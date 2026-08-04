const { test, expect } = require('@playwright/test');

/**
 * Ordering Adoption Rate — Ordering Timeliness tab.
 *
 * Covers the two ticket items:
 *   1. F&V listed only the handful of stores with an explicit
 *      "FRUITS AND VEGETABLES" delivery-schedule variant; every store whose F&V
 *      schedule sits under the umbrella "DROPS" variant was invisible.
 *   2. A scheduled date with no order defaulted to "No order" for every
 *      template. GSI-P / PUL-O / F&V require an order on every applicable date,
 *      so those must read "No"; Ice Cream and CPO keep "No order".
 *
 * Read-only: navigates and asserts, never mutates report data.
 *
 * The two non-admin profiles are marked `e2e-` fixtures, so they are not
 * checked in. To run those two tests, provision them once and export their
 * emails (both use the helper's default password, `e2e-password`):
 *
 *   php artisan david:e2e seed-user      # twice
 *   # give one the `store representative` role (has the report permission) and
 *   # the other `OPS-Store Rep` (does not); sync both to store branches 20+15.
 *   E2E_SCOPED_EMAIL=... E2E_GATED_EMAIL=... npm run qa
 *   php artisan david:e2e purge          # afterwards
 *
 * Without those vars the two tests skip. NNRAO (branch 20) is the important
 * one: it is scheduled only under the umbrella DROPS variant, so it could not
 * appear under F&V at all before the fix.
 */

// July 2026 is past the last order in the snapshot, so every scheduled date is
// unfilled — which is exactly the state the "Plotted?" default governs.
const RANGE = 'date_from=2026-07-01&date_to=2026-07-31';
const REPORT = '/reports/adoption-rate-tracking';

const reportUrl = (params = '') => `${REPORT}?tab=ordering_timeliness&${RANGE}${params}`;
const template = (name) => `&ordering_templates[]=${encodeURIComponent(name)}`;

// Ordering Timeliness columns: 1 Week No | 2 Date Range | 3 Supplier Code |
// 4 Store | 5 DAVID Delivery Date | 6 Plotted? | 7 Remarks.
const plottedValues = (page) => page.locator('tbody tr td:nth-child(6)').allInnerTexts();
const storeValues = (page) => page.locator('tbody tr td:nth-child(4)').allInnerTexts();

/** The figure under a summary card's caption ("No", "No Order", ...). */
const totalCard = (page, label) =>
  page
    .locator('p', { hasText: new RegExp(`^${label}$`) })
    .first()
    .locator('xpath=following-sibling::p[1]');

const templateFilter = (page) =>
  page.locator('div.space-y-1').filter({ hasText: 'Ordering Templates' }).locator('.p-multiselect');

async function login(page, email, password) {
  await page.goto('/login');
  await expect(page.locator('#entity_id'), 'not on a guest /login page').toBeVisible();
  await page.selectOption('#entity_id', '1');
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.click('form button[type=submit], form button:has-text("Log in")');
  await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 });
}

