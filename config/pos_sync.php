<?php

return [
    // This is Azure database-to-database processing, not store replication.
    // Runs on deployment by default; the optional override is an operational stop.
    'enabled' => env('POS_SYNC_ENABLED', true),
    'connection' => env('POS_SYNC_CONNECTION', env('DB_CONNECTION', 'sqlsrv')),
    'lookback_days' => (int) env('POS_SYNC_LOOKBACK_DAYS', 7),
    'settle_seconds' => (int) env('POS_SYNC_SETTLE_SECONDS', 5),

    // The report formulas are implemented in PosReceiptMapper and verified
    // against imported sales. Profiles only map source stores to app ownership.
    // Ship the mappings with the app; Azure does not need a profiles environment variable.
    'profiles' => json_decode(env('POS_SYNC_PROFILES', file_get_contents(__DIR__.'/pos_sync_profiles.json')), true) ?: [],
];
