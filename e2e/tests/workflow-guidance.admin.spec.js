const { test, expect } = require('@playwright/test');

// Read-only smoke coverage. Uses the saved session and never submits transactions.
test('My Actions and contextual business rules render without client errors', async ({ page }) => {
  test.setTimeout(120_000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/my-actions');
  await expect(page.getByRole('heading', { name: 'My Actions', exact: true })).toBeVisible();
  await page.getByRole('button', { name: /All open tasks/ }).click();
  await expect(page.getByText('Where work is waiting', { exact: true })).toBeVisible();
  await page.goto('/wastage');
  await expect(page.getByRole('region', { name: 'Workflow guidance' })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Workflow guidance' }).getByText('Record wastage', { exact: true })).toBeVisible();
  await page.getByText('View the full process', { exact: true }).click();
  await expect(page.getByText(/^Review wastage Level 1.*:$/)).toBeVisible();
  await page.goto('/month-end-count');
  await expect(page.getByRole('region', { name: 'Workflow guidance' })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Workflow guidance' }).getByText('Upload and submit month end count', { exact: true })).toBeVisible();
  expect(errors).toEqual([]);
});

for (const path of ['/mass-orders', '/dts-mass-orders', '/mass-orders-approval', '/cs-mass-commits', '/cs-dts-mass-commits', '/orders-receiving', '/receiving-approvals', '/store-transactions/summary', '/interco', '/interco-approval', '/store-commits', '/interco-receiving', '/wastage-approval-level1', '/wastage-approval-level2', '/month-end-count-approvals', '/month-end-count-approvals-level2']) {
  test(`guidance renders on ${path}`, async ({ page }) => {
    test.setTimeout(90_000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const response = await page.goto(path);
    expect(response.status()).toBe(200);
    await expect(page.getByRole('region', { name: 'Workflow guidance' })).toBeVisible();
    expect(errors).toEqual([]);
  });
}

test('adoption report shows separate completion and timing measures', async ({ page }) => {
  test.setTimeout(120_000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const response = await page.goto('/reports/adoption-rate-tracking?tab=sales_upload_timeliness&date_from=2026-07-01&date_to=2026-07-07');
  expect(response.status()).toBe(200);
  await expect(page.getByText(/Completion:.*On time:.*Outstanding:/)).toBeVisible();
  await expect(page.getByText('Open My Actions and handoff waiting times →')).toBeVisible();
  expect(errors).toEqual([]);
});
