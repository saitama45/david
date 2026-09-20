# POS sales synchronization

`pos:sync-sales` reads finalized POS sales, maps the Sales Report by Product values, and posts through the shared receipt/BOM processor. POS work is visible as **POS Sales Sync** in Work Queue and runs on its own `pos-sales` queue. Large Excel imports use the separate `imports` queue.

## Verified mapping

The read-only SQL is `database/queries/pos_sales_import.sql`. Bind `@CompanyId`, `@SiteId`, `@FromDate`, and `@ToDate` (dates use YYYYMMDD). PHP uses the corresponding formulas in `PosReceiptMapper`.

| Import field | Source / calculation |
| --- | --- |
| Product ID | `pos_sale_product.fproductid`, resolved to `pos_masterfiles.id` for destination items |
| Product Name | `mst_product.fname` for the SQL report; application pages use the POS masterfile description |
| Date | Final receipt header `fsale_date` |
| Posted | Characters 9-12 of `fposted_date` (HHmm), **not** `fsale_time` or `fpost_flag` |
| TM# | Final receipt header `ftermid` |
| Receipt No | `ftrx_no`; the SQL also supplies the terminal-prefixed TM-OR# form |
| Qty | Line `fqty` |
| Base Qty | `fqty * fuomqty` |
| Price | Line `funitprice` |
| Discount | `ROUND(funitprice * fqty - ftotal_line, 2)`; discount master fields can be zero on a complimentary line |
| Line Total | Line `ftotal_line`; `fextprice` is a unit-price value, not the extended line total |
| Net Total | Use `ROUND(ftotal_line * (1 + line.fvar / 100), 2)` when the line has a nonzero saved variance. Otherwise allocate the receipt's net merchandise: `ROUND(ftotal_line * (header.fgross - header.fservice_charge) / header.fsubtotal, 2)`. Zero-subtotal receipts require zero net merchandise. |
| Take Out | Line `fdeliver_flag = '1'`; this can differ between lines on the same receipt |
| Branch | Final header `fsiteid`, explicitly mapped to application branch/entity |

The SQL enriches optional report labels from `mst_product`, `mst_account`, and the actual table name `mst_price_level`. Historical cancellation notes come from `pos_sale_cancel`. `mst_discount` defines master discount rules; it must not recalculate historical sales. The actual discount already follows from the recorded sale price, quantity and line total. Forcing a join to every lookup would multiply rows and/or alter history.

## Source revisions and valid sales

- Join on company + publisher + record number. `frecno` alone is not unique.
- Choose the latest source revision by `fupdated_date`, then `_sync_timestamp`. Open-bill and finalized-bill header copies can coexist. Equal-version conflicting copies are held for review.
- A bill can open on one terminal and be paid on another. The line terminal does not have to equal the final header terminal. The final header also supplies the business date; active lines must still belong to the correct branch.
- Post only finalized, nonvoid, nonreturn receipts with a positive receipt number. Include current line status `1`; exclude removed/waiting/void lines. The existence of a cancellation note does not void the whole receipt.
- Header `ftotal_qty` excludes some zero-priced choices and is not a reliable line count. Check the sum of active `ftotal_line` against header `fsubtotal`. Inconsistent replicas are reported instead of posting partial stock consumption.
- The current destination quantity columns are integers; nonpositive or fractional quantities are reported rather than truncated.

## Timing and stock behavior

1. The upstream POS replication process commits the receipt header and lines to the replicated tables.
2. Laravel scans changes every **10 seconds**, with a **5-second** settling allowance (`POS_SYNC_SETTLE_SECONDS`).
3. A change batch creates an `import_logs` entry and immediately dispatches a `PosSalesSyncJob` to the dedicated `pos-sales` queue.
4. The worker validates and commits each receipt, its items, BOM stock movements and unique source tracking together. Locks serialize competing stock changes for the same branch.
5. The SOH and stock-history pages refresh every **10 seconds** while visible. Refresh pauses during navigation and stock-edit dialogs.

Scheduled discovery uses persisted `_sync_timestamp` cursors across headers, lines and cancellation records, with a 30-second overlap. Changes to old sale dates are detected too. Empty windows advance the cursor without creating a Work Queue job. A failed batch does not advance its cursor. Explicit `--from`/`--to` runs provide date-based backfill or reconciliation. The initial scheduled window uses `POS_SYNC_LOOKBACK_DAYS` (default 7).

