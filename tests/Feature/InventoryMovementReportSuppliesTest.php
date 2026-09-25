<?php

use App\Http\Controllers\InventoryMovementReportController;
use App\Models\Entity;
use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\SAPMasterfile;
use App\Models\SapItemType;
use App\Models\StoreBranch;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Support\Facades\DB;

/**
 * Supplies (gloves, cleaners, straws) are used up without a transaction, so the
 * month end count is what shows how much went. The report's Supplies Used is the
 * part of the book balance the count does not find, for supplies items only.
 */
function suppliesReport(): array
{
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'Test Entity', 'code' => 'TE'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create();
    $branch = StoreBranch::create(['branch_code' => 'SUPT', 'name' => 'Supplies Test', 'store_status' => 'active']);

    // GLOVES: typed Operating Supplies. STRAW: "Supplies" supplier category, also in a recipe.
    // TISSUE: Cleaning Supplies, not counted yet. BEANS: food, counted short.
    foreach (['GLOVES' => 'Pc', 'STRAW' => 'Pc', 'TISSUE' => 'Roll', 'BEANS' => 'Bag'] as $code => $unit) {
        SAPMasterfile::create([
            'ItemCode' => $code, 'ItemDescription' => $code, 'AltUOM' => $unit, 'BaseUOM' => $unit,
            'AltQty' => 1, 'BaseQty' => 1, 'is_active' => true,
        ]);
    }

    foreach (['GLOVES' => 'OPERATING SUPPLIES', 'TISSUE' => 'CLEANING SUPPLIES', 'BEANS' => 'FOOD'] as $code => $type) {
        DB::table('sap_item_type_assignments')->insert([
            'entity_id' => $entity->id, 'item_code' => $code,
            'sap_item_type_id' => SapItemType::create(['name' => $type, 'is_active' => true])->id,
        ]);
    }
    DB::table('supplier_items')->insert([
        'entity_id' => $entity->id, 'ItemCode' => 'STRAW', 'SupplierCode' => 'SUP', 'category' => 'Supplies',
        'uom' => 'Pc', 'is_active' => true,
    ]);

    $supplierId = DB::table('suppliers')->insertGetId(['supplier_code' => 'SUP', 'name' => 'Supplier', 'is_active' => true]);
    $orderId = DB::table('store_orders')->insertGetId([
        'encoder_id' => $user->id, 'supplier_id' => $supplierId, 'store_branch_id' => $branch->id,
        'order_number' => 'SUPT-00001', 'order_date' => '2026-09-05', 'order_status' => 'received',
    ]);
    foreach (['GLOVES' => ['Pc', 10], 'STRAW' => ['Pc', 100], 'TISSUE' => ['Roll', 12], 'BEANS' => ['Bag', 5]] as $code => [$unit, $qty]) {
        $itemId = DB::table('store_order_items')->insertGetId([
            'store_order_id' => $orderId, 'item_code' => $code, 'uom' => $unit,
            'quantity_ordered' => $qty, 'quantity_approved' => $qty, 'quantity_commited' => $qty, 'quantity_received' => $qty,
            'cost_per_quantity' => 0, 'total_cost' => 0,
        ]);
        DB::table('ordered_item_receive_dates')->insert(['store_order_item_id' => $itemId, 'quantity_received' => $qty, 'status' => 'approved']);
    }

    // 30 straws go out with sales through the recipe.
    $pos = POSMasterfile::create(['POSCode' => 'POS-LS', 'POSDescription' => 'Lemon soda', 'SRP' => 100]);
    POSMasterfileBOM::create(['POSCode' => $pos->POSCode, 'ItemCode' => 'STRAW', 'BOMQty' => 1, 'BOMUOM' => 'Pc']);
    $transactionId = DB::table('store_transactions')->insertGetId([
        'store_branch_id' => $branch->id, 'order_date' => '2026-09-10', 'posted' => 'Y', 'tim_number' => 'T1', 'receipt_number' => 'R1',
    ]);
    DB::table('store_transaction_items')->insert([
        'store_transaction_id' => $transactionId, 'product_id' => $pos->id, 'base_quantity' => 30, 'quantity' => 30,
        'price' => 100, 'discount' => 0, 'line_total' => 3000, 'net_total' => 3000,
    ]);

    // August count opens the period with 2 gloves; September counts 5 gloves, 60 straws,
    // 4 bags of beans (short by 1) - and no tissue yet.
    $august = DB::table('month_end_schedules')->insertGetId(['entity_id' => $entity->id, 'created_by' => $user->id, 'calculated_date' => '2026-08-31', 'month' => 8, 'year' => 2026, 'status' => 'level2_approved']);
    $september = DB::table('month_end_schedules')->insertGetId(['entity_id' => $entity->id, 'created_by' => $user->id, 'calculated_date' => '2026-09-30', 'month' => 9, 'year' => 2026, 'status' => 'level2_approved']);
    $sap = SAPMasterfile::pluck('id', 'ItemCode');
    foreach ([[$august, 'GLOVES', 2, 'Pc'], [$september, 'GLOVES', 5, 'Pc'], [$september, 'STRAW', 60, 'Pc'], [$september, 'BEANS', 4, 'Bag']] as [$schedule, $code, $qty, $unit]) {
        DB::table('month_end_count_items')->insert([
            'entity_id' => $entity->id, 'month_end_schedule_id' => $schedule, 'branch_id' => $branch->id,
            'sap_masterfile_id' => $sap[$code], 'item_code' => $code, 'item_name' => $code, 'created_by' => $user->id, 'status' => 'level2_approved', 'total_qty' => $qty, 'uom' => $unit,
        ]);
    }

    $controller = app(InventoryMovementReportController::class);
    $method = new ReflectionMethod($controller, 'getMovementData');
    $rows = $method->invoke($controller, SAPMasterfile::where('is_active', true)->get(), [
        'branch_id' => $branch->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30',
    ]);

    return collect($rows)->keyBy('sap_code')->all();
}

