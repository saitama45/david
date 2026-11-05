<?php

use App\Exports\ApprovedOrdersExport;
use App\Http\Controllers\OrderingTemplateApprovalController;
use App\Http\Controllers\OrderReceivingController;
use App\Http\Services\MassOrderService;
use App\Http\Services\MonthEndCountReadinessService;
use App\Http\Services\OrderReceivingService;
use App\Models\OrderedItemReceiveDate;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A store user and a template whose CS Commit Required was turned on an hour ago. */
function csCommitFixture(?string $code = 'GSI-T'): array
{
    $user = User::factory()->create();
    $branch = StoreBranch::create(['branch_code' => 'CSC-BR', 'name' => 'CS Commit Branch', 'store_status' => 'active']);
    $user->store_branches()->attach($branch->id);
    $supplier = Supplier::create(['supplier_code' => $code, 'name' => $code.' Template', 'cs_commit_required_from' => now()->subHour()]);

    return [$user, $supplier, $branch];
}

/** An order of two lines, placed at $placedAt (now by default), delivered yesterday. */
function csCommitOrder(User $user, Supplier $supplier, StoreBranch $branch, string $status = 'approved', ?Carbon $placedAt = null, string $variant = 'mass regular', ?string $orderDate = null): StoreOrder
{
    $order = StoreOrder::create([
        'encoder_id' => $user->id, 'supplier_id' => $supplier->id, 'store_branch_id' => $branch->id,
        'order_number' => 'CSC-'.uniqid(), 'order_date' => $orderDate ?? now('Asia/Manila')->subDay()->toDateString(),
        'order_status' => $status, 'variant' => $variant,
    ]);

    foreach (['CSC-1', 'CSC-2'] as $code) {
        StoreOrderItem::create([
            'store_order_id' => $order->id, 'item_code' => $code, 'quantity_ordered' => 2, 'quantity_approved' => 2,
            'quantity_commited' => 2, 'cost_per_quantity' => 10, 'total_cost' => 20, 'uom' => 'Pack',
        ]);
    }

    if ($placedAt) {
        DB::table('store_orders')->where('id', $order->id)->update(['created_at' => $placedAt->format('Y-m-d H:i:s')]);
    }

    return $order->fresh();
}

function csCommitListed(string $tab = 'all'): array
{
    return app(OrderReceivingService::class)->getOrdersList($tab);
}

/** Inertia keeps a page's props to itself. */
function csCommitProps($inertiaResponse): array
{
    return (fn () => $this->props)->call($inertiaResponse);
}

function csCommitSave(Supplier $supplier, array $values)
{
    return app(OrderingTemplateApprovalController::class)->update(
        Request::create('/', 'POST', ['is_forapproval_massorders' => false, ...$values]),
        $supplier->fresh(),
    );
}

it('keeps an approved order placed since the switch out of the list, the tab counts and the export', function () {
    [$user, $supplier, $branch] = csCommitFixture();
    $this->actingAs($user);

    $waiting = csCommitOrder($user, $supplier, $branch);
    $older = csCommitOrder($user, $supplier, $branch, placedAt: now()->subDays(2));

    expect($waiting->waitsForCsCommit())->toBeTrue()
        ->and($older->waitsForCsCommit())->toBeFalse();

    $list = csCommitListed();
    expect(collect($list['orders']->items())->pluck('id')->all())->toBe([$older->id])
        ->and($list['counts']['commited'])->toBe(1)
        ->and($list['counts']['all'])->toBe(1);

    $exported = collect(app(ApprovedOrdersExport::class, [
        'filters' => [], 'currentFilter' => 'all', 'service' => app(OrderReceivingService::class),
    ])->query()->pluck('id'));
    expect($exported->all())->toBe([$older->id]);
});

