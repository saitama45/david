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

## Business-rule exceptions

For a store that cannot meet a business rule or deadline. `RuleExceptionService` +
`RuleExceptionController` (`/rule-exceptions`), rules in `config/rule_exceptions.php`, one evaluator
per rule in `app/Http/Services/RuleExceptions/`.

| Family | Rules | Effect of approval |
|---|---|---|
| **unlock** — the server blocks the action | `mec.upload_window`, `mass_order.late_order`, `mass_order.edit_after_cutoff`, `dts_mass_order.late_order`, `dts_mass_order.edit_locked` | a **one-time grant**, valid until a time the approver sets, consumed by the action |
| **excuse** — nothing is blocked, the item is scored late | `receiving.late_logging`, `sales.late_upload`, `wastage.late_upload` | the Adoption Rate row shows `Excused` + reason |

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

## Entity switching

`POST /entity/switch {entity_id}` with an `X-XSRF-TOKEN` header. It updates
`session('active_entity_id')` and `users.last_entity_id`, so every subsequent request is scoped to
the new entity. Any view that shows entity-scoped data or an entity name must be re-checked after a
switch — a hard-coded entity label is a recurring bug.
