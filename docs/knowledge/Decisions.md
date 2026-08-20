# Architectural Decisions

Why the codebase is shaped the way it is, and what each choice costs. Detail behind the summary in
[CLAUDE.md](../../CLAUDE.md).

## Multi-tenancy by global scope

**Decision:** tenant isolation lives in a model trait
([BelongsToEntity](../../app/Models/Concerns/BelongsToEntity.php)) that installs a global
`EntityScope`, rather than an `entity_id` filter written into every query.

**Why:** 46 scoped models and 105 controllers — per-query filtering would leak the moment someone forgot.

**Cost:** the filter is invisible at the call site. Two failure modes follow directly:
a context-free environment (queue, console) silently returns *all* entities' rows, and a new model
with an `entity_id` column is unscoped until someone remembers the trait. Cross-entity work must be
explicit via `withoutEntityScope()` or `EntityContext::runAs()`.

## Services, not repositories

**Decision:** business logic sits in `app/Http/Services/`; controllers stay thin; Eloquent is used
directly with no repository abstraction.

**Why:** the domain is query-heavy reporting and workflow, where an extra abstraction over Eloquent
buys little and blocks query-builder features the reports need.

**Cost:** services are the only guardrail against logic drifting into controllers, and there is no
seam for swapping persistence. Note the split: `app/Http/Services/` is domain logic,
`app/Services/` is infrastructure only.

## Imports are queued and reconciled

**Decision:** all Excel work goes to a dedicated `imports` queue with a state table (`import_logs`)
and five reconciler commands, rather than synchronous processing.

**Why:** masterfile and sales imports are large and slow, and the App Service request timeout would
kill them.

**Cost:** substantial machinery — a worker loop, a 60-second reconciler, and dry-run-by-default
repair commands, all started from [startup.sh](../../startup.sh). A single stuck row blocks the
queue, which is exactly why the reconcilers exist. See [Data-Flows.md](Data-Flows.md).

## Route-level permissions, shared to the frontend

**Decision:** every route carries `check.persmission:<permission>` (374 of them), and the user's
entire permission list is shared on each Inertia response.

**Why:** one enforcement point on the server, and the SPA can gate UI without extra round trips.

**Cost:** the permission payload is on every response, and the seeder is the single source of truth
— permissions added by hand in the database will be out of sync on the next deploy. The alias
misspelling `check.persmission` is now load-bearing across 374 routes and must not be "fixed".

## Sidebar is data, not code

**Decision:** navigation lives in `SidebarMenuSetting` and is enforced by `CheckSidebarMenuActive`,
with drag-reorder and rename in the UI.

**Why:** operations staff reorganize navigation without a deploy.

**Cost:** a permission alone no longer guarantees access — a deactivated sidebar entry blocks the
route too, which makes "why is this 403?" a two-place lookup.

## Deploy runs migrations

**Decision:** [startup.sh](../../startup.sh) runs `migrations:reconcile`, `migrate --force` and the
permission seeder on every boot. There is no GitHub deployment workflow doing this.

**Why:** App Service restarts are the deployment unit; the schema was originally imported, so
`migrations:reconcile` marks pre-existing objects as ran to avoid "object already exists".

**Cost:** a merged migration ships on the next restart whether or not anyone intended it. Treat
migrations as immediately production-bound.

## SQL Server as the target

**Decision:** `sqlsrv` everywhere, including tests.

**Why:** the existing corporate data estate.

**Cost:** a standing dialect tax — no `LIMIT`, `CONVERT(date, …)` instead of `DATE()`, no
multi-column distinct counts. See the dialect table in [Database.md](Database.md). Query-builder
code that passes locally on another engine will fail here.
