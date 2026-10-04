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
for the month being counted, to date: previous month's count + approved receipts +
interco in − sales − level 2 wastage − interco out. Both call `InventoryMovementService::movementData()`,
so they cannot drift apart. The value is per ItemCode in the SAP base unit, restated in each template
line's Bulk UOM through `ItemStockUnit` factors; a line with no SAP item or no conversion shows 0.

**The period is per branch, not the calendar month** (`MonthEndCountReadinessService::periods()`): it
starts on the 1st of the month of the count the branch takes next - the last scheduled count while the
branch has not submitted it (rejected rows do not count), else the next one on the schedule - and runs
through today. A count is taken after its month ends (September's on October 1); "1st of this month →
today" then covered an empty October and every Current SOH came out 0 (fixed 2026-10-01).

**Zeros need `WithStrictNullComparison`.** maatwebsite/excel writes `0` as an empty cell without it
(`0 == null`), which is why those zeros looked like a missing column. With it, `''` is written too, so
`MonthEndCountDownloadExport::collection()` turns `''` into `null` to keep the fillable cells empty.

The download is **withheld** while the store has open work in that period
(`MonthEndCountReadinessService::blockersForPeriods()`): orders not RECEIVED (approval or receiving
open - there is no "awaiting commit" blocker, since an approved order is received directly and so just
counts as not yet received), a RECEIVED order with unapproved receipt lines, an incoming interco
transfer not received, wastage below level 2, and the previous month's count not level 2 approved.
`/month-end-count` lists them per branch with links; the server refuses too. Sales have
no approval step (`StoreTransactionApprovalController` queries a dropped `is_approved` column).
Two checks were dropped on 2026-10-01 at the user's request and must not come back: an interco
transfer the store sends but has not committed, and unapproved SOH adjustments.

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

The **upload is withheld on the same checks** (`blockersForUpload()`, from the 1st of the month counted
through today): a branch with open work is taken out of the upload form's branch list and shown in a
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

## Entity switching

`POST /entity/switch {entity_id}` with an `X-XSRF-TOKEN` header. It updates
`session('active_entity_id')` and `users.last_entity_id`, so every subsequent request is scoped to
the new entity. Any view that shows entity-scoped data or an entity name must be re-checked after a
switch — a hard-coded entity label is a recurring bug.
