const path = require('node:path');
require('dotenv').config({ path: path.resolve(__dirname, '.env.e2e') });
const { backupDb } = require('./support/backup');

/**
 * Runs once before the whole suite. When the destructive workflow tests are
 * enabled (E2E_DESTRUCTIVE=1, set by `npm run qa:full` / `npm run qa:demo`), it
 * takes a fresh DB backup first — a hard safety gate. Read-only runs skip it
 * (nothing to protect).
 */
module.exports = async () => {
  if (process.env.E2E_DESTRUCTIVE === '1') {
    console.log('[e2e] Destructive run — backing up the database first...');
    backupDb();
    console.log('[e2e] Backup complete.');
  }
};