it('lists the order as soon as CS commits one of its items', function () {
    [$user, $supplier, $branch] = csCommitFixture();
    $this->actingAs($user);

    $order = csCommitOrder($user, $supplier, $branch);
    expect(csCommitListed()['counts']['all'])->toBe(0);

    // As Confirm All in CS Mass Commits does, for the items this user may commit.
    $order->store_order_items()->first()->markAsCommittedBy($user->id);
    $order->refresh()->updateOrderStatusBasedOnCommits();

    expect($order->refresh()->order_status)->toBe('partial_committed')
        ->and($order->waitsForCsCommit())->toBeFalse()
        ->and(csCommitListed()['counts']['commited'])->toBe(1);
});

it('holds back nothing with the switch off', function () {
    [$user, $supplier, $branch] = csCommitFixture();
    $this->actingAs($user);

    $order = csCommitOrder($user, $supplier, $branch);
    $supplier->update(['cs_commit_required_from' => null]);

    expect($order->waitsForCsCommit())->toBeFalse()
        ->and(csCommitListed()['counts']['all'])->toBe(1);
});

it('applies only to approved mass orders', function () {
    [$user, $supplier, $branch] = csCommitFixture();
    $this->actingAs($user);

    $listed = [
        csCommitOrder($user, $supplier, $branch, 'committed'),
        csCommitOrder($user, $supplier, $branch, 'partial_committed'),
        csCommitOrder($user, $supplier, $branch, 'received'),
        csCommitOrder($user, $supplier, $branch, 'incomplete'),
        csCommitOrder($user, $supplier, $branch, variant: 'mass dts'),
    ];

    foreach ($listed as $order) {
        expect($order->waitsForCsCommit())->toBeFalse();
    }

    expect(csCommitListed()['counts']['all'])->toBe(count($listed));
});

it('refuses every receiving action on a waiting order and allows them once committed', function () {
    [$user, $supplier, $branch] = csCommitFixture();
    $this->actingAs($user);

    $order = csCommitOrder($user, $supplier, $branch);
    $service = app(OrderReceivingService::class);
    $controller = app(OrderReceivingController::class);
    $problem = 'This order is waiting for CS commit. It can be received once CS commits it in CS Mass Commits.';

    expect($service->receivingNotCommittedProblem($order))->toBe($problem);

    foreach (['zeroAll', 'confirmReceive', 'finalReceive'] as $action) {
        expect($controller->{$action}($order->id)->getSession()->get('errors')->first('error'))->toBe($problem);
    }

    expect(fn () => $service->addUnlistedItem($order, ['item_code' => 'CSC-3', 'uom' => 'Pack', 'quantity_received' => 1]))
        ->toThrow(\Exception::class, $problem);

    expect(OrderedItemReceiveDate::whereIn('store_order_item_id', $order->store_order_items()->pluck('id'))->count())->toBe(0)
        ->and($order->fresh()->order_status)->toBe('approved')
        ->and($order->fresh()->receiving_finalized_at)->toBeNull();

    $order->update(['order_status' => 'partial_committed']);
    expect($service->receivingNotCommittedProblem($order->fresh()))->toBeNull();
});

it('opens a waiting order by direct link with its notice and no receiving rows', function () {
    [$user, $supplier, $branch] = csCommitFixture();
    $this->actingAs($user);

    $order = csCommitOrder($user, $supplier, $branch);
    $props = csCommitProps(app(OrderReceivingController::class)->show($order->order_number));

    expect($props['waitingForCsCommit'])->toBeTrue()
        ->and($props['receiveDatesHistory'])->toHaveCount(0);

    // A template without the switch opens its rows on this page, as it did.
    $supplier->update(['cs_commit_required_from' => null]);
    $props = csCommitProps(app(OrderReceivingController::class)->show($order->order_number));

    expect($props['waitingForCsCommit'])->toBeFalse()
        ->and($props['receiveDatesHistory'])->toHaveCount(2);
});

it('shows a waiting order as TO COMMIT on the order details', function () {
    [$user, $supplier, $branch] = csCommitFixture();
    $this->actingAs($user);

    $waiting = csCommitOrder($user, $supplier, $branch);
    $older = csCommitOrder($user, $supplier, $branch, placedAt: now()->subDays(2));

    $status = fn (StoreOrder $order) => collect(app(MassOrderService::class)->orderDetails($order->order_number)['receiveDatesHistory'])
        ->pluck('display_status')->unique()->values()->all();

    expect($status($waiting))->toBe(['TO COMMIT'])
        ->and($status($older))->toBe(['TO RECEIVE']);
});

