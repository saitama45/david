<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prevents `php artisan migrate` from failing on migrations whose objects
 * already exist in the database but were never recorded in the `migrations`
 * table (an out-of-sync migration log, e.g. when a schema was imported rather
 * than built migration-by-migration).
 *
 * For each known (migration => sentinel table) pair: if the table already
 * exists but the migration is not recorded as run, record it so `migrate`
 * skips it. Completely idempotent and a no-op on a healthy log.
 */
class ReconcileMigrationsLog extends Command
{
    protected $signature = 'migrations:reconcile';

    protected $description = 'Mark already-applied-but-unrecorded migrations as run so migrate cannot fail on them.';

    /**
     * migration name (filename without .php) => a table that proves it ran.
     */
    protected array $knownApplied = [
        '2024_11_16_091533_create_permission_tables' => 'permissions',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('migrations')) {
            $this->info('No migrations table yet; nothing to reconcile.');
            return self::SUCCESS;
        }

        $batch = (int) DB::table('migrations')->max('batch') ?: 1;
        $reconciled = 0;

        foreach ($this->knownApplied as $migration => $sentinelTable) {
            $alreadyRecorded = DB::table('migrations')->where('migration', $migration)->exists();

            if (! $alreadyRecorded && Schema::hasTable($sentinelTable)) {
                DB::table('migrations')->insert([
                    'migration' => $migration,
                    'batch' => $batch,
                ]);
                $this->info("Reconciled: marked {$migration} as run (table '{$sentinelTable}' already exists).");
                $reconciled++;
            }
        }

        $this->info($reconciled === 0
            ? 'Migration log is consistent; nothing to reconcile.'
            : "Reconciled {$reconciled} migration record(s).");

        return self::SUCCESS;
    }
}
