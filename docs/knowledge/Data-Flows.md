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
subsystem. It is cached per user for **one minute** under `user_notifications_v6_<id>`.

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
advances through levels (`pending` → `approved_lvl1` → `approved` and variants), driven by
`WorkflowService` + `ApprovalMatrixService`. Which levels exist is configuration, not code — read
the relevant settings service before assuming a two-level flow.

## Entity switching

`POST /entity/switch {entity_id}` with an `X-XSRF-TOKEN` header. It updates
`session('active_entity_id')` and `users.last_entity_id`, so every subsequent request is scoped to
the new entity. Any view that shows entity-scoped data or an entity name must be re-checked after a
switch — a hard-coded entity label is a recurring bug.
