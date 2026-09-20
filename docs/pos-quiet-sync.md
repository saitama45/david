# POS sync without duplicate idle jobs

The automatic scanner still polls every 10 seconds, using the existing source timestamp overlap and settlement delay. Before creating a job it reads candidate receipts and runs the same read-only receipt validation used by preview.

- Empty scans and verified, unchanged receipts create no job, ImportLog, request JSON or CSV report.
- New receipts, changed source data, new or changed issues, and resolved issues still enter Work Queue. The worker re-reads source data and revalidates inside the existing posting transactions.
- An unresolved receipt with the same fingerprint and reason updates its existing retry time instead of creating another job/report. It remains visible in its original report and Missing BOM report.
- Missing BOMs are rechecked on the existing five-minute retry schedule (up to 100 due receipts per store per scan). Fixing master data requires no source re-upload. Large backlogs and worker load can extend this interval.
- Daily reconciliation uses the same quiet check, including destination integrity validation. Explicit `pos:sync-sales --profile=... --apply` remains available for a deliberate full audit; add `--only-changes` to suppress idle jobs.

One nullable fingerprint column is added to `pos_sync_exceptions`; no per-poll tracking rows are added. Existing issues without a fingerprint can produce one further report to establish that fingerprint. Old queue entries and reports are retained. Already queued jobs finish normally.

Deploy with the existing `startup.sh`: it runs migrations before starting the scanner and worker. No new environment variables are needed. For a local installation, stop the local runner gracefully, run `php artisan migrate`, and start `scripts/pos-sync-local.ps1 -Mode Start` again.

This reduces database/file growth, but the scanner must still query source data and validate due issues. It does not guarantee instant inventory updates: desktop replication, scan duration, settlement delay and queue backlog still affect latency.
