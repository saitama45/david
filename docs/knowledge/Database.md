# Database Design

Detail behind the summary in [CLAUDE.md](../../CLAUDE.md). **Read the safety section before running
any migration, seeder, or test.**

## Connections

| | |
|---|---|
| Driver | `sqlsrv` (SQL Server) |
| Dev database | **`daviddb`** — protected, see below |
| Test database | `daviddb_test` |
| Timezone | `Asia/Manila` (UTC+8) |
| session / cache / queue | all on the **database** driver |

## Safety

- **`daviddb` is a protected local database.** Never run destructive operations against it:
  no `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, destructive seeders, `DROP`,
  or `TRUNCATE`.
- Tests must use an isolated database. [phpunit.xml](../../phpunit.xml) force-overrides
  `DB_CONNECTION=sqlsrv` and `DB_DATABASE=daviddb_test`, and [tests/Pest.php](../../tests/Pest.php)
  applies `RefreshDatabase` to everything in `tests/Feature`. That combination **wipes whatever
  database is effectively configured** — so verify the override is intact before running tests.
  `APP_ENV=testing` alone proves nothing.
- Normal database work is allowed and expected on `daviddb`: reads, inserts, updates, upserts,
  safe backfills/idempotent seeders, additive schema changes and reviewed forward migrations.
  Claude should perform those operations directly instead of asking the user. Never execute
  delete/soft-delete actions, destructive schema changes, resets, drops or truncations there.
- The Playwright suite may read, create and update marked records on `daviddb`. Physical-delete
  and purge runs require the isolated test database. Soft-delete must never be executed in any
  database, including tests; verify it without invoking the action. A backup does not override this.

## Multi-tenancy

`entities` is the tenant root; `store_branches` belong to an entity. 46 of 59 models carry an
`entity_id` column and opt in with
[BelongsToEntity](../../app/Models/Concerns/BelongsToEntity.php):

- adds `EntityScope` as a global scope — every query is filtered by the active entity
- stamps `entity_id` on `creating` when the context has one
- `Model::withoutEntityScope()` bypasses the filter for cross-entity admin work

Two consequences:

1. **No active entity means no filtering.** Console commands and queued jobs have no session, so
   scoped models return every entity's rows unless the job sets `EntityContext` itself.
2. **A new `entity_id` column is not scoped** until the trait is added to the model.

## Hand-keyed tables

Almost every table is populated by app activity or imports. The exception is
`success_rate_weekly_tickets`, which holds weekly helpdesk ticket counts typed in by users on the
Dashboard **Success Rate** tab (one row per `entity_id` + `week_start`, Monday-normalised).

It stores **hand-keyed ticket counts only** — `<module>_incoming` / `_closed` for the six modules,
`admin_incoming` / `admin_closed` for technical concerns, plus an optional `adoption_rate_override`.

Transaction volume is deliberately **not** a column: it is counted per week from the Adoption Rate
datasets on read, so it can never go stale against actual order/commit/receiving/sales/wastage
activity. Success Rate, Close Rate and every total are likewise derived in `SuccessRateService`. Do
not add computed or transaction columns here.

## Migrations

136 migrations in `database/migrations/`, plus a shared base class in
`database/support/migrations/` (`Database\Support\Migrations\` PSR-4 namespace).

Migrations **run automatically in production** — [startup.sh](../../startup.sh) executes
`migrations:reconcile` (which marks already-existing objects as ran, avoiding "object already
exists" on imported schemas) and then `migrate --force` on every boot.

## Seeders

52 seeders. The one that matters operationally is `RolesAndPermissionSeeder` — idempotent and
re-run on every deploy. Add new permissions there, never by hand in the database.

## Auditing

Most models implement `owen-it/laravel-auditing`, so writes create audit rows. Bulk operations on
large tables therefore cost more than the row count suggests.

## SQL Server dialect rules

| Wrong (MySQL) | Correct (SQL Server) |
|---|---|
| `LIMIT n` in raw SQL | `->limit(n)` via Eloquent, or `TOP n` |
| `DATE(col)` | `CONVERT(date, col)` |
| `TIMESTAMPDIFF(MINUTE, a, b)` | `DATEDIFF(MINUTE, a, b)` |
| `GROUP_CONCAT(...)` | `STRING_AGG(...)` |
| `DATE_FORMAT(col, '%Y-%m')` | `FORMAT(col, 'yyyy-MM')` |
| `CAST(x AS UNSIGNED)` | `TRY_CAST(x AS INT)` |
| `NOW()` | `GETDATE()`, or bind `Carbon::now()` |

Multi-column distinct counts do not translate — wrap a subquery:
`DB::table($query->select('a', 'b')->distinct(), 'sub')->count()`.

## Eloquent gotchas

- **Eager loading an unbounded set fails outright on SQL Server.** Eloquent emits one
  `where <fk> in (...)` listing every parent key, and SQL Server refuses the statement past its
  expression limit (`SQLSTATE[42000] ... An expression services limit has been reached`) — string
  keys hit the 2,100-parameter cap sooner. A whole-range, all-stores report pull reaches tens of
  thousands of `store_order_items`, so `AdoptionRateTrackingService` loads those relations in
  batches (`chunkedLoad()` / `EAGER_LOAD_CHUNK`) instead of one statement. Any new report that
  eager-loads across a full date range needs the same treatment.
- **Never chain `->with()` onto a `selectRaw` + `groupBy` query.** Without the primary key in the
  select, Eloquent cannot match relations and silently returns null. Load related models separately
  and key them in PHP.
- **Date-only columns must cast as `'date:Y-m-d'`**, not plain `'date'`. In `Asia/Manila` a plain
  `date` cast serializes to UTC, so `2026-12-28` reaches the frontend as `2026-12-27T16:00:00Z` and
  a date input renders the previous day. Real timestamps stay `'datetime'`.
