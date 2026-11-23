<?php

use App\Http\Controllers\SupplierItemsController;
use App\Http\Services\MassOrderService;
use App\Models\Entity;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use App\Models\SupplierItems;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// A supplier lists an item once per unit, each at its own cost. TC's Frozen - Strawberry is
// Pack 187 and Gm 0.19, and a mass order line in Pack was priced 0.19 (2026-11-23).

/** A GSI-like template whose items are listed in Gm (first) and in Pack. */
function unitCostFixture(): StoreBranch
{
    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);
    test()->actingAs(User::factory()->create());

    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    Supplier::create(['supplier_code' => 'GSI-T', 'name' => 'GSI Template', 'is_active' => true]);
    $item = fn (string $code, string $uom, float $cost) => SupplierItems::create([
        'ItemCode' => $code, 'item_name' => $code, 'SupplierCode' => 'GSI-T', 'uom' => $uom, 'cost' => $cost,
        'category' => '', 'brand' => '', 'classification' => '', 'packaging_config' => '', 'config' => 0, 'is_active' => true,
    ]);
    $item('304A2A', 'Gm', 0.19);
    $item('304A2A', 'Pack', 187);
    $item('201D9A', 'Gm', 0.06); // no Pack price: converted through SAP's 1000 Gm = 1 Pack

    SAPMasterfile::create(['ItemCode' => '201D9A', 'ItemDescription' => 'Sugar Syrup', 'AltQty' => 1000, 'AltUOM' => 'Gm', 'BaseQty' => 1, 'BaseUOM' => 'Pack', 'is_active' => true]);
    SAPMasterfile::create(['ItemCode' => '201D9A', 'ItemDescription' => 'Sugar Syrup', 'AltQty' => 1, 'AltUOM' => 'Gm', 'BaseQty' => 1, 'BaseUOM' => 'Gm', 'is_active' => true]);

    return StoreBranch::create(['branch_code' => 'TST1', 'brand_code' => 'TST1', 'name' => 'Test Store', 'store_status' => 'Active', 'is_active' => 1]);
}

it('prices each uploaded line in its own unit, converting only when that unit has no price', function () {
    unitCostFixture();

    app(MassOrderService::class)->processMassOrderUpload(collect([
        ['item_code' => '304A2A', 'unit' => 'Pack', 'tst1' => 5],
        ['item_code' => '201D9A', 'unit' => 'Pack', 'tst1' => 3],
        ['item_code' => '999X9X', 'unit' => 'Box', 'tst1' => 1],
    ]), 'GSI-T', now('Asia/Manila')->addDays(3)->toDateString());

    $cost = StoreOrderItem::pluck('cost_per_quantity', 'item_code')->map(fn ($c) => (float) $c);

    expect($cost['304A2A'])->toBe(187.0)
        ->and($cost['201D9A'])->toBe(60.0)
        ->and($cost['999X9X'])->toBe(0.0);
});

it('answers the add-item lookup with the unit asked for', function () {
    unitCostFixture();
    app()->instance('request', Request::create('/SupplierItems-list/details-by-code/304A2A/GSI-T', 'GET', ['uom' => 'pack']));

    $item = app(SupplierItemsController::class)->getDetailsByItemCodeAndSupplierCode('304A2A', 'GSI-T')->getData(true)['item'];

    expect([$item['uom'], (float) $item['cost']])->toBe(['Pack', 187.0]);
});
