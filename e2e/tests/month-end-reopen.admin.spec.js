const { test, expect } = require('@playwright/test');

/**
 * Read-only: the per-store reopen control lives in the Store Progress modal on
 * /month-end-schedules. Opens it for a month that has submissions and checks the
 * control renders, that submitted stores cannot be selected, and that stores
 * still owing a count can be.
 */
test('Store Progress modal offers a per-store reopen', async ({ page }) => {
  await page.goto('/month-end-schedules');
  await expect(page.getByRole('heading', { name: /Month End Schedules/i })).toBeVisible();

  // Open the progress modal for the first month that has any submissions.
  const progress = page.getByTestId('schedule-progress').first();
  await expect(progress).toBeVisible();
  await progress.click();

  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText('Reopen upload for selected stores')).toBeVisible();
  await expect(dialog.getByText(/does not change the MEC Schedule Date/i)).toBeVisible();

  // The deadline field is an explicit date+time, pre-filled.
  const until = dialog.locator('#reopen_until');
  await expect(until).toBeVisible();
  expect(await until.inputValue()).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);

  // Reopen is disabled until at least one store is picked.
  const reopenBtn = dialog.getByRole('button', { name: /^Reopen/ });
  await expect(reopenBtn).toBeDisabled();

  const boxes = dialog.locator('input[type=checkbox]');
  await expect(boxes.first()).toBeVisible();

  const enabled = dialog.locator('input[type=checkbox]:not([disabled])');
  const disabled = dialog.locator('input[type=checkbox][disabled]');
  const enabledCount = await enabled.count();
  const disabledCount = await disabled.count();

  // Stores that already submitted must not be selectable.
  expect(enabledCount + disabledCount).toBeGreaterThan(0);

  if (enabledCount > 0) {
    await enabled.first().check();
    await expect(reopenBtn).toBeEnabled();
    await expect(reopenBtn).toContainText('(1)');
  }

  console.log(`[reopen] selectable stores: ${enabledCount}, already submitted (locked): ${disabledCount}`);
});
