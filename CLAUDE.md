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
- Scale: ~90 page modules, 106 module controllers (121 files incl. `Auth/` and `Api/`), 61 models, 149 migrations, 571 routes.

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

- `app/Http/Controllers/` — 106 module controllers + `Auth/`, one per module, kept thin
- `app/Http/Services/` — **primary business logic** (23 classes)
- `app/Services/` — infrastructure only: Google Drive, import queue, UOM commits
- `app/Models/` — 61 models; 47 use `BelongsToEntity`
- `app/Models/Concerns/`, `app/Models/Scopes/` — `BelongsToEntity`, `EntityScope`
- `app/Support/` — `EntityContext`, `StockQuantity`
- `app/Http/Middleware/` — `SetActiveEntity`, `HandleInertiaRequests`, `CheckUserPermission`, `CheckSidebarMenuActive`
- `app/Imports/` (22) · `app/Exports/` (54) — Excel readers/writers
- `app/Jobs/` — `StartImportJob`, `SAPMasterfileImportJob`, `StoreTransactionImportJob`, `ProcessStoreTransactionJob`
- `app/Console/Commands/` — import reconcilers, `david:e2e`
- `app/Enum/` **and** `app/Enums/` — two live namespaces (see pitfalls)
- `resources/js/Pages/<Module>/` — Inertia pages, one directory per module
- `database/migrations/` (149) · `database/seeders/` (52)
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

**Services** ([app/Http/Services/](app/Http/Services/)): `RuleExceptionService`, `OrderingCutoffService`,
`OrderApprovalService`, `MassOrderService`, `StoreOrderService`, `DTSStoreOrderService`,
`OrderCalculatorService`, `OrderReceivingService`, `IntercoService`, `WastageService`,
`MonthEndCountSettingsService`, `MonthEndCountReadinessService`, `MonthEndCountRejectionService`,
`InventoryMovementService`,
`RoleService`, `UserService`, `AdoptionRateTrackingService`, `SuccessRateService`, `GoLiveStoresService`.

**Models**: `StoreOrder` + `StoreOrderItem` (core aggregate, discriminated by `variant` and
`order_status`), `Wastage`, `ProductInventory`, `SupplierItems`, `SAPMasterfile`, `POSMasterfileBOM`,
`StoreBranch`, `Entity`, `User`, `MonthEndCountItem`, `SidebarMenuSetting`,
`SuccessRateWeeklyTicket`.

**Controllers**: `DashboardController`, `MassOrdersController`, `DTSMassOrdersController`,
`IntercoController`, `WastageController`, `CSMassCommitsController`, `StockManagementController`,
`MonthEndCountController`, `RuleExceptionController`.

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
- **`Model::insert()`, `upsert()` and `DB::table()` skip `BelongsToEntity`**, so `entity_id` lands NULL
  (invisible, and it collides on `(entity_id, …)` unique indexes). Stamp it from `EntityContext` explicitly.
- **`DeliverySchedule` (the 7 weekday rows) is shared, not entity-scoped.** All entities' store schedules
  point at ids 1–7; scoping it hid every non-Nono's store from templates. Don't add `BelongsToEntity` back.
- **Dashboard Success Rate tab: only tickets are typed in.** `success_rate_weekly_tickets` stores
  Incoming/Closed per module and nothing else. Transaction volume is counted per week from the five
  Adoption Rate datasets (MEC has no indicator, so it contributes none), and Success Rate / Close
  Rate / totals are derived in `SuccessRateService`. Never add a transactions column.
  Transactions + adoption fallback are cached **together, and separately from the ticket data** —
  sharing one cache entry makes every save re-run the slow Adoption Rate trend and the save looks
  hung. With `HELPDESK_API_URL`/`KEY` set, Incoming/Closed come from ghelpdesk instead
  (`HelpdeskTicketTallyClient`, 5-min cache). A ticket type showing 0 there is a ghelpdesk item
  missing its "DAVID Success Rate" type on helpdesk `/items` (`items.report_key`), not a DAVID bug;
  NONOS counts every helpdesk company's tickets (`DAVID_TALLY_ALL_TICKETS_ENTITY` in ghelpdesk).
