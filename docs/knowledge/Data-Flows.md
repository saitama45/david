# Request and Event Flows

Detail behind the summary in [CLAUDE.md](../../CLAUDE.md).

## HTTP request lifecycle

1. **Route** — [routes/web.php](../../routes/web.php), guarded by `auth` and
   `check.persmission:<permission>` (374 such guards).
2. **Entity binding** — [SetActiveEntity](../../app/Http/Middleware/SetActiveEntity.php) resolves
   session `active_entity_id` → `users.last_entity_id` → first accessible entity, re-validating the
   choice against the user's accessible entities, then binds
   [EntityContext](../../app/Support/EntityContext.php) (a singleton, request-scoped).
3. **Validation** — inline `$request->validate([...])` in the controller for most modules.
4. **Business logic** — `app/Http/Services/*`.
5. **Persistence** — Eloquent. `BelongsToEntity` adds `EntityScope` to every query and stamps
   `entity_id` on create.
6. **Shared props** — [HandleInertiaRequests](../../app/Http/Middleware/HandleInertiaRequests.php)
   attaches `auth` (user, roles, permissions, accessible entities, `activeEntity`), `flash`,
   `sidebarSettings`, `wastageApprovalConfig`, and `notifications`.
7. **Render** — Inertia page under `resources/js/Pages/<Module>/`.

### Notification payload (performance-sensitive)

The `notifications` prop runs roughly a dozen count queries for pending approvals across every
subsystem. It is cached per user for **one minute** under `user_notifications_v7_<id>`.

- Bump the version in that cache key whenever the payload's **shape** changes, or users keep the
  stale structure for up to a minute.
- Counts are deliberately limited to the **current calendar month**.
- Several counts wrap a distinct subquery (`DB::table($q->select(...)->distinct(), 'sub')->count()`)
  because SQL Server cannot count multi-column distincts directly.

## Import / queue flow

```
Upload → controller → ImportQueueService → import_logs row (pending)
       → StartImportJob on the `imports` queue
       → SAPMasterfileImportJob / StoreTransactionImportJob
       → import_logs updated (processing → completed | failed)
```

The worker is started by [startup.sh](../../startup.sh):
`queue:work database --queue=imports --tries=1 --timeout=3600 --max-jobs=1 --max-time=3500`.

Because a single stuck row blocks the queue, a reconciler runs **every 60 seconds** in production
(`imports:reconcile --apply`), and four more commands exist for manual repair. All of them
**dry-run by default**:

| Command | Purpose |
|---|---|
| `imports:reconcile [--apply] [--stale-minutes=]` | Update logs, dispatch the next pending import |
| `imports:doctor` | Diagnose queue/log state |
| `imports:requeue-stuck [--apply] [--include-failed] [--stale-minutes=60]` | Requeue stalled processing logs |
| `imports:recover-incomplete-jobs [--apply]` | Reset recoverable logs, clean stale queue rows |
| `imports:repair-failed-logs [--apply]` | Repair failed log rows |

**Queued jobs have no session**, so no entity is bound. When `EntityContext` is empty, `EntityScope`
does **not** filter — an entity-aware job must set it explicitly, ideally
`EntityContext::runAs($id, fn () => ...)`.

## Approval flow

Orders, wastage, month-end counts, interco and cash pull-out share the same shape: a status column
advances through levels (`pending` → `approved_lvl1` → `approved` and variants), gated per level by a
Spatie permission and the approver's store assignment, and written by that module's own controller.
There is no generic matrix (`WorkflowService` / `ApprovalMatrixService` are dead code). Which levels
exist can be a setting — read the relevant settings service before assuming a two-level flow.

**Wastage stock check.** Both wastage approval levels run `WastageService::approvalStockProblem()`
before writing. `wastage.approval_allow_negative_stock` (`WastageApprovalSettingsService`, `/wastage-settings`,
default **on**) decides what a shortfall does: on, it lands in `negative` and is deducted below zero
(a missing stock row is opened at 0); off, it is a blocking error, as before 2026-10-03. A unit that
does not convert to a stock row always blocks. A shortfall passes only when the approve request carries
`confirm_negative_stock`, which the Show page sends from its "Approve with Negative Stock?" dialog,
so stock that drops after the page loaded is refused again rather than approved silently. The Show
pages get the preview as `negative_stock_items` from `getApprovalStockCheck()`.

