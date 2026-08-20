<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Test-support for the browser QA suite (e2e/). Every user it creates carries the
 * `e2e-` marker on the email, and `purge` only ever deletes users with that
 * marker — so it can run against the local dev database without disturbing real
 * data. Refuses to run in production.
 *
 * Output is JSON on stdout so the Playwright helper can parse ids/emails.
 */
class E2eSupport extends Command
{
    protected $signature = 'david:e2e {action : seed-user|seed-mec-user|clear-mec-reopen|purge} {--schedule=} {--branch=}';

    protected $description = 'Create / clean up marked fixtures for the browser QA suite (non-production only)';

    private const MARK = 'e2e-';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('david:e2e is disabled in production.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'seed-user' => $this->seedUser(),
            'seed-mec-user' => $this->seedMecUser(),
            'clear-mec-reopen' => $this->clearMecReopen(),
            'purge' => $this->purge(),
            default => $this->errorOut('Unknown action. Use seed-user|purge.'),
        };
    }

    /** Create one marked, deletable user and print its id/email/name as JSON. */
    private function seedUser(): int
    {
        $suffix = strtolower(Str::random(8));

        $user = User::create([
            'first_name' => 'E2E',
            'last_name' => 'User '.strtoupper($suffix),
            'email' => self::MARK.$suffix.'@example.com',
            'phone_number' => '0000000000',
            'password' => Hash::make('e2e-password'),
            'is_active' => true,
        ]);

        return $this->emit([
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->first_name.' '.$user->last_name,
        ]);
    }

    /**
     * Create a marked store representative who still owes a month end count, so
     * the Month End Count page can be exercised in its "window closed" state.
     * The user gets the OPS-Store Rep role (which carries the month end count
     * permissions and grants entity 1) and is assigned one branch that has no
     * count item for the most recent past schedule.
     */
    private function seedMecUser(): int
    {
        $suffix = strtolower(Str::random(8));

        $schedule = \App\Models\MonthEndSchedule::withoutGlobalScopes()
            ->where('entity_id', 1)
            ->where('calculated_date', '<', now())
            ->orderByDesc('calculated_date')
            ->first();

        if (! $schedule) {
            return $this->errorOut('No past month end schedule to seed against.');
        }

        $submitted = \App\Models\MonthEndCountItem::withoutGlobalScopes()
            ->where('month_end_schedule_id', $schedule->id)
            ->distinct()
            ->pluck('branch_id');

        // Must be an active branch: the Store Progress modal (where support
        // grants reopens) only lists active stores, so an inactive one could
        // never be reopened through the UI.
        $branch = \App\Models\StoreBranch::withoutGlobalScopes()
            ->where('entity_id', 1)
            ->where('is_active', true)
            ->whereNotIn('id', $submitted)
            ->orderBy('id')
            ->first();

        if (! $branch) {
            return $this->errorOut('Every branch already submitted; nothing to seed.');
        }

        $user = User::create([
            'first_name' => 'E2E',
            'last_name' => 'Store Rep '.strtoupper($suffix),
            'email' => self::MARK.$suffix.'@example.com',
            'phone_number' => '0000000000',
            'password' => Hash::make('e2e-password'),
            'is_active' => true,
            'last_entity_id' => 1,
        ]);

        $user->assignRole('OPS-Store Rep');
        $user->store_branches()->attach($branch->id);

        return $this->emit([
            'id' => $user->id,
            'email' => $user->email,
            'password' => 'e2e-password',
            'branch' => $branch->name,
            'branch_id' => $branch->id,
            'schedule_id' => $schedule->id,
            'schedule_date' => $schedule->calculated_date->toDateString(),
        ]);
    }

    /**
     * Remove one reopen row created during a demo run.
     *
     * Reopens are keyed on real schedules and branches, so there is no `e2e-`
     * marker to filter on — the exact schedule and branch must be named, and
     * nothing else is ever touched.
     */
    private function clearMecReopen(): int
    {
        $scheduleId = $this->option('schedule');
        $branchId = $this->option('branch');

        if (! $scheduleId || ! $branchId) {
            return $this->errorOut('clear-mec-reopen requires --schedule and --branch.');
        }

        $deleted = \App\Models\MonthEndCountReopen::withoutGlobalScopes()
            ->where('month_end_schedule_id', $scheduleId)
            ->where('branch_id', $branchId)
            ->delete();

        return $this->emit(['cleared' => ['reopens' => $deleted]]);
    }

    /**
     * Hard-delete every marked E2E user, including soft-deleted ones. Pivots are
     * detached first so forceDelete never trips a foreign key. Only touches rows
     * whose email carries the `e2e-` marker.
     */
    private function purge(): int
    {
        $users = User::withTrashed()
            ->where('email', 'like', self::MARK.'%')
            ->get();

        $count = 0;
        foreach ($users as $user) {
            $user->store_branches()->detach();
            $user->suppliers()->detach();
            try {
                $user->roles()->detach();
            } catch (\Throwable $e) {
                // ignore — marked test users have no roles
            }
            $user->forceDelete();
            $count++;
        }

        return $this->emit(['purged' => ['users' => $count]]);
    }

    private function emit(array $data): int
    {
        $this->line(json_encode($data));

        return self::SUCCESS;
    }

    private function errorOut(string $msg): int
    {
        $this->error($msg);

        return self::FAILURE;
    }
}
