# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**David Inventory System** — a Laravel 11 + Inertia + Vue 3 inventory, ordering and
approval platform for a multi-branch food operation. ~90 page modules, 121 controllers,
59 models, 134 migrations. Runs on **SQL Server** (`sqlsrv`), deployed to Azure App Service.

## Commands

```bash
composer dev              # server + queue:listen + pail + vite, all at once (preferred)
php artisan serve         # app only
npm run dev               # vite only
npm run build             # production assets

php artisan test                                   # full suite
php artisan test --testsuite=Unit                  # unit only
php artisan test --filter=MassOrdersIndexTest      # single test class
./vendor/bin/pest tests/Feature/MassOrdersIndexTest.php   # single file
./vendor/bin/pint                                  # format PHP
```

End-to-end (Playwright, self-contained in `e2e/` with its own `package.json` and `.env.e2e`):

```bash
cd e2e
npm run qa            # read-only smoke run
npm run qa:watch      # headed, slowed down, watchable
npm run qa:full       # includes destructive workflow tests (E2E_DESTRUCTIVE=1)
npm run qa:purge      # clean up marked test data
```

`php artisan david:e2e seed-user` / `david:e2e purge` manage the test account and its data
from the app side.

## Database safety

`daviddb` is the protected developer database — never run `migrate:fresh`, `migrate:refresh`,
`db:wipe`, or destructive SQL against it.

Feature tests are safe **because** [phpunit.xml](phpunit.xml) force-overrides the connection
to `daviddb_test`, and [tests/Pest.php](tests/Pest.php) applies `RefreshDatabase` to everything
in `tests/Feature`. That combination wipes whatever database is effectively configured, so
before running tests, confirm the override is still in place. `APP_ENV=testing` alone proves
nothing.

## Architecture

### Entity multi-tenancy

Every tenant-owned row carries `entity_id`, and 47 models opt in via
[BelongsToEntity](app/Models/Concerns/BelongsToEntity.php):

- [SetActiveEntity](app/Http/Middleware/SetActiveEntity.php) resolves the active entity per
  request — `session('active_entity_id')` → `user.last_entity_id` → first allowed — always
  re-validated against the user's accessible entities, then binds it into
  [EntityContext](app/Support/EntityContext.php) (a singleton).
- `EntityScope` filters every query; `creating` stamps `entity_id` automatically.
- `Model::withoutEntityScope()` bypasses the filter for cross-entity admin work.

Two consequences that bite:

1. **Queued jobs and console commands have no session**, so no active entity is bound. When
   nothing is set, scoped models are deliberately **not** filtered — a job that should be
   entity-aware must set it itself, ideally via `EntityContext::runAs($id, fn () => ...)`.
2. A new model with an `entity_id` column is not scoped until it uses the trait.

`Entity` is the parent of `StoreBranch`. Users pick an entity at login and switch from the sidebar.

### Permissions

Spatie `laravel-permission`, enforced at the route level — [routes/web.php](routes/web.php)
carries 374 permission guards.

**The middleware alias is misspelled and must stay that way:** `check.persmission`
(registered in [bootstrap/app.php](bootstrap/app.php)). Renaming it breaks every route.

Permissions are seeded idempotently by `RolesAndPermissionSeeder`, which runs on every
deploy. Add new permissions there, never by hand in the database. The full permission list
is shared to the frontend on every request, so Vue pages gate UI with `can(...)` rather than
re-querying.

### Inertia shared props

[HandleInertiaRequests](app/Http/Middleware/HandleInertiaRequests.php) shares `auth` (user,
roles, permissions, accessible entities, `activeEntity`), `flash`, `sidebarSettings`,
`wastageApprovalConfig`, and a large `notifications` payload of pending-approval counts.

The notification block runs a dozen count queries, so it is cached per user for one minute
under `user_notifications_v6_<id>`. **Bump that cache key** when changing the shape of the
payload, or users keep the stale structure for a minute. Notifications are deliberately
limited to the current calendar month.

### Services

Most business logic lives in **`app/Http/Services/`** (20 classes — `WorkflowService`,
`ApprovalMatrixService`, `RoleService`, `MassOrderService`, `OrderCalculatorService`,
`MonthEndCountSettingsService`, …), *not* `app/Services/`, which holds only the three
infrastructure services (Google Drive, import queue, UOM commits). Put new domain logic in
`app/Http/Services/` to match.

### Imports

Excel imports (`maatwebsite/excel`) are queued on the **`imports`** queue over the `database`
driver, coordinated by `ImportQueueService` and tracked in `import_logs`. Because a stuck row
can block the queue, several reconcilers exist, registered in `bootstrap/app.php`:

```bash
php artisan imports:reconcile [--apply] [--stale-minutes=]   # runs every 60s in production
php artisan imports:doctor
php artisan imports:requeue-stuck [--apply] [--include-failed] [--stale-minutes=60]
php artisan imports:recover-incomplete-jobs [--apply]
php artisan imports:repair-failed-logs [--apply]
php artisan migrations:reconcile      # prevents "object already exists" on imported schemas
```

All of them **dry-run by default** — nothing changes without `--apply`.

### Approvals

Multi-level approval workflows (orders, wastage, month-end counts, interco, cash pull-out)
run through `WorkflowService` + `ApprovalMatrixService`. Approval levels are data-driven —
e.g. wastage level 2 only appears when `WastageApprovalSettingsService::shouldShowLevel2()`.

### Frontend

`resources/js/Pages/<Module>/` per module, plus `components/`, `composables/`, `layouts/`,
`lib/`. PrimeVue 4 + Tailwind, with radix-vue/reka-ui, `vuedraggable` (sidebar ordering),
Quill, Chart.js. Ziggy exposes named routes to JS. Sidebar visibility is DB-backed
(`SidebarMenuSetting`) and enforced server-side by `CheckSidebarMenuActive`.

Models implement `owen-it/laravel-auditing`, so most writes produce audit rows.

## SQL Server constraints

This is the most common source of code that works locally in a query builder and fails in
production:

- No `LIMIT`/`OFFSET` — use `->limit()`/`->take()` and let Eloquent emit `TOP`/`OFFSET FETCH`.
- Date truncation is `CONVERT(date, col)`, not `DATE(col)`.
- `distinct()` combined with `count()` across multiple columns does not translate; the
  codebase wraps the subquery instead — `DB::table($query->select(...)->distinct(), 'sub')->count()`.
- Avoid `whereHas` chains over the large transaction tables; existing code selects narrow
  columns and dedupes in PHP for exactly this reason.

## Deployment

Azure App Service runs [startup.sh](startup.sh) on boot — **not** a GitHub workflow. It
reconciles the migration log, runs `migrate --force`, re-seeds roles and permissions, starts
the `imports` queue worker and a periodic import reconciler, then rebuilds the config/route/view
caches. **Migrations run automatically in production**, so a merged migration ships on the next
restart.

## Conventions

- New CRUD module: use the `laravel-inertia-module` skill — it covers every touch point
  (permission, seeder, sidebar entry, role-edit UI, layout settings) so none is missed.
- After any code change: run the `regression-test` skill.
- Commit messages: one-line subject, no body unless asked.
- The repo root holds ad-hoc debug scripts (`check_*.php`, `debug_*.php`, `find_*.php`,
  `sales_report.php`, …). They are throwaway diagnostics, not part of the application — don't
  treat them as reference implementations.
