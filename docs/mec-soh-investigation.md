# MEC-68-40 / Sugar Cookies, medium

Investigated locally in `daviddb_test`: product 23835 / 199D9A, branch 40, count item 54366, original movement 904190.

## Existing business rules verified

1. `/month-end-count` downloads a template, stages uploaded quantities and permits review while status is `uploaded`. Upload alone does not change inventory. Blank quantity rows are skipped; explicit zero is a count.
2. Total quantity is `bulk_qty + loose_qty / config` when conversion is positive; otherwise the existing formula adds bulk and loose. Submission recalculates this total and moves items to `pending_level1_approval`.
3. `/month-end-count-approvals` allows authorized edits while pending Level 1 and recalculates the total. Level 1 approval changes status only.
4. Final Level 2 approval posts the approved total, grouping the same item code into its base-stock masterfile. The existing implementation uses the approval date for the stock movement. The scheduled count date identifies the count period and upload window; it was not used as the stock transaction date.
5. Stock Management list/history use the movement ledger. The MEC template and old approval implementation instead used `product_inventory_stocks.quantity`, a separate balance that was not aligned with that ledger.

## Root cause and arithmetic

The approved count is 8 PC. The uploaded template snapshot says 57. The original final approval calculated 8 - 57 = -49, and posted that movement on September 5. The actual ledger before that movement was 4, so history correctly summed 4 - 49 = -45. The defect was the approval baseline, not a negative-number formatting error.

Preserving the approval-date rule, the correct adjustment is 8 - 4 = +4. Subsequent recorded transactions are +7 on September 7, +5 on September 16, and -5 total sales on September 18-19. Therefore the corrected balance is 8 at approval and 15 currently, based on recorded movements. The current balance is not expected to remain 8 after subsequent activity.

An earlier investigation estimate of 18 assumed backdating the count to August 31. That would change the established effective-date rule and was not applied. The final fix preserves September 5.

## Fix

- Both Level 2 routes now share the same posting implementation.
- Adjustment baseline comes from the entity/branch/product movement ledger; the stock cache is refreshed afterward.
- The template's Current SOH now also comes from the ledger. Historical uploaded snapshot values remain unchanged for audit.
- Branch and count rows are locked during approval; repeated approval cannot post twice.
- Missing/ambiguous base stock targets and inconsistent quantity units fail approval instead of silently marking items approved without reliable movements. An older count cannot overwrite a later approved count.
- Count quantities, approval permission requirements, status transitions and approval-date posting are preserved.

## Targeted local repair

The original -49 movement and approved count were retained unchanged. New movement 1046749 reverses it with +49 on September 5. New movement 1046750 records the corrected +4 on the same date, linked to MEC-68-40. Both movements contain `MEC_REPAIR::904190;` for traceability. The original negative row remains visible as historical evidence, with its reversal and replacement alongside it.

Verified local ledger at September 5 = 8; current ledger = 15; stock cache = 15. Evidence: `mec-68-40-23835-repair.json`. The command also saves the before-state and calculation under `storage/app/.../stock-repairs/` using the configured storage disk. No production data was changed. No migration is needed.

Deploying code prevents the stale-baseline error on future approvals but does not automatically repair existing production movements. On the target environment, inspect this read-only preview first:

```sh
php artisan stock:repair-month-end 904190
```

After verifying that the preview identifies this same approved count, product, branch and original movement, the explicit repair is:

```sh
php artisan stock:repair-month-end 904190 --apply
```

The repair is transactional, preserves later activity (including same-day movements), leaves sales movement snapshots intact, and is idempotent. It refuses a repair when a later approved count exists or the target/unit/reference is ambiguous. It repairs only the specified movement, not all items in the count.

## Remaining boundaries

Other historical MEC adjustments may have used the same stale balance; they require separate previews and reconciliation, not a blanket numeric update. Historical sales or receipts entered later with dates before an approved count can change historical ledger totals; such late entries need count reconciliation. This change does not introduce a new closed-period policy or automatically rebase every approved count. Current SOH is based on transactions actually recorded; missing/skipped sales still require resolution.
