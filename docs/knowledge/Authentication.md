# Authentication and Authorization

Detail behind the summary in [CLAUDE.md](../../CLAUDE.md).

## Login requires an entity

DAVID's login form posts **`entity_id` alongside email and password** — a native
`<select id="entity_id">` on the page. This trips up any automation written against a normal
Laravel Breeze login.

[AuthenticatedSessionController](../../app/Http/Controllers/Auth/AuthenticatedSessionController.php):

1. Authenticates the credentials.
2. Rejects the request with a validation error on `entity_id` if the user cannot access the chosen
   entity or it is inactive.
3. Stores `session('active_entity_id')` and force-fills `users.last_entity_id`.

Breeze's remaining routes (register, password reset, email verification, confirm password) are in
[routes/auth.php](../../routes/auth.php).

## Per-request entity resolution

[SetActiveEntity](../../app/Http/Middleware/SetActiveEntity.php) runs on every web request:

```
session('active_entity_id')  →  users.last_entity_id  →  first accessible entity
```

Whatever it picks is re-validated against `accessibleEntities()` filtered by `is_active`, so a
tampered or stale session cannot widen access. The result is bound into
[EntityContext](../../app/Support/EntityContext.php), which drives model scoping — see
[Database.md](Database.md).

Switching: `POST /entity/switch {entity_id}` with an `X-XSRF-TOKEN` header.

## Authorization

Spatie `laravel-permission`, enforced **at the route level**. [routes/web.php](../../routes/web.php)
carries 374 guards of the form:

```php
->middleware('check.persmission:view store orders')
```

- The alias **`check.persmission` is misspelled** in [bootstrap/app.php](../../bootstrap/app.php).
  It is load-bearing across 374 routes — do not rename it.
- [CheckUserPermission](../../app/Http/Middleware/CheckUserPermission.php) is a thin wrapper that
  calls `Gate::allows($permission)` and aborts 403.
- Spatie's own `role`, `permission`, and `role_or_permission` aliases are registered too.

Permissions are defined in `RolesAndPermissionSeeder` (idempotent, re-run on every deploy). Add new
permissions there — never directly in the database.

## Frontend gating

[HandleInertiaRequests](../../app/Http/Middleware/HandleInertiaRequests.php) shares the user's roles
and the **full permission list** on every request, so Vue pages gate UI with `can(...)` locally
instead of making extra requests. It also shares `is_admin`, the accessible entities, and
`activeEntity`.

A second gate exists on top of permissions: `CheckSidebarMenuActive` blocks routes whose sidebar
entry has been deactivated in `SidebarMenuSetting`, so a permission alone does not guarantee access.

## QA accounts

The working elevated/restricted Playwright pair is recorded in auto memory under
`reference_david_qa_profiles` (passwords are deliberately kept out of the repository).

- Elevated: `admin@gmail.com` — role `admin`, entities 1 (Nono's) and 2 (CBTL).
- Restricted: an `e2e-*@example.com` account with role `OPS-Store Rep`, entity 1 only, created by
  `php artisan david:e2e seed-user`.
- **`storerep@gmail.com`, named in `e2e/.env.e2e`, does not exist** in `daviddb`; specs referencing
  it fail at login.
- The pair documented in the `regression-test` skill belongs to a different application (ghelpdesk)
  and does not exist here.