A purchase order does not add physical SOH. Existing receiving flows add their inventory movement when receiving is posted/approved. A finalized POS sale deducts the configured BOM quantities. POS defaults to recording consumption even when SOH is zero or insufficient, allowing a negative balance to expose late receiving or missing stock. Both POS and manual imports now record consumption even at zero or insufficient SOH. Products without BOM rows are held for review unless explicitly listed by entity and POS code in `sales_posting.non_inventory_products`. Ingredient deductions use the base-stock masterfile displayed on Stock Management, with explicit alternate-unit conversion where required.

This is **near-real-time after data arrives**, not a guarantee of instant updates at checkout. The source timestamps inspected locally show batches approximately five minutes apart. No POS replication job was found in local SQL Agent or Windows Task Scheduler; its external configuration was not changed. If the upstream feed still batches every five minutes, reducing Laravel's interval cannot remove that delay. For end-to-end updates within seconds, the POS uploader needs to commit a complete finalized receipt immediately after checkout (or provide an after-commit event/outbox), with both the scanner and dedicated worker running. Worker backlog, source inconsistencies and branch locks can extend latency.

## Deployment

Local automation is now enabled on daviddb_test for 23 validated profiles with a September 18, 2026 cutover. Other environments remain disabled by default until their app settings are configured. See docs/pos-sync-live-verification.json for the local activation results.

1. Set the `POS_SYNC_PROFILES` JSON environment value (loaded by `config/pos_sync.php`). A full example is in `config/pos_sync_profiles.example.json`; validate IDs, branch codes, owner access and cutover dates for the target environment. Only company/site, entity, branch and Work Queue owner need configuring; the report formulas no longer require user-provided mappings. The UTC example matches the inspected local branch identity, but IDs must be correct in the deployment database.
2. Apply the three additive application migrations (the third is also required for manual sales posting):

   ```powershell
   php artisan migrate --path=database/migrations/2026_09_20_000001_create_pos_sync_receipts_table.php --path=database/migrations/2026_09_20_000002_create_pos_sync_cursors_table.php --path=database/migrations/2026_09_20_000003_create_sales_posting_controls.php
   ```

3. Preview before posting:

   ```powershell
   php artisan pos:sync-sales --profile=utc --from=2026-09-19 --to=2026-09-19 --output=pos-preview.csv
   php artisan pos:sync-sales --profile=utc --from=2026-09-19 --to=2026-09-19 --apply
   ```

4. Set `POS_SYNC_ENABLED=true` and refresh cached configuration. For a standard deployment, run the Laravel scheduler and a supervised dedicated worker:

   ```powershell
   php artisan schedule:work
   php artisan queue:work database --queue=pos-sales --sleep=1 --tries=1 --timeout=3600
   ```

   The Azure/Linux `startup.sh` now starts the POS scanner and dedicated worker after the sales posting controls migration is present. The scheduler also includes a fallback worker. Deploy the code and restart existing long-lived workers. The Windows local runner is now available in scripts/pos-sync-local.ps1 and has been started and lifecycle-tested locally.

5. For a busy replica, `database/queries/pos_sales_sync_indexes.sql` supplies optional idempotent source timestamp indexes. The inspected source has over two million line records. The three incremental timestamp indexes were created on the authorized local daviddb_test replica during activation; production indexes still require the corresponding database permissions and deployment planning.

Requests and reconciliation reports are retained for recovery. Work Queue offers **Download Report** even when every receipt succeeded. Changes to already-imported sales, returns and whole-receipt cancellations are held for reconciliation; automatic reversal is not inferred from a cancellation note. Receipt identity includes entity, branch, business date, normalized terminal and receipt. Matching manual receipts with intact posting snapshots are linked without another deduction. Legacy receipts without reliable snapshots are held for reconciliation. Reused receipt numbers on different stores, terminals or business dates remain separate sales.

## Completion visibility

- Work Queue shows both Excel uploads and POS sync batches, refreshing every 10 seconds while the tab is visible. Display statuses are Queued, Processing, Completed, and Failed. Finished batches display Completed even when records were skipped; skipped counts and reports remain available. The internal review indicator does not change the stored worker status or retry behavior.
- Existing administrator and uploader access is retained. Users with `view import logs` and `view store transactions` can also see automatic POS batches for their assigned stores. Every branch in a batch must be authorized before its report is available. Listing and report downloads share this restriction and the active entity scope.
- The sales summary displays the last successful POS batch without skipped records, latest imported business date, and latest batch counts for the selected authorized stores across all dates. Counts belong to that batch; they are not a cumulative unresolved-issues counter. A queued Excel upload may not have branch associations until processing completes; its uploader can still track it in Work Queue.
- Logged-in uploaders receive in-app notifications for their own completed/failed Excel sales uploads from the past 24 hours. The app checks every 10 seconds while visible. Browser-local acknowledgements suppress repeats; these are not email or background push notifications. Work Queue remains the persistent history.
- An enabled configuration indicates scheduling is configured, not that the worker or POS replication feed is healthy. These code changes do not enable automatic syncing or run database migrations.

