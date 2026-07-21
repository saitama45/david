const { test, expect } = require('@playwright/test');
const { artisan } = require('../support/artisan');

/**
 * Destructive demo: the full soft-delete → restore lifecycle for a user.
 *
 * Gated on E2E_DESTRUCTIVE (set by `npm run qa:full` / `npm run qa:demo`), which
 * backs up the DB first and purges the marked E2E user afterwards. The user is
 * created through the guarded `david:e2e seed-user` helper so it carries the
 * `e2e-` marker and teardown can clean it up.
 */
test.describe('User lifecycle (destructive)', () => {
  test.skip(process.env.E2E_DESTRUCTIVE !== '1', 'run `npm run qa:demo` or `npm run qa:full`');

  // Accept whichever confirmation dialog is currently open (PrimeVue ConfirmDialog).
  async function acceptDialog(page, buttonName) {
    const dialog = page.locator('.p-confirmdialog, .p-confirm-dialog');
    await dialog.waitFor({ state: 'visible' });
    await dialog.getByRole('button', { name: buttonName }).click();
  }

  async function searchFor(page, term) {
    const box = page.getByPlaceholder('Search...');
    await box.fill('');
    await box.fill(term);
    // Search is debounced (~500ms) and reloads the list server-side.
    await page.waitForTimeout(800);
  }

  test('delete a user then restore it', async ({ page }) => {
    const user = artisan('seed-user'); // { id, email, name }

    // --- 1. It shows on the users list ---
    await page.goto('/users');
    await searchFor(page, user.email);
    const row = page.getByRole('row').filter({ hasText: user.email });
    await expect(row).toHaveCount(1);

    // --- 2. Delete it (soft delete) ---
    await row.getByRole('button').first().click(); // the row's only <button> is Delete
    await acceptDialog(page, 'Confirm');
    await expect(page.getByText('User Deleted Successfully.')).toBeVisible();

    // It drops off the active list.
    await searchFor(page, user.email);
    await expect(page.getByRole('row').filter({ hasText: user.email })).toHaveCount(0);

    // --- 3. It appears under Deleted Users ---
    await page.goto('/users/deleted');
    await searchFor(page, user.email);
    const deletedRow = page.getByRole('row').filter({ hasText: user.email });
    await expect(deletedRow).toHaveCount(1);

    // --- 4. Restore it ---
    await deletedRow.getByRole('button', { name: 'Restore' }).click();
    await acceptDialog(page, 'Restore');
    await expect(page.getByText('User Restored Successfully.')).toBeVisible();

    // --- 5. It is back on the active list ---
    await page.goto('/users');
    await searchFor(page, user.email);
    await expect(page.getByRole('row').filter({ hasText: user.email })).toHaveCount(1);
  });
});