**Mass Orders Approval decides an order once.** `MassOrdersApprovalController::approve()` and
`reject()` run `decisionProblem()` under a row lock and refuse unless the order is `pending`, or
`approved` with nothing committed or received: no `receiving_finalized_at`, no receiving row in
`received` / `approved`, status not `partial_committed` / `committed` / `received` / `incomplete` /
`rejected`. `show()` passes the same reason to the page as `decisionProblem`, which then hides Approve
and Reject. Until 2026-10-05 neither action looked at the status, and the page lists every order under
its ALL tab: approving a received order set it back to `approved` (or `committed` for CPO), and on a
CPO order `updateOrCreate(['status' => 'pending'])` found no pending row left and added a second,
date-less row per item that a delivery locked by Final Receive All could never receive. 46 production
orders carried a wrong status from this; `database/queries/repair_mass_orders_reapproved_after_receiving.sql`
puts them back. A CPO order is committed by its approval, so it cannot be approved a second time.

**Receiving: delivery evidence and Zero All.** Recording a quantity on `/orders-receiving/show`
(receive, edit, Add Unlisted Item) needs a delivery receipt and an image
(`OrderReceivingService::deliveryEvidenceProblem()`). Zero All (`PUT /orders-receiving/zero-all/{id}`,
`zeroUnconfirmedReceipts()`) is the exception, for a delivery that did not arrive: under a row lock it
sets every `pending` / `received` row to quantity 0, remarks `Unserved`, status `received`, stamped
with the receiver and the Manila time, and leaves `approved` rows alone. It posts nothing and does not
lock. Confirm Receive All and Final Receive All then run `postingEvidenceProblem()`: no receipt or
image is asked for while every receiving row of the order is 0, posted or not, and one row above 0
brings the requirement back (an untouched placeholder holds its committed quantity, so it counts).
The page mirrors this with `nothingReceived`.

## Wastage item search

