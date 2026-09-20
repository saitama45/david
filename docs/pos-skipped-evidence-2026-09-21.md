# POS skipped-receipt evidence

Read-only inspection of local `daviddb_test`, September 21, 2026 (Asia/Shanghai). Production has not been independently queried. No sales, BOMs, or stock movements were repaired by this investigation. The normal authorized local runner continues separately.

The recorded unresolved count was **2,683**, across all stored entities/stores/reasons, including records outside currently eligible automation stores. This is a snapshot of recorded exceptions, not proof that every POS receipt has already been discovered. Work Queue now displays the full recorded unresolved total scoped to the user's entity, authorized stores and selected store. Historical job skipped counts remain the actual count for those jobs. Retry discovery no longer truncates to 100; it retrieves every due eligible identity in internal SQL chunks of 400 and deduplicates receipts also found in source-change discovery.

## 1. Existing import lacks a verified posting audit

- Store: UP Town Center, NNUTC, branch 16, entity 1.
- Sales date: 2026-09-17. Terminal: 0001. Receipt: 27668.
- Source identity: company CBTL-25021982, site UTC, publisher CBTL-25021982-0003, record 9995.
- Existing `store_transactions.id`: **52798**.
- POS active line total and existing imported line total both equal **3,575.00**. Existing imported net line total equals **3,404.76**, also matching header gross 3,556.76 minus service charge 152.00.
- `sales_postings` has **no row** for transaction 52798.
- Nine legacy stock movement candidates mention receipt 27668 in branch 16. For example, movement **942423**, September 17, product inventory 18265, action `out`, quantity **0.001**, mentions Classic Fries with Truffle Dip / Vegetable - Parsley Flat. Movement **942424** is `out` **0.006** for inventory 18103, Mushroom - Button Fresh.

**Finding:** The audit-missing condition is proven. The warning does not prove amounts differ or that no inventory was deducted. Legacy deductions exist, but receipt-only remarks do not establish a complete terminal-specific inventory audit. Compare every imported item and expected movement before linking; do not reimport blindly. No automatic linking or extra deduction was performed.

## 2. Active line amount differs from header subtotal

- Store: UP Town Center, branch 16.
- Sales date: 2026-09-14. Terminal: 0003. Receipt: **3950**.
- Source publisher: CBTL-25021982-0003; record **9961**.
- Header `fsubtotal = 0`, `fgross = 0`, posted flag 1, void flag 0, return flag 0.
- Its single active line: product **NON01140006**, status 1, quantity **32**, unit price **395**, `ftotal_line = 12,640`.
- Difference between active lines and header subtotal: **12,640**, above the mapper's 0.02 tolerance.

**Finding:** The numerical mismatch is proven and the current mapper reproduces the warning. Incomplete replication is only one possible explanation. It could also be a POS transaction type/discount treatment needing explicit mapping. The evidence alone does not identify that business rule. Do not assume 32 sold units from this header without resolving it.

## 3. No active sold lines

- Store: Robinson's Antipolo, NNRAO, branch 20.
- Sales date: 2026-09-17. Terminal: 0006. Receipt: **37942**.
- Source publisher: CBTL-25021982-0006; record **16900**.
- Header subtotal and gross are both **0**, with posted flag 1, void flag 0, return flag 0.
- Latest detail rows: **NON01070005**, quantity 1, line total 465, status **W**; **NON01100053**, quantity 1, line total 0, status **W**.
- There are **zero** latest detail rows with status **1**.

**Finding:** The warning accurately describes the current mapping. This does not independently establish the POS business meaning of W. A confirmed zero-value non-sale could warrant an explicit exclusion rule instead of indefinite review; do not classify W as a sale or discard it without checking that rule.

## 4. Missing unit conversion for Cake Board #4.5

- Store: Uptown Mall, NNUPM, branch 30.
- Sales date: 2026-09-16. Terminal: 0037. Receipt: **19579**.
- Source publisher: CBTL-25021982-0037; record **17245**.
- Current preview reproduces `Missing or ambiguous unit conversion for 171A2A.`
- BOM for POS **NON02030002** specifies ingredient **171A2A**, `BOMUOM = GRAM`, `BOMQty = 1`.
- SAP masterfile **23671**: Cake Board #4.5, base PC, alternate PC, ratio 1:1.
- SAP masterfile **23672**: base PC, alternate PACK(100), 100 base units per pack.
- There is **no GRAM-to-PC mapping**.

**Finding:** This is a missing conversion, not ambiguous duplicate conversions. GRAM looks inconsistent with a cake board stocked by piece. Confirm the intended BOM unit/quantity; if one board is required, correct that recipe to PC rather than inventing a weight conversion. No BOM was changed.

Reproducible read-only queries: [pos-skipped-evidence-2026-09-21.sql](pos-skipped-evidence-2026-09-21.sql). The local detailed JSON snapshot is `storage/app/pos-skip-evidence.json` (not committed).
