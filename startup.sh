#!/bin/bash

# 1. Map the Nginx root to /public
cp /home/site/wwwroot/default.conf /etc/nginx/sites-available/default
service nginx reload

# 2. Fix permissions (Fast)
chmod -R 775 /home/site/wwwroot/storage /home/site/wwwroot/bootstrap/cache

# 3. Increase PHP-FPM worker limits correctly (Fixes the 502 error)
sed -i 's/^pm.max_children = .*/pm.max_children = 20/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^pm.start_servers = .*/pm.start_servers = 4/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/g' /usr/local/etc/php-fpm.d/www.conf
sed -i 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 6/g' /usr/local/etc/php-fpm.d/www.conf

# 4. Clear the cache IMMEDIATELY (Fixes the 500 error)
# We do this before the background tasks to ensure the very first request is clean.
php /home/site/wwwroot/artisan config:clear
php /home/site/wwwroot/artisan cache:clear

# 5. Run remaining tasks in background
(
  echo "⏳ Running migrations..."
  php /home/site/wwwroot/artisan migrate --force
  
  echo "⏳ Rebuilding optimization cache..."
  php /home/site/wwwroot/artisan config:cache
  php /home/site/wwwroot/artisan route:cache
  php /home/site/wwwroot/artisan view:cache
  echo "✅ Background tasks finished!"
) &

# 6. Start the Laravel Scheduler loop
(while true; do
  php /home/site/wwwroot/artisan schedule:run >> /home/site/wwwroot/storage/logs/scheduler.log 2>&1
  sleep 60
done) &

echo "🚀 Startup script finished! Handing over to php-fpm."