const path = require('node:path');
require('dotenv').config({ path: path.resolve(__dirname, '.env.e2e') });
const { artisan } = require('./support/artisan');

/**
 * Runs once after the whole suite. On a destructive run it purges every marked
 * E2E fixture, so the shared local DB is left clean regardless of pass/fail.
 */
module.exports = async () => {
  if (process.env.E2E_DESTRUCTIVE === '1') {
    const res = artisan('purge');
    console.log('[e2e] Purged E2E fixtures:', JSON.stringify(res.purged));
  }
};
