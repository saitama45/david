/**
 * Narrated Month End Count window demo.
 *
 * Runs auth setup unfiltered first (a filename filter would exclude
 * auth.setup.js and leave the admin session stale), then the narrated spec
 * headed so it can be watched.
 */
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const cli = path.resolve(__dirname, 'node_modules', '@playwright', 'test', 'cli.js');
const run = (args) => execFileSync(process.execPath, [cli, ...args], { cwd: __dirname, stdio: 'inherit' });

run(['test', '--project=setup']);
run(['test', '--project=workflow', '--headed', 'month-end-reopen.workflow']);
