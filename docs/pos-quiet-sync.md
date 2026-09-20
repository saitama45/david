# POS sync without duplicate idle jobs

The automatic scanner polls every 10 seconds. Before receipt validation or job creation it requires a replication arrival newer than the persisted cursor and older than the settlement cutoff. With no new arrivals it advances the same cursor row and creates no job, ImportLog, request JSON or CSV report.

- Empty scans and verified, unchanged receipts create no job, ImportLog, request JSON or CSV report.
- New arrivals in pos_sale, pos_sale_product or pos_sale_cancel select affected receipts. Arrivals in available mst_account, mst_discount, mst_product and mst_pricelevel tables trigger a company-scoped reference recheck for eligible stores. Unchanged, non-actionable receipts still create no job.
- Automatic worker requests carry source_only; due exceptions are not appended. An old arrival inside the 30-second overlap cannot independently trigger another job.
- Old unresolved records, changed warning text, deployment and BOM fixes alone do not create automatic jobs. Missing BOM information remains visible. To reprocess old receipts after setup repairs without another POS arrival, explicitly run `php artisan pos:sync-sales --profile=utc --from=2026-09-14 --to=2026-09-21 --apply --only-changes` with the intended profile and dates.
- Work Queue shows the full recorded unresolved receipt count for the authorized stores and selected store filter. Each historical job retains its actual skipped count; these counts must not be added together because retries may refer to the same receipts.
- Automatic daily reconciliation is removed from both the persistent scanner and Laravel scheduler. `pos:reconcile-sales` remains an explicit operator command, not a scheduled trigger.

The existing exception fingerprint and cursor tables are reused; this change needs no new migration. Old queue entries and reports are retained. Already queued jobs finish normally. An empty cursor still permits the initial eligible source backfill.

Deploy with the existing `startup.sh`: it runs migrations before starting the scanner and worker. No new environment variables are needed. For a local installation, stop the local runner gracefully, run `php artisan migrate`, and start `scripts/pos-sync-local.ps1 -Mode Start` again.

This reduces database/file growth, but the scanner must still query source timestamps. It relies on the uploader assigning current arrival timestamps to committed inserts/updates. It does not guarantee instant inventory updates: desktop replication, scan duration, settlement delay and queue backlog still affect latency.
