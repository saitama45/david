const { test, expect } = require('@playwright/test');
const path = require('node:path');
const { narrator } = require('../support/narrate');
const { artisan } = require('../support/artisan');

const AUTH = (r) => path.resolve(__dirname, '..', '.auth', `${r}.json`);

/**
 * The Month End Count page used to render nothing at all once the upload
 * window shut — the store was told "No pending month end count actions for
 * your branches", which reads as "you are finished" rather than "you are
 * locked out". This drives the new notice that explains the rule instead.
 *
 * Seeds one marked store rep who still owes the last count, so the closed
 * state is reachable; global-teardown purges it.
 */
test.describe('Month End Count upload window explains itself', () => {
  test.skip(process.env.E2E_DESTRUCTIVE !== '1', 'run `npm run qa:mec` (seeds a marked user)');

  test('a locked-out store is told why, and who to contact', async ({ browser }) => {
    const rep = artisan('seed-mec-user');

    // Explicitly blank session — the workflow project carries an admin storageState,
    // and inheriting it would land us on the dashboard instead of the login form.
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await ctx.newPage();
    const qa = narrator(page, { total: 9 });

    await page.goto('/login');
    await expect(page.locator('#entity_id')).toBeVisible({ timeout: 15_000 });
    await qa.say(`Signing in as a store rep for "${rep.branch}" — a branch that never submitted its ${rep.schedule_date} count.`);

    await page.selectOption('#entity_id', '1');
    await page.fill('#email', rep.email);
    await page.fill('#password', rep.password);
    await page.click('form button[type=submit], form button:has-text("Log in")');
    await page.waitForURL(/\/dashboard/, { timeout: 20_000 });
    await qa.check('Signed in. This user holds the month end count permissions, so nothing below is a permission problem.');

    await page.goto('/month-end-count');
    await qa.say('Opening Month End Count. The deadline for this count passed days ago, so the upload form is correctly hidden.');

    await qa.warn('This is the bug: the page used to stop here, saying "No pending month end count actions for your branches" — telling a store that had missed its count that it was finished.');

    const notice = page.getByTestId('upload-window-notice');
    await expect(notice).toBeVisible();
    await qa.check('Instead the page now shows a notice explaining the state rather than an empty panel.');

    await expect(notice).toContainText('upload window');
    await expect(notice).toContainText('has closed');
    const headline = (await notice.locator('p').first().innerText()).trim();
    await qa.check(`It names the period and the outcome: "${headline}"`);

    await expect(notice).toContainText(rep.branch);
    await qa.check(`It names the branch still missing: "${rep.branch}" — so the rep knows exactly what is outstanding.`);

    const mailto = notice.locator('a[href^="mailto:"]');
    await expect(mailto).toBeVisible();
    const address = (await mailto.innerText()).trim();
    const href = await mailto.getAttribute('href');
    expect(href).toContain('Request to reopen Month End Count');
    await expect(notice).toContainText("submit a ticket");
    await qa.check(`And it gives a way out: submit a ticket to ${address}, pre-filled with the period so support can reopen it.`);

    const rule = (await notice.locator('p').last().innerText()).trim();
    await qa.check(`Finally it states the rule being enforced, read from this entity's own settings: ${rule.replace(/^Current rule:\s*/, '')}`);

    await page.screenshot({ path: 'test-results/mec-window-closed.png', fullPage: true });
    await ctx.close();
  });

  test('a store with nothing outstanding is told that, not left blank', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: AUTH('admin') });
    const page = await ctx.newPage();
    const qa = narrator(page, { total: 3 });

    await page.goto('/month-end-count');
    await qa.say('Now the same page for an account whose branches all submitted on time.');

    const notice = page.getByTestId('upload-window-notice');
    await expect(notice).toBeVisible();
    const headline = (await notice.locator('p').first().innerText()).trim();
    await qa.check(`Different state, still explained rather than blank: "${headline}"`);

    await expect(notice).not.toContainText('mailto');
    await qa.check('No support contact is offered here, because nothing is blocked — the message matches the situation.');

    await page.screenshot({ path: 'test-results/mec-window-complete.png', fullPage: true });
    await ctx.close();
  });
});
