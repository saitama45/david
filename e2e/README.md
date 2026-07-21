# DAVID — Browser QA Automation (Playwright)

Self-contained end-to-end browser tests that drive the real app. Isolated from the
Laravel build: its own `package.json` and `node_modules` live here in `e2e/`.

## One-time setup

```bash
cd e2e
npm install
npm run setup          # installs the Chromium browser
cp .env.e2e.example .env.e2e   # then edit .env.e2e with real values
```

The app must be running and reachable at `E2E_BASE_URL` (default
`http://127.0.0.1:8000`). Start it with your usual `php artisan serve` + built/dev
front-end.

Fill in `.env.e2e`:
- **`E2E_ADMIN_EMAIL` / `E2E_ADMIN_PASSWORD`** — a real user who can log in.
- **`E2E_ENTITY_ID`** — the entity that user picks on the login form (DAVID requires
  an entity selection). Leave unset to auto-pick the first one offered.
- **`E2E_DB_*`** — SQL Server backup settings; required for destructive runs
  (`qa:full` / `qa:demo`), which back the DB up first.

## Run modes

| Command | What it does |
|---|---|
| `npm run qa` | Read-only smoke, headless, fast. Safe anywhere (CI / pre-deploy). |
| `npm run qa:watch` | Same, **headed + slowed** — watch it click through. |
| `npm run qa:demo` | **The user delete → restore demo**, headed + slow. Backs up the DB first, purges the test user after. |
| `npm run qa:full` | Full regression incl. destructive tests. Backs up + purges. |
| `npm run qa:full:watch` | Same, headed, to demo everything. |
| `npm run qa:ui` | Playwright time-travel debugger. |
| `npm run qa:report` | Open the last HTML report. |
| `npm run qa:codegen` | Record a new test by clicking the app. |
| `npm run qa:purge` | Manually clean up E2E fixtures if a run was interrupted. |

So the command you were missing:

```bash
cd e2e
npm run qa:demo
```

## Safety model

- **Backup before destructive runs** is a hard gate wired into `global-setup.js`.
  If the backup can't run, the suite stops.
- **Read-only vs destructive** are split. Only `qa:full` / `qa:demo`
  (`E2E_DESTRUCTIVE=1`) touch data.
- **Marked, self-cleaning fixtures.** The `david:e2e seed-user` artisan helper
  creates users with an `e2e-` email marker; `david:e2e purge` (run automatically
  in teardown) deletes only those. Real data is never touched.
- **Non-production only.** The `david:e2e` command refuses to run when
  `APP_ENV=production`.

## What's covered

- `tests/auth.setup.js` — logs in once (entity + email + password), saves the session.
- `tests/users.admin.spec.js` — read-only: Users index + Deleted Users page load.
- `tests/user-lifecycle.workflow.spec.js` — destructive demo: seed a user → delete
  it → confirm it moves to Deleted Users → restore it → confirm it's back.

## Notes / gotchas

- Runs **serially** (`workers: 1`) against the shared dev DB — parallel would race.
- If selectors drift after a UI change, `npm run qa:codegen` records fresh ones.
- An interrupted destructive run: `npm run qa:purge` to clean up leftovers.