- **Adoption Rate only counts go-live stores, and its commit and sales upload indicators are always
  100%.** `AdoptionRateTrackingService::liveRows()` drops every row of a store that was not live on the
  row's date (live = from the Monday of its go-live week, `GoLiveStoresService::goLiveDates()`, the
  Go-Live tab's own definition) from all five datasets, so those rows reach no rate, no Overall section
  and no Success Rate transaction count. Only My Actions passes `include_not_live` to keep them.
  Committing is no longer a prerequisite of receiving, so `commitStatus()` returns `Yes` (`NA` only for
  DROPS / CPO / PUL-O finished goods); sales post automatically from the POS replica (`pos:sync-sales`),
  so a live store's sales day is `Yes`, upload or not. Both must stay `Yes`, not `NA`: the Success Rate
  tab counts Yes/No rows as transactions. A new dataset must call `liveRows()` too.
- **A new permission is invisible in the role editor until it is listed in
  `RoleService::getPermissionsGroup()`'s `$permissionStructure`.** Unlisted permissions are silently
  dropped, not grouped under "Others". Admin still gets them (the seeder syncs all permissions to it),
  so the gap only shows when another role needs access.
- **Bump the notification cache key** (`user_notifications_v7_<id>`, 1-min TTL in
  `HandleInertiaRequests`) when changing that payload's shape, and every `Cache::forget` of it.
- **There is no live approval matrix.** `WorkflowService`, `ApprovalMatrixService` and their
  controllers/tests are dead code (tables dropped 2025-11-30). Real approvals are a Spatie permission
  + the approver's store assignment + a status column on the module's own table.
