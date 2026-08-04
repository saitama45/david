const { test, expect } = require('@playwright/test');

/**
 * Adoption Rate Tracking — Sales Upload Timeliness tab.
 *
 * Covers the column split:
 *   1. "Sales Report Uploaded?" renamed to "Sales Report Uploaded On Time?"
 *      (same acceptance-criteria formula, still the column the summary cards and
 *      the Overall Adoption Rate tab measure).
 *   2. New "Sales Report Uploaded?" column — Yes/No purely from whether a
 *      "Date of Actual Sales Upload" exists, regardless of timing.
 *
 * Read-only: navigates and asserts, never mutates report data.
 *
 * The restricted profile is an `e2e-` fixture and is not checked in. Provision
 * it once and export its email (default password `e2e-password`):
 *
 *   php artisan david:e2e seed-user   # then give it `store representative`
 *                                     # and sync it to store branches 20+15
 *   E2E_SCOPED_EMAIL=... npm run qa
 *   php artisan david:e2e purge       # afterwards
 *
 * Without the var that test skips.
 */

// April 2026 in the snapshot has both on-time and late uploads plus days with
// no upload at all — the three states the two columns must tell apart.
const RANGE = 'date_from=2026-04-01&date_to=2026-04-30';
const REPORT = '/reports/adoption-rate-tracking';
const reportUrl = (params = '') => `${REPORT}?tab=sales_upload_timeliness&${RANGE}${params}&per_page=200`;

// Sales Upload columns: 1 Week No | 2 Date Range | 3 Date of Sales |
// 4 Date of Actual Sales Upload | 5 Store | 6 Uploaded On Time? | 7 Uploaded? |
// 8 Remarks.
const columnTexts = async (page, nth) =>
  (await page.locator(`tbody tr td:nth-child(${nth})`).allInnerTexts()).map((t) => t.trim());

// The header row is CSS-uppercased, so innerText comes back shouting.
const headerTexts = async (page) =>
  (await page.locator('thead th').allInnerTexts()).map((t) => t.trim().toUpperCase());

/** The figure under a summary card's caption ("Yes", "No", ...). */
const totalCard = (page, label) =>
  page
    .locator('p', { hasText: new RegExp(`^${label}$`) })
    .first()
    .locator('xpath=following-sibling::p[1]');

async function login(page, email, password) {
  await page.goto('/login');
  await expect(page.locator('#entity_id'), 'not on a guest /login page').toBeVisible();
  await page.selectOption('#entity_id', '1');
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.click('form button[type=submit], form button:has-text("Log in")');
  await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 });
}

