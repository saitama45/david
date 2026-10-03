<?php

use App\Enums\WastageStatus;
use App\Http\Services\WastageApprovalSettingsService;
use App\Http\Services\WastageService;
use App\Models\Entity;
use App\Models\ProductInventoryStock;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\User;
use App\Models\Wastage;
use App\Support\EntityContext;

// Services are called directly: every test HTTP request disconnects the database on
// terminate(), which rolls back RefreshDatabase's transaction.

function negativeStockFixture(?float $soh): array
{
    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create();
    $store = StoreBranch::create([
        'branch_code' => 'TNEG', 'brand_code' => 'TNEG', 'name' => 'Negative Store', 'store_status' => 'Active', 'is_active' => 1,
    ]);
    $sap = SAPMasterfile::create([
        'ItemCode' => '265D9A', 'ItemDescription' => 'Chocolate Chip Walnut Cookies',
        'AltUOM' => 'PCK', 'BaseUOM' => 'PCK', 'AltQty' => 1, 'BaseQty' => 1, 'is_active' => true,
    ]);

    if ($soh !== null) {
        ProductInventoryStock::create([
            'product_inventory_id' => $sap->id, 'store_branch_id' => $store->id,
            'quantity' => $soh, 'recently_added' => 0, 'used' => 0,
        ]);
    }

    Wastage::create([
        'store_branch_id' => $store->id, 'wastage_no' => 'WS-NEG-1', 'sap_masterfile_id' => $sap->id,
        'wastage_qty' => 0.75, 'approverlvl1_qty' => 0.75, 'cost' => 10, 'reason' => 'Expired',
        'wastage_status' => WastageStatus::APPROVED_LVL1->value, 'created_by' => $user->id,
    ]);

    $wastages = Wastage::where('wastage_no', 'WS-NEG-1')->with('sapMasterfile')->get();

    return compact('user', 'store', 'sap', 'wastages');
}

test('negative stock is allowed by default and can be switched off', function () {
    $settings = app(WastageApprovalSettingsService::class);

    expect($settings->allowsNegativeStock())->toBeTrue()
        ->and($settings->sharedConfig()['allow_negative_stock'])->toBeTrue();

    $settings->setAllowNegativeStock(false);
    expect($settings->allowsNegativeStock())->toBeFalse();

    $settings->setAllowNegativeStock(true);
    expect($settings->allowsNegativeStock())->toBeTrue();
});

test('a shortfall is listed as going negative instead of blocking when allowed', function () {
    $f = negativeStockFixture(0);

    $check = app(WastageService::class)->getApprovalStockCheck($f['wastages'], $f['store']->id, 'level2');

    expect($check['errors'])->toBe([])
        ->and($check['negative'])->toHaveCount(1)
        ->and($check['negative'][0])->toMatchArray([
            'item_code' => '265D9A', 'uom' => 'PCK', 'available' => 0.0, 'required' => 0.75, 'resulting' => -0.75,
        ]);
});

test('a shortfall still blocks the approval when negative stock is switched off', function () {
    $f = negativeStockFixture(0);
    app(WastageApprovalSettingsService::class)->setAllowNegativeStock(false);
    $service = app(WastageService::class);

    $check = $service->getApprovalStockCheck($f['wastages'], $f['store']->id, 'level2');
    expect($check['negative'])->toBe([])
        ->and($check['errors'])->toHaveCount(1);

    // Even a "confirmed" request cannot get past it.
    expect($service->approvalStockProblem($f['wastages'], $f['store']->id, 'level2', true)['approval_error'])
        ->toBe('Cannot approve wastage due to insufficient stock for some items.');
});

test('going negative needs the approver confirmation', function () {
    $f = negativeStockFixture(0);
    $service = app(WastageService::class);

    $problem = $service->approvalStockProblem($f['wastages'], $f['store']->id, 'level2', false);
    expect($problem['approval_error'])->toContain('negative stock')
        ->and($problem['approval_stock_errors'][0]['item_code'])->toBe('265D9A');

    expect($service->approvalStockProblem($f['wastages'], $f['store']->id, 'level2', true))->toBeNull();
});

test('enough stock needs no confirmation', function () {
    $f = negativeStockFixture(5);
    $service = app(WastageService::class);

    expect($service->getApprovalStockCheck($f['wastages'], $f['store']->id, 'level2')['negative'])->toBe([])
        ->and($service->approvalStockProblem($f['wastages'], $f['store']->id, 'level2', false))->toBeNull();
});

test('a confirmed approval takes the stock on hand below zero', function () {
    $f = negativeStockFixture(0.25);

    app(WastageService::class)->finalizeWastageApproval($f['wastages'], $f['store']->id, $f['user']->id, 'level2');

    $stock = ProductInventoryStock::where('product_inventory_id', $f['sap']->id)->where('store_branch_id', $f['store']->id)->sole();
    expect((float) $stock->quantity)->toBe(-0.5)
        ->and((float) $stock->used)->toBe(0.75)
        ->and(Wastage::where('wastage_no', 'WS-NEG-1')->sole()->wastage_status)->toBe(WastageStatus::APPROVED_LVL2);
});

test('a store that never held the item gets a negative stock row on approval', function () {
    $f = negativeStockFixture(null);

    app(WastageService::class)->finalizeWastageApproval($f['wastages'], $f['store']->id, $f['user']->id, 'level2');

    $stock = ProductInventoryStock::where('product_inventory_id', $f['sap']->id)->where('store_branch_id', $f['store']->id)->sole();
    expect((float) $stock->quantity)->toBe(-0.75)
        ->and((int) $stock->entity_id)->toBe(app(EntityContext::class)->id());
});
