# Missing BOM source investigation

Read-only inspection of `daviddb_test` on 2026-09-20. No recipes or inventory records were changed.

## What the seven tables can establish

| Table | Relevant evidence | Recipe limitations |
| --- | --- | --- |
| pos_sale | Company, store, terminal, receipt, date, posting/void/return flags | No ingredient list or per-serving quantities |
| pos_sale_product | Sold product, quantity, sales UOM/multiplier, prices, choice type/group/index | No explicit ingredient item, recipe quantity or stock-item mapping |
| mst_product | Product name, product type, UOM, parent and variant fields | No structured ingredient list or component consumption quantity |
| pos_sale_cancel | Cancelled receipt-line reference and reason | Does not describe ingredients |
| mst_discount | Discount rules | Does not describe ingredients |
| mst_price_level | Price-level names and discount settings | Does not describe ingredients; this is the actual local table name |
| mst_account | Cashier/customer/account identities | Does not describe ingredients |

These tables can identify sold codes that have no corresponding recipe in the current entity's `pos_masterfiles_bom`. They cannot, by themselves, reliably recreate those recipes. The report inspects all current sold lines, independently of the first error recorded by a sync job.

## Data examined

- All column names in each of the seven tables were inspected, plus distributions of product parent/type/UOM and sales choice fields.
- Of 10,294 replicated product rows (including source copies), 10,287 had an empty `fparentid`. The seven populated rows describe named Viennese drink variants such as small/regular/large, with no consumption quantities. A parent reference is not sufficient evidence of a recipe.
- `NON03020027` (Spanish Latte, Hot) has type `K`, UOM `PC`, and blank memo and parent fields. Nothing in this product row specifies coffee or milk quantities.
- `NON03070005` (Signature Set) and `NON03070006` (Family Feast) have type `C`, UOM `PC`, and blank memo and parent fields. Type-code meanings have not been independently confirmed against vendor documentation.
- Choice type, group and index fields are populated across sales lines. They are useful evidence for investigating meal selections, but are not an authoritative mapping from a sold product to stock ingredients and quantities. Neither choice types nor product types are treated as automatic non-inventory classifications.
- Recipe-related table names found locally were `pos_masterfiles_bom`, `menu_ingredients`, and `wip_ingredients`. These are outside the seven replicated tables. Their existence does not establish that a missing POS recipe can be recovered or that recipes are interchangeable.

## Required recipe data

Obtain the authoritative POS recipe/component export or approved kitchen costing recipes, including POS code, ingredient stock code, consumption quantity, UOM/conversion, yield, and effective dates. For meals and modifiers, also obtain the parent/choice relationships and which level owns stock consumption. Counting both a parent recipe and its sold child selections can deduct stock twice.

Use the distinct-code CSV as the stakeholder checklist. Confirm mappings within the entity; do not copy a similar name's recipe or classify a zero-priced line as non-inventory without confirmation. After recipes are approved and loaded, rerun the report and let the existing sync retry held receipts. Validate ingredient/UOM checks separately: presence of any BOM row alone does not establish recipe correctness.

## Work Queue views

Missing BOM now has `By Store` and `Distinct POS Codes` views. The distinct view contains only codes with no BOM rows in the current entity, including codes whose POS masterfile is absent. Codes with a recipe but a missing/ambiguous masterfile remain in By Store only. Filters and permission scope apply to both views and CSV exports. Source and imported receipt counts can overlap and should not be added together.
