<?php

namespace App\Http\Services;

use App\Mail\CommitVarianceMail;
use App\Models\Setting;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * The no-reply mailbox the system sends its notification emails from (Administration > Email
 * Notifications), and the emails it sends. The SMTP account lives in the `settings` table, its
 * password encrypted, and the mailer is built from it at send time, so nothing in .env changes.
 *
 * Only notification so far: the store is told which lines CS Mass Commits committed at a quantity
 * other than the one it ordered (`sendCommitVariance()`), at the store's emails in Store Branches.
 */
class NotificationMailService
{
    public const DRIVER_SMTP = 'smtp';

    /** Writes the email to the log instead of sending it: for trying the feature out. */
    public const DRIVER_LOG = 'log';

    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /** Settings keys, all under one prefix. */
    private const KEYS = [
        'from_name' => 'notification_mail.from_name',
        'from_address' => 'notification_mail.from_address',
        'driver' => 'notification_mail.driver',
        'host' => 'notification_mail.host',
        'port' => 'notification_mail.port',
        'encryption' => 'notification_mail.encryption',
        'username' => 'notification_mail.username',
        'password' => 'notification_mail.password',
        'commit_variance_enabled' => 'notification_mail.commit_variance_enabled',
    ];

    /**
     * The Store Branches fields that hold the store's emails ("Head Chef Email" and so on). Each may
     * hold several addresses, separated by commas or semicolons.
     */
    public const BRANCH_EMAIL_FIELDS = [
        'head_chef' => 'Head Chef Email',
        'director_operations' => 'Director of Operations Email',
        'vp_operations' => 'VP of Operations Email',
        'aom' => 'AOM Email',
    ];

    /** The settings for the page. The password itself never leaves the server. */
    public function settings(): array
    {
        return [
            'from_name' => (string) Setting::get(self::KEYS['from_name'], 'DAVID Notifications (no-reply)'),
            'from_address' => (string) Setting::get(self::KEYS['from_address'], ''),
            'driver' => (string) Setting::get(self::KEYS['driver'], self::DRIVER_SMTP),
            'host' => (string) Setting::get(self::KEYS['host'], ''),
            'port' => (int) Setting::get(self::KEYS['port'], 587),
            'encryption' => (string) Setting::get(self::KEYS['encryption'], 'tls'),
            'username' => (string) Setting::get(self::KEYS['username'], ''),
            'has_password' => Setting::get(self::KEYS['password']) !== null && Setting::get(self::KEYS['password']) !== '',
            'commit_variance_enabled' => (bool) Setting::get(self::KEYS['commit_variance_enabled'], false),
        ];
    }

    /**
     * Save the page. A blank password keeps the one saved before.
     *
     * @param  array{from_name: string, from_address: string, driver: string, host: ?string, port: ?int, encryption: string, username: ?string, password: ?string, commit_variance_enabled: bool}  $data
     */
    public function save(array $data): void
    {
        Setting::set(self::KEYS['from_name'], trim($data['from_name']));
        Setting::set(self::KEYS['from_address'], trim($data['from_address']));
        Setting::set(self::KEYS['driver'], $data['driver']);
        Setting::set(self::KEYS['host'], trim((string) ($data['host'] ?? '')));
        Setting::set(self::KEYS['port'], (int) ($data['port'] ?? 587), 'integer');
        Setting::set(self::KEYS['encryption'], $data['encryption']);
        Setting::set(self::KEYS['username'], trim((string) ($data['username'] ?? '')));
        Setting::set(self::KEYS['commit_variance_enabled'], $data['commit_variance_enabled'] ? '1' : '0', 'boolean');

        if (($data['password'] ?? '') !== '') {
            Setting::set(self::KEYS['password'], Crypt::encryptString($data['password']));
        }
    }

    /** Whether the commit variance email is switched on and has an account to send from. */
    public function commitVarianceEnabled(): bool
    {
        $settings = $this->settings();

        return $settings['commit_variance_enabled']
            && $settings['from_address'] !== ''
            && ($settings['driver'] === self::DRIVER_LOG || $settings['host'] !== '');
    }

    /**
     * A mailer for the saved account, or for the account typed on the page (`$override`) while it
     * is being tried out before saving. A blank password in `$override` uses the saved one.
     */
    public function mailer(?array $override = null): Mailer
    {
        $settings = array_merge($this->settings(), $override ?? []);

        if ($settings['driver'] === self::DRIVER_LOG) {
            return Mail::build(['transport' => 'log', 'channel' => null]);
        }

        $password = ($override['password'] ?? '') !== '' ? $override['password'] : $this->savedPassword();

        return Mail::build([
            'transport' => 'smtp',
            'host' => $settings['host'],
            'port' => (int) $settings['port'],
            'encryption' => $settings['encryption'] === 'none' ? null : $settings['encryption'],
            'username' => $settings['username'] !== '' ? $settings['username'] : null,
            'password' => $password !== '' ? $password : null,
            'timeout' => 20,
        ]);
    }