test.describe('Ordering Adoption Rate — elevated (admin, all stores)', () => {
  test('DROPS is gone from the Ordering Template filter', async ({ page }) => {
    await page.goto(reportUrl());
    await expect(page.getByRole('heading', { name: 'Adoption Rate Tracking' })).toBeVisible();

    await templateFilter(page).click();
    const options = page.locator('.p-multiselect-overlay .p-multiselect-option');
    await expect(options.first()).toBeVisible();

    const labels = (await options.allInnerTexts()).map((t) => t.trim());
    expect(labels).toContain('F&V');
    expect(labels).toContain('ICE CREAM');
    expect(labels).toContain('GSI-P');
    expect(labels).toContain('PUL-O');
    expect(labels).toContain('CPO');
    expect(labels).not.toContain('DROPS');
    await page.keyboard.press('Escape');
  });

  test('F&V covers every DROPS-scheduled store and defaults unfilled dates to No', async ({ page }) => {
    await page.goto(reportUrl(template('FRUITS AND VEGETABLES')) + '&per_page=200');
    await expect(page.getByRole('heading', { name: 'Adoption Rate Tracking' })).toBeVisible();
    await expect(page.locator('tbody tr').first()).toBeVisible();

    const stores = new Set((await storeValues(page)).map((s) => s.trim()));
    // The stores that only carry the umbrella DROPS variant — invisible before.
    for (const code of ['NNRAO', 'NNRML', 'NNSFW', 'NNUPM', 'NNUTC']) {
      expect([...stores].some((s) => s.includes(code)), `${code} missing under F&V`).toBe(true);
    }
    // Plus the two that already had an explicit F&V variant.
    expect([...stores].some((s) => s.includes('NNFIL'))).toBe(true);
    expect([...stores].some((s) => s.includes('NNOKA'))).toBe(true);

    const plotted = (await plottedValues(page)).map((v) => v.trim());
    expect(plotted.length).toBeGreaterThan(0);
    expect(plotted.every((v) => v === 'No')).toBe(true);
    await expect(totalCard(page, 'No Order')).toHaveText('0');
  });

  test('GSI-P and PUL-O also default unfilled dates to No', async ({ page }) => {
    for (const name of ['GSI-P', 'PUL-O']) {
      await page.goto(reportUrl(template(name)) + '&per_page=200');
      await expect(page.locator('tbody tr').first()).toBeVisible();

      const plotted = (await plottedValues(page)).map((v) => v.trim());
      expect(plotted.length, `${name} produced no rows`).toBeGreaterThan(0);
      expect(plotted.every((v) => v === 'No'), `${name} should read No`).toBe(true);
      await expect(totalCard(page, 'No Order')).toHaveText('0');
    }
  });

  test('Ice Cream and CPO keep the No Order default (regression)', async ({ page }) => {
    for (const name of ['ICE CREAM', 'CPO']) {
      await page.goto(reportUrl(template(name)) + '&per_page=200');
      await expect(page.locator('tbody tr').first()).toBeVisible();

      const plotted = (await plottedValues(page)).map((v) => v.trim());
      expect(plotted.length, `${name} produced no rows`).toBeGreaterThan(0);
      expect(plotted.every((v) => v === 'No order'), `${name} should keep No order`).toBe(true);
      // Unfilled dates must land in "No Order", never in the missed-order "No".
      await expect(totalCard(page, 'No')).toHaveText('0');
      await expect(totalCard(page, 'No Order')).not.toHaveText('0');
    }
  });
});

test.describe('Ordering Adoption Rate — restricted profiles', () => {
  const scoped = { email: process.env.E2E_SCOPED_EMAIL, password: 'e2e-password' };
  const gated = { email: process.env.E2E_GATED_EMAIL, password: 'e2e-password' };

  test('store-scoped user sees only their branches, incl. a DROPS-only store', async ({ browser }) => {
    test.skip(!scoped.email, 'E2E_SCOPED_EMAIL not set');
    test.setTimeout(90_000);
    // Explicitly empty — this project's `use.storageState` is the admin session,
    // and an authenticated context gets bounced off /login by the guest guard.
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    await login(page, scoped.email, scoped.password);

    await page.goto(reportUrl(template('FRUITS AND VEGETABLES')) + '&per_page=200');
    await expect(page.getByRole('heading', { name: 'Adoption Rate Tracking' })).toBeVisible();
    await expect(page.locator('tbody tr').first()).toBeVisible();

    const stores = new Set((await storeValues(page)).map((s) => s.trim()));
    // Scoped to NNRAO + NNOKA and nothing else — the admin run showed 7 stores.
    expect(stores.size).toBe(2);
    expect([...stores].some((s) => s.includes('NNRAO')), 'DROPS-only store missing').toBe(true);
    expect([...stores].some((s) => s.includes('NNOKA'))).toBe(true);
    expect([...stores].some((s) => s.includes('NNFIL')), 'leaked an unassigned store').toBe(false);

    const plotted = (await plottedValues(page)).map((v) => v.trim());
    expect(plotted.every((v) => v === 'No')).toBe(true);

    await context.close();
  });

  test('user without the report permission is denied', async ({ browser }) => {
    test.skip(!gated.email, 'E2E_GATED_EMAIL not set');
    test.setTimeout(90_000);
    // Explicitly empty — this project's `use.storageState` is the admin session,
    // and an authenticated context gets bounced off /login by the guest guard.
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    await login(page, gated.email, gated.password);

    // Sidebar must not offer the report.
    await expect(page.getByRole('link', { name: /Adoption Rate Tracking/i })).toHaveCount(0);

    // And the route itself rejects a direct hit.
    const response = await page.goto(REPORT);
    expect([403, 419]).toContain(response.status());

    await context.close();
  });
});
