const { execFileSync } = require('node:child_process');
const path = require('node:path');

const APP_ROOT = path.resolve(__dirname, '..', '..');

/**
 * Runs `php artisan david:e2e <action>` in the app and returns the JSON the
 * command prints on its last line. e.g. artisan('seed-user').
 */
function artisan(...args) {
  const out = execFileSync('php', ['artisan', 'david:e2e', ...args], {
    cwd: APP_ROOT,
    encoding: 'utf8',
  });
  const line = out.trim().split('\n').filter(Boolean).pop();
  try {
    return JSON.parse(line);
  } catch {
    throw new Error(`david:e2e ${args.join(' ')} did not return JSON:\n${out}`);
  }
}

module.exports = { artisan, APP_ROOT };
