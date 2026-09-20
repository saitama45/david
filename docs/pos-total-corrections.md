# Corroborated POS subtotal corrections

## Zero-header rule (authorized September 21, 2026)

When header subtotal, gross and net merchandise are zero but active detail totals are positive, the importer uses the active details as the authority. It imports their quantities, line amounts, and per-line net amounts after explicit saved `fvar` adjustments. A line with zero adjustment keeps its own line amount; another line's discount is not spread onto it. The Work Queue report records the zero-header override. This rule does not require agreement with the zero header.

Receipt 3950 was rechecked read-only locally: quantity 32, line and net totals 12,640 now pass mapping. Posting is still held for Missing BOM for NON01140006. Void/unposted/return receipts, inactive lines, invalid quantities, missing BOM/conversions and existing-receipt conflicts retain their normal checks. The POS source tables are not rewritten. Under the latest source-arrival-only queue rule, a deployment or BOM fix alone does not trigger reprocessing; explicitly reconcile old receipts if no new POS data arrives.

## Other subtotal mismatches and earlier investigation

The earlier investigation below predates the authorized zero-header rule above. That rule supersedes the corroboration requirement for zero-value headers only.

The importer can correct a subtotal calculation error when the active detail amounts and their explicit saved percentage adjustments corroborate the header's net merchandise amount. It uses the sum of active detail line totals as the import subtotal and preserves existing discount/net allocation rules. This is an import normalization; no POS source rows are overwritten.

Example: source subtotal 999, active line total 180, saved variance -10%, header gross minus service charge 162. The detail independently gives 162 net, so import uses subtotal 180 and net 162. The Work Queue CSV explains the original subtotal, corrected subtotal and corroborating net amounts. Posting, missing-BOM, unit-conversion and duplicate-receipt safeguards still apply.

If active details and header net disagree, or a saved adjustment is missing, the importer does not select an arbitrary amount. The review message gives header subtotal, active detail total, header net and detail net where available. Existing manually imported receipts still require reconciliation before any additional posting.

On September 21, 2026, read-only inspection of all 155 local `daviddb_test` exceptions with the former merchandise/header-mismatch reason found **zero** whose adjusted detail net agreed with header net within 0.02. Therefore this rule does not claim to resolve those 155 receipts. In particular, UTC receipt 3950 has subtotal/gross/tax/header quantity all zero while an active detail specifies 32 units at 395, totaling 12,640. Replacing the subtotal alone does not establish which source state is correct.

The local analysis snapshot is `storage/app/pos-total-analysis.json` (not committed). No source amounts or inventory balances were repaired by the investigation. Automatic correction requires corroboration; unsupported source business rules need further evidence.
