const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const AUTH_DIR = path.resolve(__dirname, '..', '.auth');

/**
 * Logs in once as admin and saves the session so every other test starts
 * already authenticated. Runs before the admin/workflow projects (see config).
 *
 * DAVID's login form requires selecting an Entity (a native <select id="entity_id">)
 * in addition to email/password. Set E2E_ENTITY_ID in .env.e2e to pin a specific
 * entity the admin can access; otherwise the first real option is chosen.
 */
test('authenticate as admin', async ({ page }) => {
  const email = process.env.E2E_ADMIN_EMAIL;
  const password = process.env.E2E_ADMIN_PASSWORD;
  expect(email, 'E2E_ADMIN_EMAIL must be set in .env.e2e').toBeTruthy();
  expect(password, 'E2E_ADMIN_PASSWORD must be set in .env.e2e').toBeTruthy();

  await page.goto('/login');

  // Entity picker (option 0 is the disabled "Select an entity" placeholder).
  if (process.env.E2E_ENTITY_ID) {
    await page.selectOption('#entity_id', process.env.E2E_ENTITY_ID);
  } else {
    await page.selectOption('#entity_id', { index: 1 });
  }

  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.click('form button[type=submit], form button:has-text("Log in")');

  await page.waitForURL(/\/dashboard/, { timeout: 15_000 });

  fs.mkdirSync(AUTH_DIR, { recursive: true });
  await page.context().storageState({ path: path.join(AUTH_DIR, 'admin.json') });
});
