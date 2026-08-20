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
- Never delete, reset, migrate, or truncate a database unless explicitly authorized. Restores and
  drops are for the user to run manually.
- The Playwright suite: `npm run qa` is read-only; `qa:full` is destructive and gated on
  `E2E_DESTRUCTIVE=1`, which triggers a backup in `e2e/global-setup.js` and a purge in
  `e2e/global-teardown.js`.

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

## Migrations

134 migrations in `database/migrations/`, plus a shared base class in
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

- **Never chain `->with()` onto a `selectRaw` + `groupBy` query.** Without the primary key in the
  select, Eloquent cannot match relations and silently returns null. Load related models separately
  and key them in PHP.
- **Date-only columns must cast as `'date:Y-m-d'`**, not plain `'date'`. In `Asia/Manila` a plain
  `date` cast serializes to UTC, so `2026-12-28` reaches the frontend as `2026-12-27T16:00:00Z` and
  a date input renders the previous day. Real timestamps stay `'datetime'`.
