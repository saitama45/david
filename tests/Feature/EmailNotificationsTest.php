<?php

use App\Http\Controllers\CSMassCommitsController;
use App\Http\Controllers\EmailNotificationsController;
use App\Http\Controllers\StoreBranchController;
use App\Http\Services\NotificationMailService;
use App\Jobs\SendCommitVarianceEmails;
use App\Models\Setting;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use App\Models\SupplierItems;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

// Administration > Email Notifications: the no-reply mailbox, and the commit variance email it sends.

function mailSettings(array $overrides = []): void
{
    app(NotificationMailService::class)->save(array_merge([
        'from_name' => 'DAVID (no-reply)', 'from_address' => 'no-reply@example.com', 'driver' => 'smtp',
        'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'no-reply@example.com',
        'password' => 'app-password-1234', 'commit_variance_enabled' => true,
    ], $overrides));
}

/**
 * A CS user who may commit non-finished goods of GSI-V, and an approved order of a store with emails:
 * BREAD-A committed at the ordered 5, BREAD-B committed at 3 of the 5 ordered.
 *
 * @return array{0: User, 1: StoreOrder, 2: StoreOrderItem, 3: StoreOrderItem, 4: StoreBranch}
 */
function varianceFixture(): array
{
    $user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'edit other commits', 'guard_name' => 'web']);
    $user->givePermissionTo('edit other commits');

    $store = StoreBranch::create([
        'branch_code' => 'VAR-1', 'brand_code' => 'VAR-1', 'name' => 'Variance Store', 'store_status' => 'active', 'is_active' => true,
        'head_chef' => 'chef@example.com', 'director_operations' => 'do@example.com; extra@example.com',
        'vp_operations' => 'not an email', 'aom' => 'CHEF@example.com',
    ]);
    $user->store_branches()->attach($store->id);

    $supplier = Supplier::create(['supplier_code' => 'GSI-V', 'name' => 'GSI Variance', 'is_active' => true]);
    $user->suppliers()->attach('GSI-V');

    foreach ([['BREAD-A', 'Pandesal'], ['BREAD-B', 'Ensaymada']] as [$code, $name]) {
        SupplierItems::create([
            'ItemCode' => $code, 'item_name' => $name, 'SupplierCode' => 'GSI-V', 'uom' => 'PCK', 'category' => 'BREAD',
            'brand' => '', 'classification' => '', 'packaging_config' => '', 'config' => 0, 'cost' => 10, 'is_active' => true,
        ]);
    }

    $order = StoreOrder::create([
        'encoder_id' => $user->id, 'supplier_id' => $supplier->id, 'store_branch_id' => $store->id,
        'order_number' => 'VAR-1-00001', 'order_date' => '2026-11-09', 'order_status' => 'approved', 'variant' => 'mass regular',
    ]);
    $same = $order->store_order_items()->create(['item_code' => 'BREAD-A', 'uom' => 'PCK', 'quantity_ordered' => 5, 'quantity_approved' => 5, 'quantity_commited' => 5, 'cost_per_quantity' => 10, 'total_cost' => 50]);
    $short = $order->store_order_items()->create(['item_code' => 'BREAD-B', 'uom' => 'PCK', 'quantity_ordered' => 5, 'quantity_approved' => 5, 'quantity_commited' => 3, 'cost_per_quantity' => 10, 'total_cost' => 30]);

    return [$user, $order, $same, $short, $store];
}

/** The NotificationMailService with its mailer swapped for the in-memory "array" mailer. */
function arrayMailer()
{
    $mailer = Mail::mailer('array');
    $mock = Mockery::mock(NotificationMailService::class)->makePartial();
    $mock->shouldReceive('mailer')->andReturn($mailer);
    app()->instance(NotificationMailService::class, $mock);

    return $mailer->getSymfonyTransport();
}

it('saves the no-reply account with its password encrypted, never sends the password back, and keeps it when left blank', function () {
    mailSettings();
    $service = app(NotificationMailService::class);

    $stored = Setting::where('key', 'notification_mail.password')->value('value');
    expect($stored)->not->toBe('app-password-1234')
        ->and(Illuminate\Support\Facades\Crypt::decryptString($stored))->toBe('app-password-1234')
        ->and($service->settings())->not->toHaveKey('password')
        ->and($service->settings()['has_password'])->toBeTrue();

    mailSettings(['password' => '', 'host' => 'smtp.other.com']);
    expect(Illuminate\Support\Facades\Crypt::decryptString(Setting::where('key', 'notification_mail.password')->value('value')))->toBe('app-password-1234')
        ->and($service->settings()['host'])->toBe('smtp.other.com');

    mailSettings(['commit_variance_enabled' => false]);
    expect($service->commitVarianceEnabled())->toBeFalse();
});