    /**
     * Log in to the SMTP server with the account, and send a test email to `$sendTo` if given.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(?array $override = null, ?string $sendTo = null): array
    {
        $settings = array_merge($this->settings(), $override ?? []);

        try {
            $mailer = $this->mailer($override);
            $transport = $mailer->getSymfonyTransport();

            if (method_exists($transport, 'start')) {
                $transport->start(); // connects and logs in, or throws
            }

            if ($sendTo) {
                $mailer->raw(
                    "This is a test email from the DAVID Inventory System.\n\n"
                    ."It was sent from the no-reply notification mailbox set up in Administration > Email Notifications. "
                    ."Do not reply to it: replies to this mailbox are not read.",
                    fn ($message) => $message->to($sendTo)
                        ->from($settings['from_address'], $settings['from_name'])
                        ->subject('DAVID test email (no-reply)')
                );

                return ['ok' => true, 'message' => "Connected, and a test email was sent to {$sendTo}."];
            }

            return ['ok' => true, 'message' => $settings['driver'] === self::DRIVER_LOG
                ? 'Log only: emails are written to the application log, nothing is sent.'
                : "Connected to {$settings['host']} and logged in."];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'The connection failed: '.$e->getMessage()];
        }
    }

    /**
     * The store's email addresses from Store Branches, each once. Text that is not an email
     * address is left out.
     *
     * @return list<string>
     */
    public function recipientsFor(StoreBranch $branch): array
    {
        $addresses = [];

        foreach (array_keys(self::BRANCH_EMAIL_FIELDS) as $field) {
            foreach (self::splitAddresses((string) $branch->{$field}) as $address) {
                $addresses[strtolower($address)] ??= $address; // the same address in other letters: once
            }
        }

        return array_values($addresses);
    }

    /**
     * The addresses in one Store Branches email field: separated by commas, semicolons or spaces,
     * keeping only valid email addresses.
     *
     * @return list<string>
     */
    public static function splitAddresses(string $value): array
    {
        return collect(preg_split('/[\s,;]+/', trim($value)) ?: [])
            ->filter(fn ($part) => $part !== '' && Validator::make(['e' => $part], ['e' => 'email'])->passes())
            ->values()
            ->all();
    }

    /**
     * Whether a committed line differs from what the store ordered (to 4 decimals) and that
     * committed quantity has not been emailed yet. A quantity changed again is emailed again.
     */
    public static function needsVarianceNotice(\App\Models\StoreOrderItem $line): bool
    {
        $committed = round((float) $line->quantity_commited, 4);

        if ($committed === round((float) $line->quantity_ordered, 4)) {
            return false;
        }

        return $line->commit_variance_notified_qty === null
            || round((float) $line->commit_variance_notified_qty, 4) !== $committed;
    }

    /**
     * Email the store the lines of its order that CS committed at a quantity other than the one it
     * ordered. Never throws: a mail problem must not undo or block the commit, so it is logged.
     *
     * @param  Collection<int, \App\Models\StoreOrderItem>  $lines  only the lines with a variance
     * @return bool whether an email was sent
     */
    public function sendCommitVariance(StoreOrder $order, Collection $lines, ?User $committedBy = null): bool
    {
        if ($lines->isEmpty() || ! $this->commitVarianceEnabled()) {
            return false;
        }

        $order->loadMissing(['store_branch', 'supplier']);
        $recipients = $order->store_branch ? $this->recipientsFor($order->store_branch) : [];

        if ($recipients === []) {
            Log::warning('Commit variance email not sent: the store has no email in Store Branches.', [
                'order_number' => $order->order_number,
                'store_branch_id' => $order->store_branch_id,
            ]);

            return false;
        }

        $settings = $this->settings();

        try {
            $this->mailer()->to($recipients)->send(
                (new CommitVarianceMail($order, $lines, $committedBy))->from($settings['from_address'], $settings['from_name'])
            );

            Log::info('Commit variance email sent.', [
                'order_number' => $order->order_number,
                'recipients' => $recipients,
                'lines' => $lines->count(),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('Commit variance email failed.', [
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function savedPassword(): string
    {
        $stored = (string) Setting::get(self::KEYS['password'], '');

        if ($stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            // Saved under another APP_KEY: it has to be typed again.
            return '';
        }
    }
}
