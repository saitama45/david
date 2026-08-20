# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Permanent session instructions

1. At the beginning of every new session, read this file and Claude auto memory first.
2. Do not start a task by broadly or recursively scanning the entire repository.
3. Use the project map below to identify the relevant subsystem, then read only the files needed.
4. Perform broader exploration only if the documentation is missing, stale, or contradicted by source.
5. Source code remains authoritative when it conflicts with documentation.
6. After a verified architectural or workflow change, update this file and the relevant note in `docs/knowledge/`.
7. Save reusable discoveries, conventions, debugging insights, and architectural knowledge to auto memory.
8. Do not save temporary progress, speculative conclusions, or task-specific status as memory.
9. Keep this file concise to minimize startup tokens. Detailed knowledge belongs in `docs/knowledge/`;
   open only the relevant note per task — do not `@`-import them all.
10. Before ending each significant task, check whether documentation or memory has gone stale, and update it.

## Detailed knowledge (read only what the task needs)

| Note | Read it for |
|---|---|
| [docs/knowledge/Architecture.md](docs/knowledge/Architecture.md) | Layering, service catalogue, middleware order, frontend structure |
| [docs/knowledge/Data-Flows.md](docs/knowledge/Data-Flows.md) | Request lifecycle, import/queue flow, approvals, entity switching |
| [docs/knowledge/Database.md](docs/knowledge/Database.md) | Schema, tenancy columns, SQL Server dialect, migrations, safety |
| [docs/knowledge/Authentication.md](docs/knowledge/Authentication.md) | Entity login, permission enforcement, QA accounts |
| [docs/knowledge/Integrations.md](docs/knowledge/Integrations.md) | Google Drive, Excel, PDF, Redis, Sanctum, Ziggy, deployment |
| [docs/knowledge/Decisions.md](docs/knowledge/Decisions.md) | Why the architecture is shaped this way, and what it costs |

## System purpose

