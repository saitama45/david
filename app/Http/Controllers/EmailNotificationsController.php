<?php

namespace App\Http\Controllers;

use App\Http\Services\NotificationMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration > Email Notifications: the no-reply SMTP mailbox the system sends its
 * notification emails from, and which notifications are switched on.
 */
class EmailNotificationsController extends Controller
{
    public function __construct(private NotificationMailService $mail) {}

    public function index(): Response
    {
        return Inertia::render('EmailNotifications/Index', [
            'settings' => $this->mail->settings(),
            'encryptions' => NotificationMailService::ENCRYPTIONS,
            'recipientFields' => array_values(NotificationMailService::BRANCH_EMAIL_FIELDS),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->mail->save($this->validated($request));

        return back()->with('success', 'Email notification settings saved.');
    }

    /** Log in with the account as typed on the page (saved or not), and send a test email if asked. */
    public function test(Request $request): JsonResponse
    {
        $data = $this->validated($request, forTest: true);
        $result = $this->mail->test($data, $data['send_test_to'] ?? null);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    private function validated(Request $request, bool $forTest = false): array
    {
        $smtp = Rule::requiredIf(fn () => $request->input('driver') === NotificationMailService::DRIVER_SMTP);

        $data = $request->validate([
            'from_name' => ['required', 'string', 'max:100'],
            'from_address' => ['required', 'email', 'max:255'],
            'driver' => ['required', Rule::in([NotificationMailService::DRIVER_SMTP, NotificationMailService::DRIVER_LOG])],
            'host' => [$smtp, 'nullable', 'string', 'max:255'],
            'port' => [$smtp, 'nullable', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(NotificationMailService::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'commit_variance_enabled' => [$forTest ? 'nullable' : 'required', 'boolean'],
            'send_test_to' => ['nullable', 'email', 'max:255'],
        ], [
            'from_address.required' => 'The Sender Email Address (the no-reply address) is required.',
            'from_address.email' => 'The Sender Email Address must be an email address, such as no-reply@yourcompany.com.',
            'host.required' => 'The SMTP Host is required.',
            'port.required' => 'The Port is required.',
        ]);

        $data['commit_variance_enabled'] = (bool) ($data['commit_variance_enabled'] ?? false);

        return $data;
    }
}