## Validation

- `docs/pos-sales-reconciliation.json` compares August historical imported items across eight branch selections with current source snapshots. Six branches have matching receipt/item identities; two have none in that sample. Some historical imported prices/quantities/net totals differ from current source records, so this is not a claim of universal historical identity.
- `docs/pos-sales-source-validation.json` compares the SQL and PHP calculations for UTC on September 19: **394 source lines agree on every checked numeric field, Posted, terminal, receipt and Take Out**. Of 84 receipt identities, 64 pass preview, 8 have inconsistent merchandise totals, and 12 are excluded sale states. These are read-only checks; no sales or inventory were posted.
- Dedicated integration tests replace all database connections with SQLite `:memory:` before providers boot. Run them without the project PHPUnit configuration, which otherwise points at populated local data:

   ```powershell
   php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/PosSalesSyncTest.php
   ```

Coverage includes report formulas, free items, posted time, per-line take-out, source versions, cross-terminal settlement, old-sale change discovery, separate queues, monotonic cursors, preview, atomic rollback, negative stock consumption, tenant isolation, and Excel/POS duplicate prevention.


## Store/day manual import policy

A manual receipt is allowed only when its store/business date has no successfully posted or verified POS receipts. One successful POS receipt is sufficient to protect the entire store/day, across all terminals; the batch need not have finished. A queued, failed, empty or review-only sync does not block a day unless some receipts were actually posted. The sales date, not the time the sync ran, determines the restriction.

Both paths take the same branch database lock before checking and posting. An Excel file queued before automation is checked at execution as well. A mixed file posts eligible receipts and lists blocked store/date receipts in its skipped report. If any row has an invalid date/receipt identity, the file is rejected before any receipts are posted to avoid splitting an incomplete receipt. The file is limited to 20 MB.

When manual posting happens first, later automation verifies normalized identity, item content and the linked destination/stock snapshots. Equivalent receipts are linked without consuming stock again; successful linking also protects that store/day. Mismatches and legacy receipts lacking reliable movement links remain review exceptions. No general administrator override bypasses the day rule.

Manual create uses the same posting process. Queue execution rechecks uploader permission, entity access and assigned stores. Direct sales edits are now corrections: the store/date/terminal/receipt remain fixed, a reason is required, original consumption is reversed and replacement consumption posts atomically. Original posting/items and reversal IDs are retained in sales_posting_corrections. This corrects recorded consumption; it is not a financial refund or an automatic physical restock. Legacy or tampered records cannot be corrected without first reconciling their movements.

## Recovery and reconciliation controls

Unresolved POS receipts are stored independently of the discovery cursor. While automation runs, up to 100 due exceptions per store are included in a scan after a five-minute retry delay. The sales summary displays the persistent unresolved count while Work Queue continues to display Completed as requested. Reports retain the reasons and outcomes of each attempt.

`pos:reconcile-sales` queues a date-based recheck of the last seven days at 01:00 application time through the Laravel scheduler, independent of source change timestamps. An existing active batch causes that profile to be skipped on that invocation. Explicit --from/--to selects older periods. Source deletions and unavailable historical BOM versions still require reconciliation; the application does not guess returns or recreate historical recipes. Posting snapshots preserve the actual quantities/costs used for later audited corrections.

`php artisan sales:posting-control pause` sets a shared-cache stop checked by queued jobs at each receipt boundary, including manual jobs. An already posting receipt may finish atomically. `resume` clears the cache stop; `status` reports it. `SALES_POSTING_PAUSED=true` is an additional configuration stop and must be cleared separately. Use a shared persistent cache in a multi-worker deployment. Resuming posting does not itself enable automatic discovery. POS jobs that failed while paused are picked up again from the unchanged cursor; manual jobs retain their files for recovery.

Before deployment, apply the controls migration and restart long-lived workers together. The user applied the migrations before local activation. Live SQL Server pilot posting, replay, and concurrent duplicate-window processing have now been verified; production deployment and the external POS replication schedule remain environment-specific.

