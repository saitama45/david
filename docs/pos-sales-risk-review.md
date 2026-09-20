# POS sales activation risk review

Historical audit reviewed 2026-09-20 before enabling automatic posting. The subsequent implemented safeguards and the user-selected store/day policy are documented in pos-sales-sync.md; findings below describe the pre-fix state. This is a code and isolated-test review, not a claim that these problems have already affected daviddb_test. No application business data was changed and automation was not enabled.

## Evidence

Seven temporary audit tests ran with every database connection replaced by SQLite :memory: before application providers booted, using --no-configuration. All seven checks passed (26 assertions): six reproduced gaps and one verified protection. The temporary test file was removed after the investigation. SQL Server concurrent-worker behavior was not stress-tested.

| Check | Observed result |
| --- | --- |
| Excel terminal 41 / receipt 41-00000042 followed by the same POS sale on terminal 0041 | Two transactions and twice the ingredient consumption. Terminal aliases exist, but receipt-prefix aliases do not cover all equivalent terminal representations. |
| Excel sale at zero SOH followed by matching POS sale | Excel created a transaction without an out movement. POS reported the existing receipt for review and did not repair consumption. |
| Excel contains receipt 42 on two business dates | One transaction with combined quantities on the first date. Excel groups by branch and receipt only, omitting date and terminal. |
| Product has no BOM rows | POS posted and tracked the sale with no ingredient consumption. |
| Destination item quantity changed after POS posting | Next sync returned unchanged because the saved source hash still matched; destination content is not compared. |
| Excel quantity -2 with positive SOH and BOM quantity 0.5 | An out movement of -1 was created, which increases calculated SOH. |
| BOM references a missing SAP ingredient | Receipt was correctly held for review and no transaction was created. |

Relevant code: app/Support/StoreReceiptIdentity.php; app/Imports/StoreTransactionImport.php; app/Services/StoreTransactionReceiptProcessor.php; app/Services/PosSalesSync.php.

## Additional code findings

1. Manual upload accepts a file and creates a job; the worker resolves file branch codes inside the active entity but does not check the uploader's assigned stores. Manual create/update requests require authentication and top-level fields, without detailed item or assigned-branch validation. Route permissions exist, but do not replace record/branch authorization.
2. Manual create/update in app/Http/Services/StoreTransactionService.php writes transaction headers/items outside the shared receipt/inventory processor. Updates replace item rows without adjusting original ingredient movements. Create also explicitly adds one day to order_date. These endpoints need attention along with Excel upload.
3. The POS source tracking table has a unique source_key. Repository migrations do not define a canonical cross-channel receipt uniqueness constraint on store_transactions. Branch locking protects the shared importer paths, but direct create/update bypasses that protocol. Inspect live legacy duplicates and receipt-number reset rules before adding any unique index.
4. POS changes, returns, voids, and missing destinations are held for review rather than automatically reversed. That avoids speculative double posting, but does not resolve the original stock balance.
5. A POS batch advances its cursor after finishing even when some receipts need review. Fixing a BOM/masterfile alone does not update the source timestamp, so a skipped receipt may never be rediscovered after the overlap window. Exceptions need durable tracking and deliberate retries independent of the discovery cursor.
6. Sync discovery depends on _sync_timestamp. Late commits with old timestamps, timestamp resets, hard deletions, and an incomplete upstream feed require reconciliation independent of the incremental scan. The five-second allowance is not proof of atomic source replication.
7. Excel wraps the entire workbook in one transaction. Branch locks can therefore remain held until the whole file finishes, delaying POS jobs. Mixed-branch workloads increase contention; concurrency on SQL Server needs a separate test.
8. BOM quantities and cost are read when posting, not from a sale-time recipe snapshot. Backfills can therefore use today's BOM. Matching an alternate UOM also does not itself convert quantities into the stock ledger's unit. Recipe/unit semantics need explicit validation.
9. Inventory movements carry descriptive receipt remarks but the shared processor does not write a structured sale/item source link. Safe corrections require exact links to original posted movements, including recipe quantities and units.
10. POS_SYNC_ENABLED gates scheduled discovery, not execution of already queued jobs or explicit --apply requests. Turning off that flag is not a complete emergency posting stop.
11. The summary's enabled flag and latest sale date do not prove complete coverage. A later batch can finish successfully while older review receipts remain unresolved. Keep Completed as requested, but show independent unresolved counts and feed/worker freshness.

## Recommended controls before activation

1. Introduce a server-enforced per-branch mode and cutover boundary: Manual, Automatic, Paused, or approved Manual Fallback. Automatic branches reject ordinary manual uploads and creates for the automated period. Enforce at upload, queue execution, and final posting, so previously queued files and direct requests cannot bypass a mode change. Use the branch lock for the mode check and posting. Define what happens to an already-running batch when pausing.
2. Keep fallback restricted to a permission, authorized branch, explicit business-date range, reason, approver, and expiry. Pause automatic posting for the overlapping scope, preview the file, and reconcile fallback receipts before resuming. Non-automated branches can continue manual operation. Do not use a globally hidden button or an unrestricted administrator bypass as the protection.
3. Use one receipt identity and posting ledger for every entry path. Canonicalize terminal/receipt representation and require entity, branch, business date, terminal, and receipt. Reject ambiguous/missing identity. Confirm receipt reset rules; retain the stronger POS company/site/publisher/record identity where available. Register identities atomically with database uniqueness after legacy reconciliation. File hashes are useful supplementary checks, not receipt deduplication.
4. Reconcile overlaps by both contents and posted inventory: same receipt and equivalent items plus complete movements can be linked as already posted; mismatches or absent movements require review. Never treat receipt existence alone as proof of correct consumption, and never blindly repost an existing receipt.
5. Use the same quantity, unit, BOM, stock-consumption, and authorization rules across automatic and allowed fallback paths. Reject negative/zero/fractional quantities where unsupported. Classify products explicitly as ingredient-consuming or legitimately non-inventory; require a valid BOM for the former. A finalized sale should record actual consumption even if receiving is late, with negative-stock monitoring.
6. Make posted sales immutable through generic edit endpoints. Corrections use audited reversal-and-replacement linked to original ledger movements. Do not infer physical restocking from a financial cancellation/refund; support the actual business disposition.
7. Persist receipt-level exceptions with retry state, age, reason and resolution history. Retry after master-data fixes and transient replication delays. Reconcile receipt/item counts, quantities, net totals and ingredient movements by branch/date/terminal on a regular schedule.
8. Before cutover, reconcile historical Excel data and existing stock movements, choose the initial import window, resolve pending uploads, validate profiles, and test concurrency/crash recovery on a disposable SQL Server database. Start with one branch and reconcile its first batches before expanding.

These recommendations are proposed work, not implemented controls. Existing protections include entity scoping, shared-import branch locks, atomic POS receipt/item/movement/tracking writes, unique POS source tracking, source total/version validation, and review holds for source changes. They are useful but insufficient for unrestricted mixed manual/automatic posting.