it('takes supplies usage from the month end count and closes theoretical on it', function () {
    $rows = suppliesReport();

    // 2 on hand + 10 received - 5 counted = 7 gloves used.
    expect($rows['GLOVES']['supplies_type'])->toBe('Operating Supplies')
        ->and($rows['GLOVES']['supplies_counted'])->toBeTrue()
        ->and((float) $rows['GLOVES']['supplies_qty'])->toBe(7.0)
        ->and((float) $rows['GLOVES']['theoretical_qty'])->toBe(5.0);

    // Straws in the recipe are already in Sales: 100 - 30 sold - 60 counted = 10 used on top.
    expect($rows['STRAW']['supplies_type'])->toBe('Supplies')
        ->and((float) $rows['STRAW']['sales_qty'])->toBe(30.0)
        ->and((float) $rows['STRAW']['supplies_qty'])->toBe(10.0)
        ->and((float) $rows['STRAW']['theoretical_qty'])->toBe(60.0);
});

it('leaves supplies alone until counted, and never treats food as supplies', function () {
    $rows = suppliesReport();

    expect($rows['TISSUE']['supplies_type'])->toBe('Cleaning Supplies')
        ->and($rows['TISSUE']['supplies_counted'])->toBeFalse()
        ->and((float) $rows['TISSUE']['supplies_qty'])->toBe(0.0)
        ->and((float) $rows['TISSUE']['theoretical_qty'])->toBe(12.0);

    // Beans counted 1 short: a variance to explain, not supplies usage.
    expect($rows['BEANS']['supplies_type'])->toBeNull()
        ->and((float) $rows['BEANS']['supplies_qty'])->toBe(0.0)
        ->and((float) $rows['BEANS']['theoretical_qty'])->toBe(5.0)
        ->and((float) $rows['BEANS']['actual_mec'])->toBe(4.0);
});