- **Ordering cutoffs are enforced on the server** through `OrderingCutoffService` (mass orders, DTS).
  Never re-derive cutoff maths in a controller or Vue page. A blocked action may only pass with an
  approved one-time grant from `RuleExceptionService`, consumed **inside** the action's transaction.
  Detail: [Data-Flows.md](docs/knowledge/Data-Flows.md#business-rule-exceptions).
- **DTS batch update deletes and recreates the batch.** Any guard must run before its transaction.
- **Sales are dated by `store_transactions.order_date`, not `created_at`.** `created_at` is only the
  import timestamp — a day's POS file is uploaded the next day, and one upload usually carries
  several earlier sales dates. Any sales-by-date report must filter `order_date` (date-only, so
  `whereBetween` is already inclusive). The Inventory Movement Report had this wrong until 2026-09-18.
- **A month end count is identified by its MEC Scheduled Date, not by a calendar month.**
  `month_end_schedules.calculated_date` often sits in the month after the schedule's `year`/`month`
  (the March 2026 count is dated Apr 5), so never map a picked date to a count through its month. The
  Qty / Cost Variance report picks its one count from a dropdown of those dates (`mec_date`), not from
  a free From / To range.
- **The Qty / Cost Variance report has no figures of its own - they are the Inventory Movement Report's.**
  `MonthEndStockVariance::rows()` reads `InventoryMovementService::movementDataForBranches()` over the
  count's `period()` (1st of the month counted through the MEC Scheduled Date, kept inside that month),
  so Actual / Theoretical / Qty Variance = Actual MEC / Theoretical SOH / Variance in the SAP base unit,
  Supplies Used included. Cost is `SupplierUnitCost::find()` for that unit (0 when unpriced, never 1).
  Never read theoretical back from the stock ledger again: it ignored Supplies Used and mixed count units.
  Detail: [Data-Flows.md](docs/knowledge/Data-Flows.md#qty-variance--cost-variance-report).
- **Supplies usage has no transaction - only the MEC count reveals it.** An item is supplies when its
  SAP Item Type is OPERATING / CLEANING SUPPLIES or a supplier item's category is `Supplies`. Nobody
  logs usage (the Stock Management log-usage / cost-centre path has never been used), so the level-2
  MEC adjustment is where it lands. The Inventory Movement Report's Supplies Used is the book balance
  the count does not find (never negative; recipe items like cups are already in Sales).
- **Never join `sap_masterfiles` on `ItemCode` inside a SUM.** An item has one row per AltUOM (and can
  have two `BaseUOM=AltUOM` rows), so every line is counted once per matching row. Sum per
  `(item_code, unit)` from the source table and convert with `AltQty × AltUOM = BaseQty × BaseUOM`.
  The Inventory Movement Report does this: one row per ItemCode, in the SAP BaseUOM of its conversion
  rows (36 Gm sold of a 1000 Gm Bag = 0.036 Bag), with inconvertible units listed in `unconverted_units`
  (fixed 2026-09-24; it had doubled every total).
- **SAP Item Type belongs to the ItemCode, not the `sap_masterfiles` row.** It lives in
  `sap_item_type_assignments (entity_id, item_code)`; read it with `SAPMasterfile::withItemType()` /
  `whereItemType()`. Never add a per-row type column - rows are per AltUOM and would drift apart.
  Types are deactivated, never deleted. An import without an `Item Type` column leaves types alone.
- **Stock lives on one base row per linked unit group - resolve it with `App\Support\ItemStockUnit`.** Never
  pick "the `BaseUOM = AltUOM` row" with `->first()`: an item can have two (Can/Can + Case/Case). Units
  SAP links by conversion share the SAP base unit's row (48 Can = 1 Case -> Case); an unlinked base
  (Sprite's Can next to LIT) keeps its own. Convert with `factor()` (BaseQty / AltQty, chained), never
  `BaseQty` alone. Misplaced history: `php artisan stock:rekey-base-rows` (dry run; `--apply`).
  Item pickers and stock lists offer **every unit** (Gm has no Gm/Gm row) once each via
  `ItemStockUnit::onePerUnit()` - never filter to `BaseUOM = AltUOM` rows. Price a unit with
  `App\Support\SupplierUnitCost` (supplier cost is per ItemCode + uom), never by ItemCode alone.
- **SAP import key is ItemCode + AltUOM + BaseUOM** (per entity). SAP restates a pack in a second
  base (Case = 48 Can and Case = 18720 Gm); both rows import. A second base is still refused unless
  the file gave that same AltUOM under the accepted base first. Stored blank-BaseUOM pairs match without it.
  The one-by-one Create on `/sapitems-list` applies the same key and base guard (a pack already on file may be
  restated); Supplier Items' Create applies the import's assigned-supplier and SAP ItemCode + unit checks.
- **An order line can have no supplier item.** Add Unlisted Item (`/orders-receiving/show`) briefly
  (2026-10-02) took any active SAP Masterlist item, so `store_order_items` rows exist whose
  `supplierItem` is null (ordered qty 0). Read the line's own `item_code` / `uom` / `item_description`,
  never `supplierItem->…` or `supplier_item.…` without a fallback: Confirm Receive All used the supplier
  item's code and would have marked such a line received without posting stock. Add Unlisted Item now
  takes only the order's supplier list (item + unit, at the supplier cost); the SAP tab was removed "for now".
- **Receiving is locked by Final Receive All, not by time.** `store_orders.receiving_finalized_at/_by`;
  every item-list action (receive, edit/delete history, add unlisted, confirm) checks
  `OrderReceivingService::receivingLockedProblem()`. Confirm Receive All posts but leaves the list open.
  The old 3-day window from `order_date` is gone. Delivery receipts and images stay editable.
- **Wastage approval may take SOH negative** while Wastage Settings' "Negative Stock on Approval" is on
  (the default). A shortfall needs `confirm_negative_stock` on the approve request, enforced by
  `WastageService::approvalStockProblem()`; never re-add a hard stock block in the controllers.
  Detail: [Data-Flows.md](docs/knowledge/Data-Flows.md#approval-flow).
- **`pos_masterfiles.Category` is free text, not a Menu Categories key.** Forms must offer
  `POSMasterfileController::categoryNames()` (menu categories + categories in use); a Select given only
  `MenuCategory::options()` shows blank for every other category. `pos_masterfiles.UOM` (2026-10-05) is
  free text for reference only; nothing computes with it.
- **A report prints every quantity, amount and percentage with four decimals**, whatever the value
  (5 -> 5.0000), the same on the page, in the PDF and in the Excel export. Pages use
  `resources/js/lib/reportNumbers.js`; exports use `App\Support\ReportNumber` (`EXCEL` number formats,
  `format()` for text) and need `WithStrictNullComparison`, or a 0 is written as an empty cell. Never
  format a report number locally or send Excel a `number_format()` string. Counts and ranks stay whole.
- **An SOH adjustment waits as a stock history row no balance counts.** `SohAdjustmentService` files
  it as `product_inventory_stock_managers` action `soh_adjustment` (signed difference, in the item's
  stock unit, on its stock row); approving rewrites that same row to `add` / `out` and refreshes the
  cache, rejecting parks it as `soh_adjustment_rejected`. Never add either action to a balance, and
  never post a correction any other way. `/soh-adjustment` lists SAP unit rows, not the dead
  `product_inventories` table. Detail: [Data-Flows.md](docs/knowledge/Data-Flows.md#soh-adjustment).
- **A wastage line can have no SAP item: a Sub-Prep line.** A POS item of the `Sub-Prep` category
  (`POSMasterfile::isSubPrep()`) is wasted as itself - `wastages.pos_masterfile_id` set,
  `sap_masterfile_id` NULL, qty in the POS item's UOM, cost = its Supplier Items cost for that unit
  (`SubPrepRecipe::unitCost()`; the SRP is a selling price, never a cost). Read a line's item with
  `Wastage::lineItem()`, never `sapMasterfile->...` without a fallback. It holds no stock: approval
  deducts, and the Inventory Movement Report charges, its raw materials through `App\Support\SubPrepRecipe`
  (BOM Qty x quantity, **per one unit, as a sale reads the BOM**). Anything that sums wastage per SAP
  item must add that breakdown. Any other POS product is never a line: the search only lists its
  ingredients. Detail: [Data-Flows.md](docs/knowledge/Data-Flows.md#sub-prep-wastage).
- **A Month End Count can be rejected at Level 1 or Level 2, and both return it to the store** through
  `MonthEndCountRejectionService::returnToStore()` (rows `rejected`, upload reopened, re-upload replaces
  them and starts again before Level 1). Never give one level its own reject logic.
  Detail: [Data-Flows.md](docs/knowledge/Data-Flows.md#month-end-count-approval-and-rejection).
- **`supplier_items` text columns (category, brand, classification, packaging_config) and `config` are NOT
  NULL**, but blank form inputs arrive as null (ConvertEmptyStringsToNull). Store `''` / `0`, as the import does.
- **Fresh migrations create a UNIQUE index on `sap_masterfiles.ItemCode`** that the live database does
  not have (one row per AltUOM). Test fixtures needing two UOM rows must drop it.
- **A POS BOM line is POSCode + ItemCode + BOMUOM + Assembly, but one item may repeat with a different
  BOMQty** (each line is deducted separately per sale). The import updates the line with the same
  BOMQty; a repeat with a new BOMQty is held back in the session for the user to allow or dismiss on
  `/pos-bom-list`, never written over the line before it (it silently did until 2026-09-25).
  The one-by-one Create there refuses the same line + BOMQty, saves a repeat only with `allow_repeat`
  (the form asks first), and - stricter than the import - requires BOMUOM to be a SAP unit of the item.
- **Two enum namespaces**: `App\Enum\` (OrderStatus, UserRole, Days, TimePeriod) and `App\Enums\`
  (IntercoStatus, WastageStatus).
- **Services live in `app/Http/Services/`**, not `app/Services/`.
- **SQL Server, not MySQL**: no `LIMIT` in raw SQL, `CONVERT(date, col)` not `DATE()`, `DATEDIFF`
  not `TIMESTAMPDIFF`, `STRING_AGG` not `GROUP_CONCAT`. Multi-column distinct counts need a subquery.
- **Never chain `->with()` onto `selectRaw` + `groupBy`** — Eloquent silently returns null relations.
- **Date-only columns need `'date:Y-m-d'` casts**, or UTC serialization shows the previous day.
- **Toast each action from one place.** The layout mounts the only PrimeVue `<Toast />`; a page adding its
  own shows every toast twice. Inertia 1.3 re-creates the page on any visit without `preserveState`,
  even a redirect back to itself, so a page that toasts `flash.success` in `onMounted` already covers
  its forms and imports. Their `onSuccess` must not toast it again.
- **`e2e/.env.e2e` names `storerep@gmail.com`, which does not exist** in `daviddb`; the working QA
  pair is in auto memory (`reference_david_qa_profiles`), not the one in the `regression-test` skill.
- **Repo root holds throwaway diagnostics** (`check_*.php`, `debug_*.php`, `find_*.php`). Not app code.
- **Every New Release, Enhancement and Removed feature goes into the Change Log automatically, in the
  same task and commit - never wait to be asked.** Entries live at the top of
  [resources/js/Pages/ChangeLog/entries.js](resources/js/Pages/ChangeLog/entries.js) (page `/change-log`,
  the header button beside Knowledge Base). Write the process and the business rules in plain words for
  store and office users: no table, file, class or command names. Types are New Release, Enhancements
  and Removed; there is no "Fixed" (a correction is an Enhancement). Internal-only work is not logged.
  Follow the project `change-log` skill ([.claude/skills/change-log/SKILL.md](.claude/skills/change-log/SKILL.md)).
- New CRUD module → `laravel-inertia-module` skill. After changes → `regression-test` skill, then
  the `change-log` skill for anything users will notice.
  Commit messages: one-line subject, no body.
