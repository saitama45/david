const { test, expect } = require('@playwright/test');
const path = require('node:path');
const { narrator } = require('../support/narrate');
const { artisan } = require('../support/artisan');

const AUTH = (r) => path.resolve(__dirname, '..', '.auth', `${r}.json`);

/** datetime-local value a few days out at 11:59 PM. */
const untilValue = (days) => {
  const d = new Date();
  d.setDate(d.getDate() + days);
  d.setHours(23, 59, 0, 0);
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
};

test.describe('Month End Count: locked out, then reopened', () => {
  test.skip(process.env.E2E_DESTRUCTIVE !== '1', 'run `npm run qa:mec` (seeds a marked user)');

  test('the store is told to raise a ticket, support reopens, the store can upload', async ({ browser }) => {
    const rep = artisan('seed-mec-user');
    const until = untilValue(3);

    try {
    // ---------- 1. the locked-out store ----------
    const repCtx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const repPage = await repCtx.newPage();
    let qa = narrator(repPage, { total: 14 });

    await repPage.goto('/login');
    await expect(repPage.locator('#entity_id')).toBeVisible({ timeout: 15_000 });
    await qa.say(`Signing in as the store rep for "${rep.branch}" — a branch that never submitted its ${rep.schedule_date} count.`);

    await repPage.selectOption('#entity_id', '1');
    await repPage.fill('#email', rep.email);
    await repPage.fill('#password', rep.password);
    await repPage.click('form button[type=submit], form button:has-text("Log in")');
    await repPage.waitForURL(/\/dashboard/, { timeout: 20_000 });
    await qa.check('Signed in. This rep holds the month end count permissions, so nothing below is a permission problem.');

    await repPage.goto('/month-end-count');
    await qa.warn('The old behaviour stopped here: an empty page saying "No pending month end count actions for your branches" — telling a store that missed its count that it was finished.');

    const notice = repPage.getByTestId('upload-window-notice');
    await expect(notice).toBeVisible();
    const headline = (await notice.locator('p').first().innerText()).trim();
    await qa.check(`Now it explains the state: "${headline}"`);

    await expect(notice).toContainText(rep.branch);
    await expect(notice).toContainText('submit a ticket');
    const address = (await notice.locator('a[href^="mailto:"]').innerText()).trim();
    await qa.check(`It names the branch still missing and tells them to submit a ticket to ${address}.`);

    const rule = (await notice.locator('p').last().innerText()).trim();
    await qa.say(`It also states the rule being enforced: ${rule.replace(/^Current rule:\s*/, '')}`);
    await repPage.screenshot({ path: 'test-results/reopen-1-locked-out.png', fullPage: true });

    // ---------- 2. support reopens, after their approver signed off ----------
    const adminCtx = await browser.newContext({ storageState: AUTH('admin') });
    const adminPage = await adminCtx.newPage();
    qa = narrator(adminPage, { total: 14 });

    await adminPage.goto('/month-end-schedules');
    await qa.say('Now support, who has just had the ticket approved. The reopen lives on the Month End Schedules page.');

    await adminPage.getByTestId('schedule-progress').first().click();
    const dialog = adminPage.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await qa.say('Opening Store Progress for the count in question. Every store and its status is listed here.');

    await dialog.getByPlaceholder('Search for a store...').fill(rep.branch);
    await expect(dialog.getByText(rep.branch, { exact: true })).toBeVisible();
    await qa.say(`Filtering to "${rep.branch}", the store that raised the ticket.`);

    const row = dialog.locator('li').filter({ hasText: rep.branch }).first();
    const box = row.locator('input[type=checkbox]');
    await expect(box).toBeEnabled();
    await qa.warn('Only stores that have NOT submitted can be ticked. A store that already counted is locked out of this list, so a reopen can never let a second count in.');

    await box.check();
    await dialog.locator('#reopen_until').fill(until);
    await qa.say(`Selected, with an explicit deadline of ${until.replace('T', ' ')}. This does not touch the MEC Schedule Date.`);

    await adminPage.screenshot({ path: 'test-results/reopen-2-support-modal.png', fullPage: true });
    await dialog.getByRole('button', { name: /^Reopen/ }).click();

    // Confirmation arrives as a toast carrying the server's own message.
    const toast = adminPage.locator('.p-toast-message').filter({ hasText: /reopened/i }).first();
    await expect(toast).toBeVisible({ timeout: 15_000 });
    const flash = (await toast.innerText()).replace(/\s+/g, ' ').trim();
    await qa.check(`Support gets told exactly what happened: "${flash}"`);

    // And the store row itself now shows the extension.
    await expect(row).toContainText(/Reopened until/i);
    await qa.check('The store row in the list now reads "Reopened until …", so the grant is visible after the toast goes.');

    // ---------- 3. the store can now upload ----------
    await repPage.bringToFront();
    qa = narrator(repPage, { total: 14 });
    await repPage.goto('/month-end-count');
    await qa.say('Back to the same store rep, reloading the page they were locked out of a moment ago.');

    await expect(repPage.getByTestId('upload-window-notice')).toBeHidden();
    await qa.check('The lock-out notice is gone — it only shows while the store is actually blocked.');

    const deadline = repPage.getByTestId('upload-deadline');
    await expect(deadline).toBeVisible();
    await expect(deadline).toContainText('reopened for your branch');
    await qa.check(`The store now sees its new deadline: "${(await deadline.innerText()).trim()}"`);

    await expect(repPage.locator('#file')).toBeVisible();
    await qa.check(`And the upload form is back for "${rep.branch}" — the ticket is resolved without the count date ever being changed.`);

    await repPage.screenshot({ path: 'test-results/reopen-3-store-can-upload.png', fullPage: true });

    await repCtx.close();
    await adminCtx.close();
    } finally {
      // Reopens sit on real schedules/branches, so there is no marker to purge
      // by. Clear this one by name, pass or fail — a leftover reopen would make
      // the next run start in the reopened state and fail confusingly.
      artisan('clear-mec-reopen', `--schedule=${rep.schedule_id}`, `--branch=${rep.branch_id}`);
    }
  });
});
