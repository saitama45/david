// Manual cleanup: run `node purge.js` (or `npm run qa:purge`) if a run was
// interrupted and left E2E fixtures behind.
const path = require('node:path');
require('dotenv').config({ path: path.resolve(__dirname, '.env.e2e') });
const { artisan } = require('./support/artisan');

const res = artisan('purge');
console.log('Purged E2E fixtures:', JSON.stringify(res.purged));