`/wastage/create` and `/wastage/edit` search through `WastageController::getAvailableItems()`
(`wastage.items.search`). A term matches SAP items by ItemCode / ItemDescription and, since
2026-10-05, POS products by `pos_masterfiles_bom.POSCode` / `POSDescription`. **A product is never a
result, unless it is a Sub-Prep** (stock lives on SAP items; see [Sub-Prep wastage](#sub-prep-wastage)):
`recipeIngredientRows()` turns each matched product (10 at most;
`more_products` flags the rest) into one SAP row per ingredient. That row is the item's BOM UOM row,
or its stock-unit row when SAP gives the item no such unit, and it carries `product {code,
description}`, `recipe_qty` (that product's BOMQty lines for the item + unit, summed) and
`recipe_uom`. Direct item rows come first, one per unit as before, with no `product` key.

`ItemAutoComplete.vue` (shared with Interco, whose rows never carry `product`) groups rows by
`product` under an "Ingredients of" heading. "Add all" emits `items-selected`; the page adds the
lines its cart does not hold yet. **A row with stock <= 0 is listed but cannot be picked**
(`canPick()`: click, keyboard and "Add all" skip it; the button then reads "Add N in stock"). That
block is in the search box only: `store` / `update` do not check stock, which is still checked at
approval (`approvalStockProblem()`). Quantity stays 1: the recipe quantity is a guide only
(`recipe_note`, client-side, never posted). The search row on both pages is always stacked — their
scoped `.grid-cols-1` rule outranks `md:grid-cols-12` — so do not add a `col-span` child to it.

## Sub-Prep wastage

A POS item whose Category is `Sub-Prep` (`POSMasterfile::isSubPrep()`, case-insensitive) is wasted
**as itself** (2026-10-05). It is not a SAP item and holds no stock, so three places read its BOM
through `App\Support\SubPrepRecipe::ingredients()` — one entry per raw material + BOM unit, with the
stock row and factor from `ItemStockUnit`. **The BOM Qty is per one unit of the POS item, exactly as
a sale reads it** (`StoreTransactionReceiptProcessor`): 5 ml wasted x 100 Gm BOM Qty = 500 Gm.

- **The line.** `wastages.pos_masterfile_id` is set and `sap_masterfile_id` is NULL
  (`WastageRequest` allows one or the other, and only an active Sub-Prep with a UOM). Quantity is in
  `pos_masterfiles.UOM`; `cost` is `SubPrepRecipe::unitCost()` - the Supplier Items cost under the
  POS Code and that UOM (`SupplierUnitCost::find()`, so another priced unit converts through SAP;
  0 when unpriced; **not the SRP**, as it was for a few hours on 2026-10-05) - set by `WastageService`
  whatever the form sent (an existing line keeps the cost it was filed at). Pages still read `sap_masterfile` in their payloads:
  `Wastage::lineItem()` fills it with the POS item under the same keys (`sub_prep: true`).
- **Search.** A Sub-Prep group is the Sub-Prep row alone (`sub_prep`, `pos_masterfile_id`,
  `cost_per_quantity` = that Supplier Items cost); its raw materials are not listed. Its `stock` is how many units its raw materials cover (the minimum of
  on-hand / BOM Qty), and `blocked_reason` says why it cannot be added: inactive, no UOM, a BOM unit
  that does not convert, or a raw material at stock <= 0 (named in the reason). A raw material is
  wasted on its own by searching it directly.
- **Approval.** `WastageService::buildFinalApprovalDeductions()` turns a Sub-Prep line into deductions
  on each raw material's stock row (quantity x BOM Qty x factor; ledger cost = BOM Qty x BOM Unit
  Cost), so the negative-stock check and confirmation work per raw material. No BOM, or a unit that
  does not convert, is a blocking error. `Scrap` deducts nothing, as for a SAP line.
- **Reports.** `InventoryMovementService` step 3.5 adds approved Sub-Prep wastage to `wastage` per
  `(bom.ItemCode, bom.BOMUOM)`, and `InventoryMovementReportController::applyMovementFilter()` lists
  a raw material whose only movement is one. Each row also carries `wastage_sub_preps` (code,
  description, uom, wasted_qty, quantity in the display unit) - the part of `wastage_qty` that is a
  Sub-Prep - which the page shows as a "Sub-Prep" line under the figure, the Excel export as a
  shaded cell with a note, and the PDF as a small line. The Qty / Cost Variance report and the MEC template's
  Current SOH follow, since they read the same service. The Wastage Report and both wastage exports
  show the line by its POS code, name and UOM (`leftJoin pos_masterfiles`, grouped by both ids).

Both readings use the BOM as it is now, as sales do: editing a BOM changes past figures in the report.

## Inventory Movement Report drill-down

Nine columns of `/reports/inventory-movement` are clickable (2026-10-05): Ordered, Committed,
Received, Beg Bal Qty, Sales Qty, Wastage Qty, Supplies Used, Inbound and Outbound Interco. The page
calls `reports.inventory-movement.details` (branch, dates, `sap_code`, `metric`, `page`), which only
answers for a store the user is assigned to.

`InventoryMovementDetailService::source()` returns, per metric, one row per source line with the
same joins and filters as the grouped query in `InventoryMovementService`, as `tx_date, ref_no,
ref_id, d1, d2, unit, qty`. The service pages it (25), sums it per unit and converts with the
report's own `itemUnits()`, so `total` equals the cell. Wastage is a `UNION ALL` of the item's own
lines and the Sub-Prep lines (quantity x BOM Qty). Supplies Used has no source: its popup is the
calculation (`calculation`) with the current count as its one reference.

Ref No. links (`url()`): an order opens where it was placed - `mass-orders.show` by order number,
`dts-mass-orders.show` by `batch_reference` for a DTS variant, `store-orders.show` otherwise (that
module is unused: every live order is a mass variant); Received `orders-receiving.show`; Sales
`store-transactions.show`; Wastage `wastage.show.by-number`; Beg Bal / Supplies
`month-end-count-approvals.show`; Interco in `interco-receiving.show` (by interco number), out
`interco.show` (by order id). Each target keeps its own permission.

**Export Excel in the popup** (2026-10-05): `reports.inventory-movement.details.export-excel` takes the
popup's own parameters through the same `detailRequest()` check and calls `details()` with
`$perPage = null`, so the file is every line of the figure, not the page showing.
`InventoryMovementDetailExport` writes the popup's columns with dates and quantities as real values,
each Ref No. as a hyperlink, the total row, the notes and - for Supplies Used - the calculation. Its
column and date headings come from `InventoryMovementDetailService::LABELS`, a copy of `detailColumns`
in the page: a renamed column must be changed in both.

## Report numbers

The seven reports (Qty / Cost Variance, Inventory Movement, PMIX, Wastage, Delivery, Actual Cost /
COGS, Interco) print every quantity, amount and percentage with **four decimals whatever the value**
(2026-10-05), so a page matches its exports.

- **Pages** import `formatReportNumber` / `formatReportCurrency` / `formatReportPercent` /
  `formatReportCount` from `resources/js/lib/reportNumbers.js` (their local `formatNumber` etc. are
  aliases of these). Counts of records, items and ranks use `formatReportCount` and stay whole.
- **Excel exports** keep cells numeric and apply `App\Support\ReportNumber::EXCEL` /
  `EXCEL_PESO` / `EXCEL_PERCENT`. Each has `WithStrictNullComparison`: without it maatwebsite/excel
  writes 0 as an empty cell. `WastageReportExport` inserts its two title rows **before** working out
  any row number - doing it after left the first two lines unformatted.
- **PDF** (Inventory Movement only) and notes use `ReportNumber::format()`.

## SOH adjustment

`/soh-adjustment` (`SOHAdjustmentController` + `SohAdjustmentService`, rebuilt 2026-10-05; the page
had only ever read uploads through a `product` relation that no longer exists, so it showed an
empty list and its search threw).

- **Items tab.** The store's SAP items, one row per item + unit as Stock Management lists them,
  each with its SOH in that unit (`balances()`: the same sum as `MonthEndStockAdjustment::balance()`).
  "Adjust" posts the **new SOH** in the row's unit.
- **Filing.** The service stores the *difference* (new - current), converted to the item's stock
  unit, as a `product_inventory_stock_managers` row on the stock row: action `soh_adjustment`,
  signed quantity, `is_stock_adjustment_approved = false`. No balance counts that action, so nothing
  changes yet. One may wait per stock row and store; an unchanged SOH is refused. Remarks carry the
  reason, the typed SOH and who requested (`... | requested by Name`).
- **Approving** (`approve soh adjustment`, created 2026-10-05) rewrites that same row to `add` or
  `out` with the absolute quantity, dated today, and calls `MonthEndStockAdjustment::refreshCache()`.
  **Rejecting** sets the action to `soh_adjustment_rejected` and keeps the row. Nothing is deleted.
- **Upload.** Stock Management's "SOH Update" (`UpdateStockManagementSOH`) goes through the same
  service with a variance per row; the ID must be the SAP row of the Item Code beside it, since old
  files carry ids of `product_inventories` (an empty, dead table). `StockMangementSOHExport` writes
  the file from the SAP Masterlist.

## Business-rule exceptions

For a store that cannot meet a business rule or deadline. `RuleExceptionService` +
`RuleExceptionController` (`/rule-exceptions`), rules in `config/rule_exceptions.php`, one evaluator
per rule in `app/Http/Services/RuleExceptions/`.

| Family | Rules | Effect of approval |
|---|---|---|
| **unlock** — the server blocks the action | `mec.upload_window`, `mass_order.late_order`, `mass_order.edit_after_cutoff`, `dts_mass_order.late_order`, `dts_mass_order.edit_locked` | a **one-time grant**, valid until a time the approver sets, consumed by the action |
| **excuse** — nothing is blocked, the item is scored late | `receiving.late_logging`, `sales.late_upload`, `wastage.late_upload` | the Adoption Rate row shows `Excused` + reason |

Excuses exist only for rows the Adoption Rate report holds, and it holds go-live stores only (from
their go-live week); a row of a store that is not live is refused as "not in the report".
`sales.late_upload` is dormant: a live store's sales day is always scored `Yes` (sales post
automatically from the POS), so there is nothing to excuse.
`receiving.late_logging` can no longer be requested from the UI: the "Request excuse" link on the
Delivery Logging Timeliness tab was removed on 2026-10-01. The rule stays on the server so excuses
approved before then still show as `Excused`.

An ordering template with **no `orders_cutoff` row** is unrestricted: `OrderingCutoffService` opens
tomorrow + 59 days for both mass orders and DTS (CPO stays fully unrestricted). Mass orders also
accept today and the past `MASS_ORDER_BACKDATE_DAYS` (60) days — a temporary allowance added 2026-09-24;
set it to 0 to restore tomorrow-only. The Mass Orders create
dialog no longer offers the *late order* request link (removed 2026-09-22); the rule and its grants remain.

Flow: blocked screen → *Request exception* dialog (asks `/rule-exceptions/eligibility` first) →
`pending` → module approver approves/rejects in the queue → unlock: action succeeds once and the grant
becomes `consumed`; unused grants become `expired` (`rule-exceptions:expire`, every 15 min).

Controls, all enforced in the service rather than the UI:

- Requester holds the rule's performer permission; approver holds the module's **existing** approver
  permission (DTS uses `approve mass order`). Both must be assigned to the store; the approver is
  never the requester.
- A request is refused unless the evaluator confirms the action is **currently blocked** (or the row
  is really `No`, filed within 7 days). Only time rules are waivable — status, permission, store
  scope, delivery schedule, booked DTS dates, received DTS batches and past delivery dates are not.
- One open (pending/approved) request per `rule_key` + `subject_key`: service check plus a SQL Server
  filtered unique index.
- Validity is capped by the rule (`max_validity_hours`) and by the subject (the delivery day's start).
- Consumption locks the grant row inside the protected transaction, so a failed action leaves the
  grant unused and a second use fails. `consume()` throws if called outside a transaction.
- Every transition writes one append-only `rule_exception_request_actions` row (IP, user agent,
  snapshot); the model throws on update/delete. Evidence files live on the private `local` disk.

Where each unlock is consumed: `MassOrdersController@uploadMassOrder` (per store, via the
`processMassOrderUpload` callback — ungranted stores are skipped), `MassOrdersController@update`,
`DTSMassOrdersController@store` / `@update`, `MonthEndCountController@upload` (import + consume in one
transaction). The legacy admin MEC reopen still works alongside.

Excuses are overlaid in `AdoptionRateTrackingService::applyExcuses()`. `Excused` is neither Yes nor
No, so every Yes/No adoption denominator skips it, but `SuccessRateService` still counts it as a
transaction and `WorkflowGuidanceService::reportMetrics()` ignores it.

## Month End Count template: Current SOH

The downloaded template's **Current SOH** is the Inventory Movement Report's **Theoretical SOH**
over the period of the count: previous month's count + approved receipts +
interco in − sales − level 2 wastage − interco out. Both call `InventoryMovementService::movementData()`,
so they cannot drift apart. The value is per ItemCode in the SAP base unit, restated in each template
line's Bulk UOM through `ItemStockUnit` factors; a line with no SAP item or no conversion shows 0.

**The period is per branch, not the calendar month** (`MonthEndCountReadinessService::periods()`): it
is the period of the count the branch takes next - the last scheduled count while the branch has not
submitted it (rejected rows do not count), else the next one on the schedule. A count is taken after
its month ends (September's on October 1); "1st of this month → today" then covered an empty October
and every Current SOH came out 0 (fixed 2026-10-01).

**A count's period ends on its MEC Scheduled Date, not today** (`countPeriod()`, 2026-10-05): the 1st
of the month counted through `MonthEndStockVariance::periodFor()`'s end (the scheduled date, kept
inside the month counted), cut at today only while that date is still ahead. It is the same period
the Qty / Cost Variance report and the closed dates use. Until then it ran through today, so on Oct 5
a store's October orders (not yet delivered, or awaiting approval) withheld its September template and
upload, and October movements leaked into September's Current SOH.

**Zeros need `WithStrictNullComparison`.** maatwebsite/excel writes `0` as an empty cell without it
(`0 == null`), which is why those zeros looked like a missing column. With it, `''` is written too, so
`MonthEndCountDownloadExport::collection()` turns `''` into `null` to keep the fillable cells empty.

The download is **withheld** while the store has open work in that period
(`MonthEndCountReadinessService::blockersForPeriods()`): orders not RECEIVED (approval or receiving
open - there is no "awaiting commit" blocker, since an approved order is received directly and so just
counts as not yet received), a RECEIVED order with a recorded quantity not posted yet (see below), an incoming interco
transfer not received, wastage below level 2, and the previous month's count not level 2 approved.
`/month-end-count` lists them per branch with links; the server refuses too. Sales have
no approval step (`StoreTransactionApprovalController` queries a dropped `is_approved` column).
Two checks were dropped on 2026-10-01 at the user's request and must not come back: an interco
transfer the store sends but has not committed, and unapproved SOH adjustments.

**Receiving has no approval step, so there is no "receipt awaiting approval" blocker** (2026-10-05).
A receipt row (`ordered_item_receive_dates.status`) goes `pending` (worksheet placeholder, TO RECEIVE)
→ `received` (a quantity recorded) → `approved`, and `approved` only means *posted to stock* by the
store's own Confirm Receive All / Final Receive All; `/receiving-approvals` is a legacy page outside
the process. The blocker is a RECEIVED order, not finalized, with an **item** that has a `received`
row whose quantity is not 0 and no `approved` row at all - in practice an Add Unlisted Item made after
Confirm Receive All (it does not re-evaluate the order status). It leaves out what the store cannot
post, what moves no stock and what is already in stock: a delivery locked by Final Receive All, a zero
(Unserved) line, a `pending` row on a RECEIVED order, and any row beside a posted receipt of the same
item (a leftover, e.g. from the Mass Orders re-approval bug; confirming it would post the item twice).

One line per order (`receipt_confirmation_<order number>`, "NNFIL-00329 has 1 item waiting for Confirm
Receive All"), linked to `orders-receiving.show`, not the list: the order's status is RECEIVED either
way, and on its page a recorded row and a posted row both read RECEIVED - only the Confirm Receive All
button and the row's edit button tell them apart. The first version (one counted line linked to the
list) left a store with 39 RECEIVED orders and no way to find the one meant.

## Qty Variance / Cost Variance report

`/reports/qty-variance-cost-variance-report` shows one count (picked by MEC Scheduled Date), one row per
store + ItemCode with a level 2 approved line. **It computes nothing itself**: `MonthEndStockVariance::rows()`
calls `InventoryMovementService::movementDataForBranches()` and relabels the Inventory Movement Report's
row - Actual Inventory = Actual MEC, Theoretical Inventory = Theoretical SOH (Supplies Used already
deducted), Qty Variance = Variance, UoM = the SAP base unit (`ItemStockUnit`). The drill-down
(`getBreakdown`) returns the same row's Beg Bal / Received / Interco / Sales / Wastage / Supplies Used.
`tests/Feature/QtyVarianceCostVarianceReportTest.php` asserts the two reports agree line for line.

**Period** (`MonthEndStockVariance::period()`): the 1st of the schedule's `year`/`month` through its
`calculated_date`, clamped to that month. The clamp is deliberate: `movementData()` takes Beg Bal from the
schedule of the month before `date_from` and Actual MEC from the schedule of `date_to`'s month, so a range
ending in April would compare March's stock with April's count. The page prints the period so the same
dates can be typed into the Inventory Movement Report.

**Cost** is `SupplierUnitCost::find()` for the row's base unit: the supplier price of that unit, else a
linked unit's price through the SAP factors (37.50 per Can = 1,800 per Case at 48 Can), else 0. `for()`
keeps its 1.0 fallback for the stock writers.

Until 2026-10-04 theoretical was read back from the ledger (counted − the `MEC_REF` adjustment) and cost
was the latest supplier row by ItemCode. That ignored Supplies Used (a supplies item the count explained
still showed a shortage), summed count lines in their own units, and priced a Case at the Can's cost.

**Long item lists are not bound into SQL.** pdo_sqlsrv costs about 0.5 ms per bound parameter on every
query of every request, so a month's item list (≈600 codes × a dozen queries) took 4-5 s. Above 200 codes
`movementDataForBranches()` and `SupplierUnitCost::forItems()` leave the item filter out: each query is
already narrowed to the branches and period, and rows of other items are never looked up. Item codes are
cast to strings before binding - a numeric code ("213") becomes an int array key, and SQL Server then
casts the whole `ItemCode` column to int and fails.

The **upload is withheld on the same checks** (`blockersForUpload()`, over the same `countPeriod()` of
the schedule being uploaded): a branch with open work is taken out of the upload form's branch list and shown in a
notice with what it must finish, and `MonthEndCountController@upload` refuses it too. The upload form
follows the branch picked in the download box: once a branch is picked the form shows only if that
branch can upload, so a pending branch never sits above a form meant for the user's other stores. Until 2026-10-01
only the download was gated, so a store could upload a count it could not have taken on the template.
The window rules still apply first; a branch that clears its pending work after the deadline needs a
reopen like any other late store.

## Month End Count approval and rejection

`uploaded` (store reviews) → `pending_level1_approval` → `level1_approved` → `level2_approved` (stock
applied). Statuses live on every `month_end_count_items` row of a schedule + branch.

Either approver can **reject** a count waiting at their own level, with the same permission as that
level's approve: `MonthEndCountApprovalController@rejectLevel1` (`pending_level1_approval`) and
`MECApproval2Controller@rejectLevel2` (`level1_approved`, added 2026-10-04). Both go through
`MonthEndCountRejectionService::returnToStore()`, so a rejection means the same thing at either level.
It needs a reason and a re-upload deadline, and in one transaction it:
- sets the rows to `rejected`, which every "has this branch submitted?" check already excludes;
- writes a `month_end_count_rejections` row (who, at which `level`, why, how many items) that outlives
  those rows;
- grants a `month_end_count_reopens` row until the deadline, never shortening a later one. The store
  can re-upload even after the window closed.

A Level 2 rejection goes to the **store**, not back to Level 1 (the user's decision), and no stock
moves: stock is only posted by Level 2 approval, and a `level2_approved` count cannot be rejected.

The re-upload (`MonthEndCountController@upload`) deletes the `rejected` rows inside the import
transaction, so the new count replaces the old one rather than merging with it, and it starts again
before Level 1 whichever level returned it. `/month-end-count` shows the store the reason and the level
(`returnedCounts`, built by `MonthEndCountRejection::toNotice()`, as are both approval pages' banners).
Both pages use the one dialog, `resources/js/components/month-end-count/RejectCountDialog.vue`.

## Closed dates after the final Month End Count approval

Once a branch's count is `level2_approved`, the period that count covers is settled, and no create or
edit form may take a transaction date inside it. `MonthEndClosedPeriodService` (2026-10-05) is the one
source of the rule:

- `closedThrough($branchIds)` → branch id => last closed date. It is the end of the period the count is
  read over, `MonthEndStockVariance::periodFor()`: the MEC Scheduled Date, kept inside the month counted.
  September's count dated Sep 30 closes through Sep 30; March's dated Apr 5 closes through Mar 31; a
  count dated Oct 29 closes through Oct 29, so the store still records Oct 30 and 31. The latest approved
  count wins. Rows in any other status (`rejected` included) close nothing.
- `closedForAll($branchIds)` → for a date picker several stores share: the last date closed for *every*
  one of them, or null while any is open. A later date stays selectable and the closed stores are
  refused one by one.
- `problem($branchId, $date)` → the message to show, or null. Every server check goes through it.

It reads `month_end_count_items` joined to `month_end_schedules` by branch with the query builder, not
the entity-scoped models: a branch belongs to one entity, and older schedules carry no `entity_id`.

| Module | Date picker | Server |
|---|---|---|
| Mass Orders (create) | `closedForAllStores` strikes the dates through, CPO included; `available-dates` drops them | `uploadMassOrder` skips a closed store with the reason (the rest still order); the template leaves it out |
| Mass Orders (edit) | `closedThrough[branch]`, or `closedForAllStores` once a new date cleared the store; `get-branches` drops closed stores | `update` refuses when the date or store changed into a closed period |
| DTS Mass Orders | `available-dates` drops dates closed for all stores; the Create grid locks a store's closed cells (`closed_through`) | `store` refuses; `update` refuses cells the batch does not already hold (checked before its delete-and-recreate transaction) |
| Wastage (create) | `min` on Wastage Date for the picked store, red note on a typed closed date | `WastageRequest` rule on `wastage_date` |
| Store Transactions | Edit's date is read-only | `StoreStoreTransactionRequest` rule on `order_date`; the update applies it only when the date or store changed |
| Inbound Receiving | - (receipts are stamped `now()`) | `ReceiveOrderRequest` rule on `received_date` (its dialog is not reachable from the page today) |

Only *choosing* a closed date is refused. A transaction that already carries one can still be edited
(Wastage Edit and the sales correction have no selectable date). Not checked on purpose: the POS sales
sync and the sales import, which post whatever the POS recorded, and every receipt stamped with the
current time. The rule is not a business-rule exception: no grant lifts it.

`StoreTransaction/Create.vue` does not load (it uses `ref` / `watch` without importing them and expects
a `menus` prop the controller stopped sending in 2025), so its date picker carries no limit yet; the
server rule covers the endpoint.

## Entity switching

`POST /entity/switch {entity_id}` with an `X-XSRF-TOKEN` header. It updates
`session('active_entity_id')` and `users.last_entity_id`, so every subsequent request is scoped to
the new entity. Any view that shows entity-scoped data or an entity name must be re-checked after a
switch — a hard-coded entity label is a recurring bug.
