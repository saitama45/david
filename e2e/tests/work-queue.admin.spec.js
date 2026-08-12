const { test, expect } = require('@playwright/test');

/**
 * Work Queue (/work-queue) verification across two contrasting profiles.
 *
 * Covers the scheduler change that drives the `imports` queue: the page is the
 * real UI surface for import_logs state, so it is what a stuck/queued import
 * actually shows up on.
 *
 *   Elevated   admin@gmail.com    role `admin`  → has `view import logs`,
 *                                                 sees EVERY user's logs
 *   Restricted storerep@gmail.com role `store representative`
 *                                               → NO `view import logs`,
 *                                                 no nav link, 403 on direct hit
 *
 * Read-only: no imports are dispatched and no logs are mutated. The entity
 * switch writes `last_entity_id`, so the run resets to entity 1 at the end.
 */

// Both profiles log in explicitly; do not inherit the saved admin session.
test.use({ storageState: { cookies: [], origins: [] } });

const ENTITY_NONOS = '1';
const ENTITY_CBTL = '2';

function rendersCleanly(bodyText) {
  return !/server error|whoops|exception/i.test(bodyText);
}

async function login(page, email, password, entityId = ENTITY_NONOS) {
  await page.goto('/login');
  await page.selectOption('#entity_id', entityId);
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.click('form button[type=submit], form button:has-text("Log in")');
  // Role landing pages differ per profile — assert we left /login, not a path.
  // The local app runs against the remote test DB, so login can take ~40s.
  await page.waitForURL((url) => !/\/login/.test(url.pathname), { timeout: 120_000 });
}

async function switchEntity(page, entityId) {
  const token = (await page.context().cookies())
    .find((c) => c.name === 'XSRF-TOKEN');
  expect(token, 'XSRF-TOKEN cookie must exist').toBeTruthy();

  const status = await page.evaluate(async ({ entityId, xsrf }) => {
    const res = await fetch('/entity/switch', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(xsrf),
        Accept: 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ entity_id: Number(entityId) }),
    });
    return res.status;
  }, { entityId, xsrf: token.value });

  expect(status, `entity switch to ${entityId} should be accepted`).toBeLessThan(400);
}

test.describe('Work Queue — elevated (admin)', () => {
  test('sees every user\'s import logs, including the stuck pending backlog', async ({ page }) => {
    await login(page, process.env.E2E_ADMIN_EMAIL, process.env.E2E_ADMIN_PASSWORD);

    const res = await page.goto('/work-queue');
    expect(res.status(), '/work-queue must load for admin').toBe(200);

    await expect(page.locator('table')).toBeVisible();

    // The admin branch in ImportLogController skips the `where user_id = me`
    // scoping, so rows owned by OTHER users must be present. The 6 stuck
    // pending rows belong to solutions@mail.com (user 42), not to admin.
    const body = await page.locator('body').innerText();
    expect(body).toContain('SAP Masterlist Query for DAVID');

    // The pending backlog this change is meant to drain must be visible.
    const pendingRows = page.locator('tr', { hasText: /pending/i });
    expect(await pendingRows.count(), 'stuck pending imports should be listed').toBeGreaterThan(0);
  });

  test('entity switch re-scopes the queue and shows a graceful empty state', async ({ page }) => {
    await login(page, process.env.E2E_ADMIN_EMAIL, process.env.E2E_ADMIN_PASSWORD);

    await page.goto('/work-queue');
    const nonosBody = await page.locator('body').innerText();
    expect(nonosBody).toContain('SAP Masterlist Query for DAVID');

    // All 39 import_logs rows belong to entity 1, so entity 2 must come back
    // empty — not stale rows from the previous entity.
    await switchEntity(page, ENTITY_CBTL);
    await page.goto('/work-queue');
    const cbtlBody = await page.locator('body').innerText();
    expect(cbtlBody, 'entity 2 must not leak entity 1 import logs')
      .not.toContain('SAP Masterlist Query for DAVID');
    expect(rendersCleanly(cbtlBody), 'entity 2 should render an empty state, not an error').toBe(true);

    // Reset so the shared test DB is left on Nonos.
    await switchEntity(page, ENTITY_NONOS);
    await page.goto('/work-queue');
    const backBody = await page.locator('body').innerText();
    expect(backBody).toContain('SAP Masterlist Query for DAVID');
  });
});

test.describe('Work Queue — restricted (store representative)', () => {
  test('has no Work Queue nav entry and is forbidden on a direct hit', async ({ page }) => {
    await login(page, process.env.E2E_RESTRICTED_EMAIL, process.env.E2E_RESTRICTED_PASSWORD);

    // Gated-out nav must be ABSENT, not merely disabled.
    await expect(page.locator('nav a[href*="work-queue"]')).toHaveCount(0);
    await expect(page.getByRole('link', { name: /work queue/i })).toHaveCount(0);

    // Direct navigation must be rejected by the `view import logs` middleware.
    const res = await page.goto('/work-queue');
    expect([403, 419], 'restricted user must be forbidden on /work-queue')
      .toContain(res.status());
  });
});
