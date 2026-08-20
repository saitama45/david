# External Integrations

Detail behind the summary in [CLAUDE.md](../../CLAUDE.md).

## Google Drive (file storage)

Custom Flysystem disk, not a stock Laravel driver.

- Adapter: `masbug/flysystem-google-drive-ext`
- Registered by [GoogleDriveServiceProvider](../../app/Providers/GoogleDriveServiceProvider.php) via
  `Storage::extend('google', ...)`
- Disk config: the `google` block in [config/filesystems.php](../../config/filesystems.php)
- Wrapper: [app/Services/GoogleDriveService.php](../../app/Services/GoogleDriveService.php)
- Env: `GOOGLE_DRIVE_CLIENT_ID`, `GOOGLE_DRIVE_CLIENT_SECRET`, `GOOGLE_DRIVE_REFRESH_TOKEN`,
  `GOOGLE_DRIVE_FOLDER_ID`, `GOOGLE_DRIVE_TEAM_DRIVE_ID`
- The provider contains an explicit **CA-bundle fix** — it resolves `curl.cainfo` /
  `openssl.cafile`, falling back to `public/cacert.pem`, because Guzzle's TLS verification fails on
  the Windows dev box and the Azure container otherwise. Don't remove it when refactoring.
- It also logs the resolved folder / team-drive ID at boot, so `storage/logs` shows whether config
  actually reached the adapter.
- Helper scripts at the repo root (`get_google_refresh_token.php`, `artisan_google_auth.php`) and
  [GOOGLE_DRIVE_SETUP.md](../../GOOGLE_DRIVE_SETUP.md) cover obtaining a refresh token.
  `FILESYSTEM_DISK` defaults to `local`; Drive is opt-in per call.

## Excel import / export

`maatwebsite/excel` — 22 readers in `app/Imports/`, 54 writers in `app/Exports/`.
Imports never run synchronously; see the queue flow in [Data-Flows.md](Data-Flows.md).

## PDF

`barryvdh/laravel-dompdf`, used by `PDFReportController`, `InventoryMovementReportController`,
and `OrderingCalendarController`.

## Redis

`predis/predis` is installed and `REDIS_HOST` is set, but **cache, session and queue all run on the
database driver** — do not assume Redis is the cache backend.

## API tokens

`laravel/sanctum`. Only one API surface exists: the `auth:sanctum` group in
[routes/api.php](../../routes/api.php). The main app is session-authenticated.

## Ziggy

`tightenco/ziggy` exposes named Laravel routes to JS. Registered in
[resources/js/app.js](../../resources/js/app.js) via `ZiggyVue`, so Vue can call `route('name')`.

## Mail

Local `.env` uses `MAIL_MAILER=log`, so nothing is delivered in development. Before any run that
can trigger notification mailables against real data, confirm the effective driver — a production
SMTP setting would email real recipients.

## Deployment platform

Azure App Service (Linux, nginx + php-fpm). [startup.sh](../../startup.sh) maps nginx to `public/`,
tunes php-fpm worker limits, ensures the `storage:link` symlink, runs migrations and the permission
seeder, and starts the imports worker and reconciler. [default.conf](../../default.conf) and
[web.config](../../web.config) hold the server configs.
