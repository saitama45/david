const { execFileSync } = require('node:child_process');

/**
 * Full SQL Server .bak of the app database. Runs before any destructive test so
 * there is always a restore point. Uses a rolling filename (WITH INIT overwrites)
 * to avoid filling the disk — the previous run's data is recreated-then-purged
 * anyway, so one "state right before this run" backup is the safety net we need.
 *
 * All settings come from .env.e2e so this file carries no credentials.
 */
function backupDb() {
  const server = process.env.E2E_DB_SERVER || '127.0.0.1';
  const user = process.env.E2E_DB_USER || 'sa';
  const pass = process.env.E2E_DB_PASSWORD;
  const db = process.env.E2E_DB_NAME;
  const disk = process.env.E2E_DB_BACKUP_PATH;

  if (!db || !disk || !pass) {
    throw new Error(
      'DB backup is required before destructive tests but is not configured. ' +
      'Set E2E_DB_NAME, E2E_DB_PASSWORD and E2E_DB_BACKUP_PATH in .env.e2e.',
    );
  }

  execFileSync(
    'sqlcmd',
    ['-S', server, '-U', user, '-P', pass, '-C', '-b', '-Q',
      `BACKUP DATABASE [${db}] TO DISK = N'${disk}' WITH INIT, NAME = N'pre-e2e ${db}'`],
    { stdio: 'inherit' },
  );
}

module.exports = { backupDb };