Verification after the controls update: 31 isolated integration tests and 174 assertions passed, including actual CSV parsing, store/date restrictions, multiple terminals, manual-first linking, negative-stock consumption, missing BOMs, base-stock unit conversion, source and stock tampering, branch authorization, correction rollback, and cursor-independent retry discovery. PHP syntax, routes, scheduler registration, shell syntax, and the frontend production build were also checked.


## Windows local runner

Run from this project in PowerShell:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/pos-sync-local.ps1 -Mode Start
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/pos-sync-local.ps1 -Mode Status
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/pos-sync-local.ps1 -Mode Stop
```

Start performs read-only `pos:doctor` checks, then launches a hidden supervisor with a scanner and a dedicated POS queue worker. A file lock prevents duplicate supervisors. Crashed children restart; Stop stops discovery and lets the current worker batch finish. The runner is local-session infrastructure: run Start again after a PC reboot. Restart it after changing .env/configuration. It handles automatic POS jobs; the existing imports worker continues handling Excel uploads.

Logs are `storage/logs/pos-local-scanner.log`, `pos-local-scanner-error.log`, `pos-local-worker.log`, and `pos-local-worker-error.log`. Child restarts replace their current-process log files; Work Queue and retained CSV reports are the persistent job history. Runtime PIDs/locks are ignored by Git.

## Azure startup and settings

`startup.sh` starts the dedicated POS scanner and worker only after this boot's migration step, config cache and `pos:doctor` checks succeed. The scanner executes incremental discovery every ten seconds and triggers daily reconciliation after 01:00 application time. It does not require a separate Laravel scheduler for POS operation. The worker is supervised and restarts after exit. Startup preserves an existing sales-posting pause across cache clearing.

Table-to-table processing is enabled by default and store mappings ship in `config/pos_sync_profiles.json`; Azure does not need POS_SYNC_ENABLED or POS_SYNC_PROFILES settings. These names remain optional overrides, so an existing false/empty override must be removed if it should no longer apply. The mapping connects source company/site to the application entity/branch and Work Queue owner; it does not configure or replace desktop replication. Startup validates the shipped IDs and cutover dates against the target database. The optional settings example reflects local mappings, not independently verified production IDs. The database queue retry_after defaults to 3900 seconds. Non-inventory exceptions remain explicit: missing recipes otherwise skip and report individual receipts without blocking valid sales.

Run `php artisan pos:doctor` in the deployed app to verify the target environment. Deploy all application files and frontend build artifacts, then restart the App Service using its existing startup.sh startup command. Source tables must be present on the configured POS connection; the application does not replace the external store-to-replica uploader. Source index DDL is in database/queries/pos_sales_sync_indexes.sql. Azure deployment itself has not been executed from this local workspace.

Work Queue now has All Jobs, Automated POS Sync, and Manual Imports tabs, sharing branch/search filters and source-specific report access. The sales summary reports scanner freshness and unresolved receipts.

The Missing BOM tab at `/work-queue/missing-bom` is a read-only stakeholder report available with both Work Queue and store-transaction view permissions. It checks current recipe presence against imported history and current replicated sold lines, including dates before automation cutover. Select up to 31 days (defaults to the last seven), filter by authorized store or POS code/description, and export CSV. Each row is one POS code/store combination, with separate distinct source and imported receipt counts, first/last sales dates, and missing BOM or masterfile status. Counts may overlap across evidence sources. Explicit non-inventory codes are excluded. Source product names fill absent application descriptions. Conflicting source copies are flagged as incomplete coverage; unmapped stores have history-only coverage. Run the report again after corrections; it does not poll or alter inventory. A present recipe can still have ingredient or conversion issues, which remain in sync exception reports.

## Activation evidence and remaining source-data issues

- Local source mappings: 23 active stores. Inactive SGC, the TEST source, and the superseded OKA company feed were excluded. Stores absent from the replica are not guessed or mapped to another site. The BIC source explicitly maps to NNBIC/SMBIC.
- UTC pilot: 114 receipts, 3,682 linked stock movements. Replay created no additional receipts or movements.
- EVI concurrent pilot: two simultaneous workers processing the same period produced exactly 57 receipts; all linked stock snapshots remained intact.
- The full first catch-up posted 1,710 receipts. 753 receipts required review at verification time. These include missing source lines, header/line mismatches, missing recipes and unit mappings; they cannot be repaired by inventing quantities. The application retains and retries them while keeping valid posting operational.
- See docs/pos-sync-master-data-review.csv, docs/pos-sync-activation-validation.json and docs/pos-sync-live-verification.json. Reconcile these exceptions before declaring every store's SOH complete. Upstream feed freshness also varies by store; no new stock deduction can occur until the source receipt arrives.
