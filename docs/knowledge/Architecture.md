# Architecture

Detail behind the summary in [CLAUDE.md](../../CLAUDE.md). Read this when you need the layering,
where a responsibility lives, or which class owns a subsystem.

## Stack

| Layer | Technology |
|---|---|
| Frontend | Vue 3 + Inertia 1 SPA, PrimeVue 4, Tailwind 3, Vite 5 |
| Routing in JS | Ziggy (`tightenco/ziggy`) exposes named Laravel routes |
| Backend | Laravel 11, PHP 8.3 |
| Database | SQL Server (`sqlsrv`); session, cache and queue all on the database driver |
| Auth | Laravel Breeze (session) + Sanctum (tokens) + Spatie permissions |
| Audit | `owen-it/laravel-auditing` on most models |

## Layering

```
routes/web.php  →  Controller (thin)  →  app/Http/Services/*  →  Eloquent model
```

There is **no repository layer**. Services talk to Eloquent directly. Controllers validate,
delegate, and return an Inertia response; they should not hold business rules.

Two service directories exist and mean different things:

- `app/Http/Services/` — **domain logic**, 20 classes. New business logic goes here.
- `app/Services/` — infrastructure only: `GoogleDriveService`, `ImportQueueService`,
  `CommitUomChangeService`.

## Domain services

`WorkflowService` and `ApprovalMatrixService` drive every multi-level approval (orders, wastage,
month-end counts, interco, cash pull-out). Approval levels are data-driven, not hard-coded — e.g.
`WastageApprovalSettingsService::shouldShowLevel2()` decides whether a second level exists at all.

Ordering is split by variant: `StoreOrderService` (regular), `MassOrderService` (mass),
`DTSStoreOrderService` (direct-to-store), `IntercoService` (inter-company),
`OrderCalculatorService` (suggested quantities), `OrderReceivingService` (receiving),
`OrderApprovalService` (approval transitions).

Supporting: `RoleService` (permission grouping for the role UI), `UserService`,
`MonthEndCountSettingsService`, `WastageService`, `AdoptionRateTrackingService`,
`ConsolidatedSOReportService`, `DeliveryScheduleService`, `StoreTransactionService`,
`CSCommitService`, `ApprovalNotificationService`.

## Core models

`StoreOrder` + `StoreOrderItem` are the central aggregate — every order variant is a `StoreOrder`
discriminated by `variant` (`mass regular`, `mass dts`, `INTERCO`, …) and `order_status`.

Other high-traffic models: `Wastage`, `ProductInventory`, `SupplierItems`, `SAPMasterfile`,
`POSMasterfileBOM`, `StoreBranch`, `Entity`, `User`, `MonthEndCountItem`, `SidebarMenuSetting`.

`Entity` is the tenant root and the parent of `StoreBranch`. 46 of 59 models carry `entity_id` via
the `BelongsToEntity` trait — see [Database.md](Database.md).

## Middleware

Appended to the `web` group in [bootstrap/app.php](../../bootstrap/app.php), in order:

1. `SetActiveEntity` — binds the active entity into `EntityContext`
2. `HandleInertiaRequests` — shares auth, permissions, notifications, sidebar
3. `AddLinkHeadersForPreloadedAssets`
4. `CheckSidebarMenuActive` — blocks routes whose sidebar entry is deactivated

Aliases: `check.persmission` (note the misspelling), `check.sidebar.active`, plus Spatie's `role`,
`permission`, `role_or_permission`.

## Frontend structure

- `resources/js/Pages/<Module>/` — one directory per module (~90), matching the controller name.
- `resources/js/components/` — shared components; `layouts/` — app shells; `composables/` —
  reusable logic (e.g. sidebar ordering); `lib/` — helpers.
- Sidebar order and labels are data-driven through `SidebarMenuSetting` and the `sidebarSettings`
  Inertia prop, with drag-reorder in the UI.

## Background processing

`app/Jobs/`: `StartImportJob`, `SAPMasterfileImportJob`, `StoreTransactionImportJob`,
`ProcessStoreTransactionJob`. All Excel work runs on the dedicated **`imports`** queue over the
`database` driver, coordinated by `ImportQueueService` and tracked in `import_logs`.
See [Data-Flows.md](Data-Flows.md).

`app/Imports/` holds 22 maatwebsite readers; `app/Exports/` holds 54 writers.