it('refuses a Sender Email Address that is not an email, and tests the account as typed', function () {
    $this->actingAs(User::factory()->create());
    $controller = app(EmailNotificationsController::class);
    $payload = ['from_name' => 'DAVID', 'from_address' => 'no-reply@example.com', 'driver' => 'log', 'encryption' => 'tls', 'commit_variance_enabled' => true];

    expect(fn () => $controller->update(Request::create('/email-notifications', 'POST', ['from_address' => 'nope'] + $payload)))
        ->toThrow(ValidationException::class)
        ->and(fn () => $controller->update(Request::create('/email-notifications', 'POST', ['driver' => 'smtp'] + $payload)))
        ->toThrow(ValidationException::class); // SMTP needs a host and a port

    $result = $controller->test(Request::create('/email-notifications/test', 'POST', $payload))->getData(true);
    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toContain('Log only');
});

it('emails the store from Confirm All with only the lines committed at another quantity', function () {
    [$user, $order, $same, $short] = varianceFixture();
    mailSettings();
    $this->actingAs($user);
    Bus::fake();

    app(CSMassCommitsController::class)->confirmAll(Request::create('/cs-mass-commits/confirm-all', 'POST', ['order_date' => '2026-11-09', 'supplier_id' => 'GSI-V']));

    Bus::assertDispatchedAfterResponse(SendCommitVarianceEmails::class, fn ($job) => $job->lineIdsByOrder === [$order->id => [$short->id]] && $job->committedById === $user->id);
    expect(session('success'))->toContain('1 order(s) with a commit variance');
});

it('does not email while the commit variance email is switched off', function () {
    [$user] = varianceFixture();
    mailSettings(['commit_variance_enabled' => false]);
    $this->actingAs($user);
    Bus::fake();

    app(CSMassCommitsController::class)->confirmAll(Request::create('/cs-mass-commits/confirm-all', 'POST', ['order_date' => '2026-11-09', 'supplier_id' => 'GSI-V']));

    Bus::assertNotDispatchedAfterResponse(SendCommitVarianceEmails::class);
});

it('sends the variance email to the store\'s emails from Store Branches, once per committed quantity', function () {
    [$user, $order, $same, $short] = varianceFixture();
    mailSettings();
    $transport = arrayMailer();

    app()->call([new SendCommitVarianceEmails([$order->id => [$same->id, $short->id]], $user->id), 'handle']);

    $messages = $transport->messages();
    expect($messages)->toHaveCount(1);
    $email = $messages->first()->getOriginalMessage();
    $to = collect($email->getTo())->map->getAddress()->all();
    $html = $email->getHtmlBody();

    expect($to)->toBe(['chef@example.com', 'do@example.com', 'extra@example.com']) // invalid and repeated ones left out
        ->and($email->getFrom()[0]->getAddress())->toBe('no-reply@example.com')
        ->and($email->getSubject())->toBe('[No-Reply] Commit variance on order VAR-1-00001 - Variance Store')
        ->and($html)->toContain('This is a no-reply email')
        ->and($html)->toContain('BREAD-B')->toContain('Ensaymada')->toContain('-2.0000')
        ->and($html)->not->toContain('BREAD-A') // committed as ordered: not listed
        ->and((float) $short->fresh()->commit_variance_notified_qty)->toBe(3.0);

    // Already emailed at 3: nothing again.
    app()->call([new SendCommitVarianceEmails([$order->id => [$short->id]], $user->id), 'handle']);
    expect($transport->messages())->toHaveCount(1);

    // Committed again at another quantity: emailed again.
    $short->update(['quantity_commited' => 4]);
    app()->call([new SendCommitVarianceEmails([$order->id => [$short->id]], $user->id), 'handle']);
    expect($transport->messages())->toHaveCount(2);
});

it('sends nothing to a store with no email in Store Branches', function () {
    [$user, $order, , $short, $store] = varianceFixture();
    $store->update(['head_chef' => null, 'director_operations' => null, 'vp_operations' => null, 'aom' => null]);
    mailSettings();
    $transport = arrayMailer();

    app()->call([new SendCommitVarianceEmails([$order->id => [$short->id]], $user->id), 'handle']);

    expect($transport->messages())->toHaveCount(0)
        ->and($short->fresh()->commit_variance_notified_qty)->toBeNull();
});

it('only takes email addresses in the store\'s email fields', function () {
    [, , , , $store] = varianceFixture();
    $this->actingAs(User::factory()->create());

    $update = fn (array $values) => app(StoreBranchController::class)->update(Request::create("/branches/{$store->id}", 'PUT', array_merge([
        'branch_code' => 'VAR-1', 'name' => 'Variance Store', 'store_status' => 'active',
    ], $values)), $store->id);

    try {
        $update(['head_chef' => 'chef at store']);
        $this->fail('A Head Chef Email that is not an email should be refused.');
    } catch (ValidationException $e) {
        expect($e->errors()['head_chef'][0])->toBe('The Head Chef Email must be a valid email address.');
    }

    $update(['head_chef' => 'newchef@example.com', 'aom' => null]);
    expect($store->fresh()->head_chef)->toBe('newchef@example.com');
});
