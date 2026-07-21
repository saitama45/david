const path = require('path');
require('dotenv').config({ path: path.resolve(__dirname, '.env.e2e') });
const { defineConfig, devices } = require('@playwright/test');

const BASE_URL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8000';

/**
 * DAVID QA automation.
 *
 * Run modes:
 *   npm run qa          → headless, fast, HTML report (CI / pre-deploy regression)
 *   npm run qa:watch    → headed + slow, so you can watch it click through
 *   npm run qa:demo     → headed + slow, runs the destructive user-lifecycle demo
 *   npm run qa:full     → full regression incl. destructive (backs up first, purges after)
 *   npm run qa:ui       → Playwright's time-travel debugger
 *   npm run qa:codegen  → record a new test by clicking the app
 *
 * Auth: the `setup` project logs in once as admin and saves the session to
 * .auth/. The admin project reuses that state so every test starts already
 * signed in. DAVID's login requires selecting an Entity as well as email/password.
 */
module.exports = defineConfig({
  testDir: './tests',
  // Shared app + shared DB: run serially so tests never race each other.
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  // Slow-motion demo runs (SLOWMO set) add a delay to every action, so give
  // them generous headroom; headless runs stay tight.
  timeout: Number(process.env.SLOWMO) ? 90_000 : 30_000,
  expect: { timeout: Number(process.env.SLOWMO) ? 20_000 : 8_000 },
  reporter: [['html', { open: 'never' }], ['list']],
  // Backs up the DB before destructive runs; purges E2E fixtures after.
  globalSetup: require.resolve('./global-setup.js'),
  globalTeardown: require.resolve('./global-teardown.js'),
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    launchOptions: { slowMo: Number(process.env.SLOWMO) || 0 },
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.js/ },
    {
      name: 'admin',
      testMatch: /.*\.admin\.spec\.js/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: path.resolve(__dirname, '.auth/admin.json') },
    },
    {
      // Destructive workflow tests. Create/mutate real rows, so they only run on
      // `npm run qa:full` / `npm run qa:demo` (E2E_DESTRUCTIVE=1) — which backs up
      // first and purges after. They reuse the saved admin session.
      name: 'workflow',
      testMatch: /.*\.workflow\.spec\.js/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: path.resolve(__dirname, '.auth/admin.json') },
    },
  ],
});