test.describe('Sales Upload Timeliness — elevated (admin, all stores)', () => {
  test('renders both columns, renamed on-time first', async ({ page }) => {
    await page.goto(reportUrl());
    await expect(page.getByRole('heading', { name: 'Adoption Rate Tracking' })).toBeVisible();
    await expect(page.locator('tbody tr').first()).toBeVisible();

    expect(await headerTexts(page)).toEqual([
      'WEEK NO.',
      'DATE RANGE',
      'DATE OF SALES',
      'DATE OF ACTUAL SALES UPLOAD',
      'STORE',
      'SALES REPORT UPLOADED ON TIME?',
      'SALES REPORT UPLOADED?',
      'REMARKS',
    ]);
  });

  test('Uploaded? follows the upload date alone; on-time keeps its formula', async ({ page }) => {
    await page.goto(reportUrl());
    await expect(page.locator('tbody tr').first()).toBeVisible();

    const uploadDates = await columnTexts(page, 4);
    const onTime = await columnTexts(page, 6);
    const uploaded = await columnTexts(page, 7);

    expect(uploadDates.length).toBeGreaterThan(0);
    expect(onTime).toHaveLength(uploadDates.length);
    expect(uploaded).toHaveLength(uploadDates.length);

    uploadDates.forEach((date, i) => {
      expect(uploaded[i], `row ${i} (upload date "${date}") uploaded-at-all`).toBe(date ? 'Yes' : 'No');
      // On time is a strict subset: never Yes without an upload behind it.
      if (onTime[i] === 'Yes') {
        expect(uploaded[i], `row ${i} on time but not uploaded`).toBe('Yes');
      }
    });

    // The point of the split: at least one late upload — uploaded Yes, on time No.
    const late = uploadDates.some((date, i) => date && onTime[i] === 'No' && uploaded[i] === 'Yes');
    expect(late, 'no late-but-uploaded row in range; pick a range that has one').toBe(true);
    // And at least one day with no upload at all, so both columns can read No.
    expect(uploadDates.some((d) => d === '')).toBe(true);
  });

  test('summary cards still measure the on-time column', async ({ page }) => {
    // One store, so every filtered row fits on the page and the cards (which
    // count the whole filtered set, not the page) can be checked against them.
    await page.goto(reportUrl('&store_ids[]=15'));
    await expect(page.locator('tbody tr').first()).toBeVisible();

    const onTime = await columnTexts(page, 6);
    const yes = onTime.filter((v) => v === 'Yes').length;
    const no = onTime.filter((v) => v === 'No').length;

    await expect(totalCard(page, 'Total Days')).toHaveText(String(onTime.length));
    await expect(totalCard(page, 'Yes')).toHaveText(String(yes));
    await expect(totalCard(page, 'No')).toHaveText(String(no));
    await expect(totalCard(page, 'Adoption Rate')).toHaveText(
      `${(Math.round((yes / onTime.length) * 10000) / 100).toFixed(2)}%`
    );
  });

  test('Overall Adoption Rate still scores sales uploads off the on-time column', async ({ page }) => {
    await page.goto(reportUrl('&store_ids[]=15'));
    await expect(page.locator('tbody tr').first()).toBeVisible();
    const salesRate = (await totalCard(page, 'Adoption Rate').innerText()).trim();

    await page.goto(`${REPORT}?tab=overall_adoption_rate&${RANGE}&store_ids[]=15`);
    const row = page.locator('tbody tr', { hasText: 'Timeliness of Sales Uploading' }).first();
    await expect(row).toBeVisible();
    // Last cell is the Overall column — must match the tab's own adoption rate,
    // i.e. it still reads the renamed on-time key rather than a missing one.
    await expect(row.locator('td').last()).toHaveText(salesRate);
  });

  test('Export to Excel still builds with the extra column', async ({ page }) => {
    await page.goto(reportUrl('&store_ids[]=15'));
    await expect(page.locator('tbody tr').first()).toBeVisible();

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: /Export to Excel/i }).click(),
    ]);
    expect(await download.failure()).toBeNull();
    expect(download.suggestedFilename()).toMatch(/\.xlsx$/);
  });

  test('search still matches on both status columns', async ({ page }) => {
    await page.goto(reportUrl('&search=yes'));
    await expect(page.locator('tbody tr').first()).toBeVisible();

    const onTime = await columnTexts(page, 6);
    const uploaded = await columnTexts(page, 7);
    expect(onTime.length).toBeGreaterThan(0);
    // Every surviving row says Yes in at least one of the two columns.
    onTime.forEach((v, i) => expect(v === 'Yes' || uploaded[i] === 'Yes').toBe(true));
    // And rows that are only "uploaded, but late" are kept by the new column.
    expect(onTime.some((v, i) => v === 'No' && uploaded[i] === 'Yes')).toBe(true);
  });
});

test.describe('Sales Upload Timeliness — restricted profile', () => {
  const scoped = { email: process.env.E2E_SCOPED_EMAIL, password: 'e2e-password' };

  test('store-scoped user sees only their branches, with both columns intact', async ({ browser }) => {
    test.skip(!scoped.email, 'E2E_SCOPED_EMAIL not set');
    test.setTimeout(90_000);
    // Explicitly empty — this project's `use.storageState` is the admin session,
    // and an authenticated context gets bounced off /login by the guest guard.
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    await login(page, scoped.email, scoped.password);

    await page.goto(reportUrl());
    await expect(page.getByRole('heading', { name: 'Adoption Rate Tracking' })).toBeVisible();
    await expect(page.locator('tbody tr').first()).toBeVisible();

    const headers = await headerTexts(page);
    expect(headers).toContain('SALES REPORT UPLOADED ON TIME?');
    expect(headers).toContain('SALES REPORT UPLOADED?');

    const stores = new Set(await columnTexts(page, 5));
    expect(stores.size, 'store scoping leaked').toBe(2);

    const uploadDates = await columnTexts(page, 4);
    const uploaded = await columnTexts(page, 7);
    uploadDates.forEach((date, i) => expect(uploaded[i]).toBe(date ? 'Yes' : 'No'));

    await context.close();
  });
});