- **DAVID Inventory System** — inventory, ordering and multi-level approval platform for a
  multi-branch food operation (entities: Nono's, Coffee Bean & Tea Leaf).
- Store ordering (regular / mass / DTS / interco / emergency / F&V / ice cream), approval matrices,
  receiving, wastage, month-end counts, stock adjustments, cost and inventory reporting.
- Ingests sales and masterfile data (POS, SAP) through queued Excel imports.
- Scale: ~90 page modules, 105 module controllers (121 files incl. `Auth/`), 59 models, 134 migrations, 567 routes.

## Architecture

- **Frontend:** Vue 3 + Inertia 1 SPA, PrimeVue 4, Tailwind, Vite; Ziggy for named routes.
- **Backend:** Laravel 11 / PHP 8.3. Thin controllers → services → Eloquent. **No repository layer.**
- **Database:** SQL Server (`sqlsrv`); session, cache and queue all on the database driver.
- **Auth:** Breeze session login (**requires an entity**) + Sanctum for the small API surface;
  Spatie `laravel-permission` enforced per route.
- **Background:** dedicated `imports` queue, worker started by `startup.sh`, state in `import_logs`.
- **Integrations:** Google Drive, maatwebsite/excel, dompdf, Redis (installed, not the cache), Sanctum.

## Entry points

- [public/index.php](public/index.php) — HTTP front controller
- [bootstrap/app.php](bootstrap/app.php) — middleware stack, aliases, registered commands
- [routes/web.php](routes/web.php) — all app routes (~88 KB, 374 permission guards)
- [routes/auth.php](routes/auth.php) · [routes/api.php](routes/api.php) · [routes/console.php](routes/console.php)
- [resources/js/app.js](resources/js/app.js) — Inertia + Vue bootstrap
- [resources/views/app.blade.php](resources/views/app.blade.php) — root Blade shell
- [artisan](artisan) — CLI entry
- [startup.sh](startup.sh) — Azure boot: migrations, permission seeder, queue worker

## Directory responsibilities

- `app/Http/Controllers/` — 105 module controllers + `Auth/`, one per module, kept thin
- `app/Http/Services/` — **primary business logic** (20 classes)
- `app/Services/` — infrastructure only: Google Drive, import queue, UOM commits
- `app/Models/` — 59 models; 46 use `BelongsToEntity`
- `app/Models/Concerns/`, `app/Models/Scopes/` — `BelongsToEntity`, `EntityScope`
- `app/Support/` — `EntityContext`, `StockQuantity`
- `app/Http/Middleware/` — `SetActiveEntity`, `HandleInertiaRequests`, `CheckUserPermission`, `CheckSidebarMenuActive`
- `app/Imports/` (22) · `app/Exports/` (54) — Excel readers/writers
- `app/Jobs/` — `StartImportJob`, `SAPMasterfileImportJob`, `StoreTransactionImportJob`, `ProcessStoreTransactionJob`
- `app/Console/Commands/` — import reconcilers, `david:e2e`
- `app/Enum/` **and** `app/Enums/` — two live namespaces (see pitfalls)
- `resources/js/Pages/<Module>/` — Inertia pages, one directory per module
- `database/migrations/` (134) · `database/seeders/` (52)
- `tests/Feature/`, `tests/Unit/` — Pest · `e2e/` — self-contained Playwright suite
- `docs/knowledge/` — detailed notes indexed above

## Main data flows

1. Request enters [routes/web.php](routes/web.php) behind `auth` + `check.persmission:<permission>`.
2. [SetActiveEntity](app/Http/Middleware/SetActiveEntity.php) binds the active entity into
   [EntityContext](app/Support/EntityContext.php).
3. Validation in the controller (`$request->validate`), then delegation to `app/Http/Services/*`.
4. Persistence via Eloquent; [BelongsToEntity](app/Models/Concerns/BelongsToEntity.php) filters by
   `entity_id` and stamps it on create.
5. [HandleInertiaRequests](app/Http/Middleware/HandleInertiaRequests.php) shares auth, permissions,
   entities, sidebar settings and approval-count notifications.
6. Renders an Inertia page in `resources/js/Pages/<Module>/`.
7. Heavy work → `imports` queue → `import_logs`, reconciled every 60s in production.

Full detail: [Data-Flows.md](docs/knowledge/Data-Flows.md).

## Key components

**Services** ([app/Http/Services/](app/Http/Services/)): `WorkflowService`, `ApprovalMatrixService`,
`OrderApprovalService`, `MassOrderService`, `StoreOrderService`, `DTSStoreOrderService`,
`OrderCalculatorService`, `OrderReceivingService`, `IntercoService`, `WastageService`,
`MonthEndCountSettingsService`, `RoleService`, `UserService`, `AdoptionRateTrackingService`.

**Models**: `StoreOrder` + `StoreOrderItem` (core aggregate, discriminated by `variant` and
`order_status`), `Wastage`, `ProductInventory`, `SupplierItems`, `SAPMasterfile`, `POSMasterfileBOM`,
`StoreBranch`, `Entity`, `User`, `MonthEndCountItem`, `SidebarMenuSetting`.

**Controllers**: `DashboardController`, `MassOrdersController`, `DTSMassOrdersController`,
`IntercoController`, `WastageController`, `CSMassCommitsController`, `StockManagementController`,
`MonthEndCountController`, `ApprovalMatrixController`.

**Repositories**: none — services use Eloquent directly.

## Database

SQL Server (`sqlsrv`), timezone `Asia/Manila`. `entities` is the tenant root, `store_branches`
belongs to it, and 46 models carry `entity_id` filtered by a global scope. Most models are audited
(`owen-it/laravel-auditing`). Migrations run automatically on deploy.
Full detail: [Database.md](docs/knowledge/Database.md).

## Authentication and authorization

Login posts **`entity_id` with email/password**;
[AuthenticatedSessionController](app/Http/Controllers/Auth/AuthenticatedSessionController.php)
rejects inaccessible entities, then stores `session('active_entity_id')` and `users.last_entity_id`.
`SetActiveEntity` re-resolves and re-validates it per request. Authorization is Spatie permissions
via the `check.persmission:<permission>` route alias; the full permission list is shared to the
frontend so Vue gates UI with `can(...)`. `CheckSidebarMenuActive` is a second gate on top.
Entity switching: `POST /entity/switch {entity_id}` with `X-XSRF-TOKEN`.
Full detail: [Authentication.md](docs/knowledge/Authentication.md).

## Commands

- **Development:** `composer dev` (server + queue:listen + pail + vite), or `php artisan serve` +
  `npm run dev`. Use `PHP_CLI_SERVER_WORKERS=10` when driving Playwright.
- **Build:** `npm run build`
- **Lint:** `./vendor/bin/pint`
- **Tests:** `php artisan test` · `--testsuite=Unit` · `--filter=MassOrdersIndexTest` ·
  `./vendor/bin/pest tests/Feature/MassOrdersIndexTest.php`
- **E2E** (from `e2e/`): `npm run qa` (read-only) · `qa:watch` (headed) · `qa:full` (destructive) ·
  `qa:purge`
- **Imports** (dry-run unless `--apply`): `imports:reconcile`, `imports:doctor`,
  `imports:requeue-stuck`, `imports:recover-incomplete-jobs`, `imports:repair-failed-logs`,
  `migrations:reconcile`
- **QA fixture:** `php artisan david:e2e seed-user` / `php artisan david:e2e purge`

## Database safety

- **`daviddb` is a protected local database.** Never run destructive operations against it.
- Never run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, destructive seeders,
  `DROP`, or `TRUNCATE` against it.
- Laravel tests must always use a separate isolated test database. [phpunit.xml](phpunit.xml)
  force-overrides `DB_DATABASE` to `daviddb_test`, and [tests/Pest.php](tests/Pest.php) applies
  `RefreshDatabase` to `tests/Feature` — that combination wipes whatever database is effectively
  configured.
- **Verify the active connection before running tests, migrations, seeders, or any
  database-modifying command.** `APP_ENV=testing` alone proves nothing.
- Restores, drops and truncations are for the user to run manually.

## Architectural decisions (summary)

Multi-tenancy by global scope; services instead of repositories; imports queued and reconciled;
route-level permissions shared to the frontend; data-driven sidebar; migrations run on deploy;
SQL Server as the target. Rationale and trade-offs: [Decisions.md](docs/knowledge/Decisions.md).

## Pitfalls and non-obvious behavior

- **`check.persmission` is misspelled** in `bootstrap/app.php` and used by 374 routes. Do not fix it.
- **Queued jobs and console commands have no session**, so no entity is bound — and with no context
  `EntityScope` does **not** filter. Use `EntityContext::runAs($id, fn () => ...)`.
- **A new model with `entity_id` is unscoped** until it uses `BelongsToEntity`.
- **Bump the notification cache key** (`user_notifications_v6_<id>`, 1-min TTL in
  `HandleInertiaRequests`) when changing that payload's shape.
- **Two enum namespaces**: `App\Enum\` (OrderStatus, UserRole, Days, TimePeriod) and `App\Enums\`
  (IntercoStatus, WastageStatus).
- **Services live in `app/Http/Services/`**, not `app/Services/`.
- **SQL Server, not MySQL**: no `LIMIT` in raw SQL, `CONVERT(date, col)` not `DATE()`, `DATEDIFF`
  not `TIMESTAMPDIFF`, `STRING_AGG` not `GROUP_CONCAT`. Multi-column distinct counts need a subquery.
- **Never chain `->with()` onto `selectRaw` + `groupBy`** — Eloquent silently returns null relations.
- **Date-only columns need `'date:Y-m-d'` casts**, or UTC serialization shows the previous day.
- **`e2e/.env.e2e` names `storerep@gmail.com`, which does not exist** in `daviddb`; the working QA
  pair is in auto memory (`reference_david_qa_profiles`), not the one in the `regression-test` skill.
- **Repo root holds throwaway diagnostics** (`check_*.php`, `debug_*.php`, `find_*.php`). Not app code.
- New CRUD module → `laravel-inertia-module` skill. After changes → `regression-test` skill.
  Commit messages: one-line subject, no body.
