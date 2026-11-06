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

## ghelpdesk ticket tally (outbound)

The Dashboard **Success Rate** tab pulls weekly Incoming/Closed ticket counts from ghelpdesk
(`GET {HELPDESK_API_URL}/api/integrations/david/ticket-tally`, header `X-Integration-Key` =
`HELPDESK_API_KEY`) via `app/Http/Services/HelpdeskTicketTallyClient.php` (cached 5 min; the tab's
"Refresh from Helpdesk" bypasses it). Matched by the active entity's `code`. Live counts are
read-only in the week modal and snapshotted into `success_rate_weekly_tickets`; if ghelpdesk is
unset/unreachable the tab falls back to the saved (or hand-encoded) counts. Which helpdesk items
count is controlled in ghelpdesk by `items.report_key`.

## Ziggy

`tightenco/ziggy` exposes named Laravel routes to JS. Registered in
[resources/js/app.js](../../resources/js/app.js) via `ZiggyVue`, so Vue can call `route('name')`.

## Mail

Local `.env` uses `MAIL_MAILER=log`, so nothing is delivered in development. Before any run that
can trigger notification mailables against real data, confirm the effective driver — a production
SMTP setting would email real recipients.

**Notification emails do not use `.env`** (2026-11-06). Administration > Email Notifications
(`EmailNotificationsController`, permission `manage email notifications`) saves a no-reply SMTP account in
the `settings` table (`notification_mail.*`, password encrypted with the APP_KEY: a new key means typing it
again), and `NotificationMailService::mailer()` builds an on-demand mailer from it (`Mail::build`). Driver
"log" writes the email to the log instead. The only notification so far is the **commit variance email**:
CS Mass Commits' `confirmAll()` collects the lines it committed where `quantity_commited` differs from
`quantity_ordered` (4 dp) and that quantity is not yet in `store_order_items.commit_variance_notified_qty`,
then `SendCommitVarianceEmails::dispatchAfterResponse()` mails each order's store (`CommitVarianceMail`,
view `emails.commit-variance`) at its Store Branches `head_chef`, `director_operations`, `vp_operations`
and `aom` (valid addresses only, comma / semicolon separated) and stamps the quantity. After the response,
so no queue worker is needed and a mail failure is only logged. `Mail::fake()` cannot fake `Mail::build`:
tests swap `mailer()` for the `array` mailer (`EmailNotificationsTest`).

## Deployment platform

Azure App Service (Linux, nginx + php-fpm). [startup.sh](../../startup.sh) maps nginx to `public/`,
tunes php-fpm worker limits, ensures the `storage:link` symlink, runs migrations and the permission
seeder, and starts the imports worker and reconciler. [default.conf](../../default.conf) and
[web.config](../../web.config) hold the server configs.

The same build is also sent by FTP to **site10** (MonsterASP, Windows / IIS), the `deploy_ftp` job of
[main.yaml](../../.github/workflows/main.yaml). There a PHP file a live request is reading cannot be
overwritten, and the server answers `550 Could not access file: driver error: calling GetHandle: failure`
(routes/web.php, 2026-11-06, after 14 green runs). The upload is therefore tried three times (30 s and
90 s apart); a retry resends everything changed since the last complete run, since FTP-Deploy-Action saves
its sync-state file only at the end.
