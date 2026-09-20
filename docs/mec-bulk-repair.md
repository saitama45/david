# Historical MEC repair across stores

Deploy the new command and service first. No migration is required. Run from Azure SSH:

```sh
cd /home/site/wwwroot
php artisan stock:repair-month-end-all --expect-database=daviddb
php artisan stock:repair-month-end-all --expect-database=daviddb --apply
```

The first command previews every entity/store/item with approved counts or identifiable month-end movements. It writes a JSONL report and does not change inventory. The second revalidates current data and commits eligible repairs. Use the exact production database name; a mismatch prevents execution. Optional `--entity=1` or `--branch=40` narrows the scope. Do not put the repair command in startup.sh.

Before applying, retain a current database backup and run in a quiet maintenance window. The command locks each branch and affected movement range while processing an item, so normal posting for that branch may briefly wait. It does not modify store POS replication or require disabling the desktop uploader.

## Repair behavior

- Processes the complete chronological count sequence for each product/store, keeping the established approval dates.
- At every count boundary, reconstructs the ledger balance and compares it with the approved total. Later counts absorb earlier corrections instead of allowing an old adjustment to distort present SOH.
- Recognizes existing single-item and bulk repairs. An unchanged rerun adds no new movements. If additional historical data has arrived, a rerun can produce a new, separately recorded reconciliation delta.
- Preserves original count records, approvals and stock movements. Appends corrections marked `MEC_CHAIN::<original movement ID>;`, linked to the original MEC reference. Sales-linked movement snapshots remain untouched.
- Saves before-state evidence before each item is changed. Corrections for one complete item/store sequence commit atomically or roll back together. Other successfully committed items remain committed if a later item fails; a rerun is supported.
- Refreshes the stock cache and checks its source ledger total against the planned final balance.
- Produces `applied`, `would_repair`, `unchanged`, and `review` results. A review result leaves the whole affected item/store sequence unchanged; it is not a repaired item.

## Cases requiring review

The command does not invent missing approval boundaries or ingredient unit conversions. It reports missing movement references (including old zero-difference approvals that did not create a movement), duplicate original adjustments, ambiguous base-stock mappings, mixed or invalid units, partly approved groups, and unlinked legacy adjustments. These cases need specific reconciliation before they can be repaired. Source query failures stop the run rather than masquerading as invalid data.

Exit code 0 means the run finished without review exceptions; 2 means it finished with review exceptions; 1 means an operational error or wrong database. Always inspect the printed totals and report, not just the shell exit code.

The command prints the full JSONL report path and an Excel-readable `summary.csv`, normally under `/home/site/wwwroot/storage/app/private/stock-repairs/bulk-.../`. Individual before-state evidence files are in the same run directory. Reports require the configured storage disk to support a writable local filesystem path, as in this application's existing Azure storage configuration.

To extract review rows when PHP CLI is available, read the JSONL report and select lines whose `status` equals `review`; each line includes entity, branch, product and a reason. Keep the report with the deployment record. Refresh Stock Management after the run. Current SOH includes transactions after the last approved count; it need not equal that count's physical quantity.

## Local verification

A full read-only preview on `daviddb_test` on September 20, 2026 checked 10,169 item/store sequences: 1,696 would be repaired, 2,912 were unchanged, and 5,561 required review. Of the review cases, 5,010 lacked a linked movement for at least one approved count and 482 had ambiguous/missing base-stock targets. Remaining cases included quantity/unit/schedule issues and duplicate original adjustments. No bulk repairs were applied locally or in production. These totals describe the local snapshot, not production results. Isolated regression coverage passed with 43 tests and 273 assertions, including multiple counts, prior single repairs, database guards, partial-history refusal and repeat-run idempotency.
