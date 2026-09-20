#!/bin/bash

# 1. Map the Nginx root to /public
cp /home/site/wwwroot/default.conf /etc/nginx/sites-available/default
service nginx reload

# 2. Fix permissions (Fast)
mkdir -p /home/site/wwwroot/storage/framework/cache
mkdir -p /home/site/wwwroot/storage/framework/sessions
mkdir -p /home/site/wwwroot/storage/framework/views
chmod -R 775 /home/site/wwwroot/storage /home/site/wwwroot/bootstrap/cache

# Ensure the public storage symlink exists (for uploaded files like entity logos).
# Guarded so it is harmless if the link already exists.
if [ ! -e /home/site/wwwroot/public/storage ]; then
  php /home/site/wwwroot/artisan storage:link 2>/dev/null || true
fi

# 3. Increase PHP-FPM worker limits correctly (Fixes the 502/504 error)
sed -i 's/^pm.max_children = .*/pm.max_children = 50/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^pm.start_servers = .*/pm.start_servers = 10/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 5/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 15/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^;pm.max_requests = .*/pm.max_requests = 500/g' /usr/local/etc/php-fpm.d/www.conf

# POS workers start only after this boot's migrations/configuration checks succeed.
pos_ready_file=/home/site/wwwroot/storage/framework/pos-runtime-ready
rm -f "$pos_ready_file"
pos_was_paused=0
if php /home/site/wwwroot/artisan sales:posting-control status 2>/dev/null | grep -q '^Sales posting paused'; then
  pos_was_paused=1
fi

# 4. Clear the cache IMMEDIATELY (Fixes the 500 error)
# We do this before the background tasks to ensure the very first request is clean.
if command -v composer >/dev/null 2>&1; then
  composer dump-autoload -o
else
  echo "Composer not found at runtime; using deployed vendor/autoload.php."
fi
php /home/site/wwwroot/artisan optimize:clear
php /home/site/wwwroot/artisan config:clear
php /home/site/wwwroot/artisan cache:clear
if [ "$pos_was_paused" = "1" ]; then
  php /home/site/wwwroot/artisan sales:posting-control pause
fi
php /home/site/wwwroot/artisan queue:restart

# 5. Run remaining tasks in background
(
  echo "🩹 Reconciling migration log (prevents 'object already exists' on imported schemas)..."
  php /home/site/wwwroot/artisan migrations:reconcile

  echo "⏳ Running migrations..."
  php /home/site/wwwroot/artisan migrate --force || exit 1

  echo "🔑 Syncing roles & permissions (idempotent)..."
  php /home/site/wwwroot/artisan db:seed --class=RolesAndPermissionSeeder --force

  echo "Reconciling import queue..."
  php /home/site/wwwroot/artisan imports:reconcile --apply
  
  echo "⏳ Rebuilding optimization cache..."
  php /home/site/wwwroot/artisan config:cache || exit 1
  php /home/site/wwwroot/artisan route:cache
  php /home/site/wwwroot/artisan view:cache
  # Read-only application readiness check, not a store replication command.
  # Missing BOMs are handled per receipt by the worker and do not block startup.
  if php /home/site/wwwroot/artisan pos:doctor; then
    touch "$pos_ready_file"
  else
    echo "POS sync not started: resolve pos:doctor errors and restart the app."
  fi
  echo "✅ Background tasks finished!"
) &

# 6. Reconcile import state periodically so orphaned processing rows cannot block the queue
(
  exec 8>/home/site/wwwroot/storage/framework/imports-reconciler.lock
  if ! flock -n 8; then
    echo "[$(date)] Imports reconciler is already running for this instance."
    exit 0
  fi

  until php /home/site/wwwroot/artisan migrate:status | grep "2026_06_08_000003_add_runtime_columns_to_import_logs_table" | grep -q "Ran"; do
    echo "[$(date)] Waiting for import runtime migration before starting reconciler..."
    sleep 5
  done

  while true; do
    php /home/site/wwwroot/artisan imports:reconcile --apply
    sleep 60
  done
) >> /home/site/wwwroot/storage/logs/import-reconciler.log 2>&1 &

# 7. Start queue worker for the 'imports' queue (auto-restarts if it dies)
(
  exec 9>/home/site/wwwroot/storage/framework/imports-worker.lock
  if ! flock -n 9; then
    echo "[$(date)] Imports queue worker is already running for this instance."
    exit 0
  fi

  until php /home/site/wwwroot/artisan migrate:status | grep "2026_06_08_000003_add_runtime_columns_to_import_logs_table" | grep -q "Ran"; do
    echo "[$(date)] Waiting for import runtime migration before starting queue worker..."
    sleep 5
  done

  while true; do
    echo "[$(date)] Starting queue worker..."
    php /home/site/wwwroot/artisan queue:work database \
      --queue=imports \
      --sleep=5 \
      --tries=1 \
      --timeout=3600 \
      --max-jobs=1 \
      --max-time=3500
    echo "[$(date)] Queue worker exited, restarting in 10 seconds..."
    sleep 10
  done
) >> /home/site/wwwroot/storage/logs/queue-worker.log 2>&1 &

# Dedicated POS scanner and worker. Scanner includes daily reconciliation, so
# this deployment does not depend on a separate scheduler process for POS tasks.
(
  exec 10>/home/site/wwwroot/storage/framework/pos-sales-scanner.lock
  flock -n 10 || exit 0
  until [ -f "$pos_ready_file" ]; do sleep 5; done
  while true; do
    php /home/site/wwwroot/artisan pos:scan-loop
    sleep 10
  done
) >> /home/site/wwwroot/storage/logs/pos-sales-scanner.log 2>&1 &

(
  exec 11>/home/site/wwwroot/storage/framework/pos-sales-worker.lock
  flock -n 11 || exit 0
  until [ -f "$pos_ready_file" ]; do sleep 5; done
  while true; do
    php /home/site/wwwroot/artisan queue:work database --queue=pos-sales --sleep=1 --tries=1 --timeout=3600 --max-time=3500
    sleep 2
  done
) >> /home/site/wwwroot/storage/logs/pos-sales-worker.log 2>&1 &

echo "🚀 Startup script finished! Handing over to php-fpm."