it('holds the Month End Count with its own message, apart from orders not yet received', function () {
    [$user, $supplier, $branch] = csCommitFixture();
    $this->actingAs($user);

    $date = '2026-10-10';
    csCommitOrder($user, $supplier, $branch, orderDate: $date);
    csCommitOrder($user, $supplier, $branch, orderDate: $date);
    csCommitOrder($user, $supplier, $branch, placedAt: now()->subDays(2), orderDate: $date);

    $blockers = collect(app(MonthEndCountReadinessService::class)->blockers([$branch->id], '2026-10-01', '2026-10-31')[$branch->id]);

    expect($blockers->pluck('count', 'key')->all())->toEqual([
        'orders_not_yet_received' => 1,
        'orders_waiting_for_cs_commit' => 2,
    ]);

    $waiting = $blockers->firstWhere('key', 'orders_waiting_for_cs_commit');
    expect($waiting['label'])->toBe('2 orders waiting for CS commit')
        ->and($waiting['url'])->toBeNull();
});

it('stamps the moment it is turned on, keeps it through other saves and clears it when off', function () {
    [$user, $supplier] = csCommitFixture();
    $this->actingAs($user);
    $supplier->update(['cs_commit_required_from' => null]);

    Carbon::setTestNow('2026-11-05 10:00:00');
    csCommitSave($supplier, ['cs_commit_required' => true]);
    expect($supplier->fresh()->cs_commit_required_from->format('Y-m-d H:i'))->toBe('2026-11-05 10:00');

    // The page sends the switch with every save: changing the levels must not move the moment.
    Carbon::setTestNow('2026-11-06 09:00:00');
    csCommitSave($supplier, ['cs_commit_required' => true, 'mass_order_approval_levels' => 2]);
    expect($supplier->fresh()->cs_commit_required_from->format('Y-m-d H:i'))->toBe('2026-11-05 10:00')
        ->and($supplier->fresh()->mass_order_approval_levels)->toBe(2);

    // A caller that does not send it leaves it alone.
    csCommitSave($supplier, []);
    expect($supplier->fresh()->cs_commit_required_from)->not->toBeNull();

    csCommitSave($supplier, ['cs_commit_required' => false]);
    expect($supplier->fresh()->cs_commit_required_from)->toBeNull();

    Carbon::setTestNow();
});

it('refuses the switch on a template with no CS commit', function (string $code) {
    [$user, $supplier] = csCommitFixture($code);
    $this->actingAs($user);
    $supplier->update(['cs_commit_required_from' => null]);

    $response = csCommitSave($supplier, ['cs_commit_required' => true]);

    expect($response->getSession()->get('errors')->first('error'))->toContain('have no CS commit')
        ->and($supplier->fresh()->cs_commit_required_from)->toBeNull();
})->with(['CPO', 'AO', 'EO', 'ALOC', 'DROPS']);

it('tells the page which templates can have the switch and since when', function () {
    [$user, $supplier] = csCommitFixture();
    $this->actingAs($user);
    Supplier::create(['supplier_code' => 'DROPS', 'name' => 'Dropshipping', 'is_active' => true]);
    $supplier->update(['is_active' => true, 'cs_commit_required_from' => '2026-11-05 14:30:00']);

    $rows = collect(csCommitProps(app(OrderingTemplateApprovalController::class)->index())['suppliers'])->keyBy('supplier_code');

    expect($rows['GSI-T']['has_cs_commit'])->toBeTrue()
        ->and($rows['GSI-T']['cs_commit_required'])->toBeTrue()
        ->and($rows['GSI-T']['cs_commit_required_since'])->toBe('Nov 5, 2026 2:30 PM')
        ->and($rows['DROPS']['has_cs_commit'])->toBeFalse()
        ->and($rows['DROPS']['cs_commit_required'])->toBeFalse()
        ->and($rows['DROPS']['cs_commit_required_since'])->toBeNull();
});
