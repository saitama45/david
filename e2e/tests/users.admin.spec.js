const { test, expect } = require('@playwright/test');

/**
 * Read-only smoke over the Users module. Navigates the pages and asserts on
 * stable, visible headings/controls — never creates or mutates data, so it's
 * safe to run anywhere (the default `npm run qa`).
 */
test.describe('Users module (read-only)', () => {
  test('users index lists users and links to deleted', async ({ page }) => {
    await page.goto('/users');

    await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
    await expect(page.getByPlaceholder('Search...')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Deleted Users' })).toBeVisible();
  });

  test('deleted users page loads', async ({ page }) => {
    await page.goto('/users/deleted');

    await expect(page.getByRole('heading', { name: 'Deleted Users' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Back to Users' })).toBeVisible();
  });
});
