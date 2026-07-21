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
    protected $signature = 'david:e2e {action : seed-user|purge}';

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
