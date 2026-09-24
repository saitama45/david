<?php

use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/**
 * An item SAP restates in two bases (Bag/Bag and Gm/Gm, with 1000 Gm = 1 Bag) must be
 * one report row in its smallest unit. It used to be two rows with every quantity
 * doubled, and Theoretical subtracted grams sold from bags received.
 */
it('reports an item with two base rows once, in its smallest unit', function () {
    // Fresh migrations keep a UNIQUE index on ItemCode the live schema does not have.
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    Permission::firstOrCreate(['name' => 'view inventory movement report']);
    $user = User::factory()->create();
    $user->givePermissionTo('view inventory movement report');

    $branch = StoreBranch::create(['branch_code' => 'GL4T', 'name' => 'Glorietta Test', 'store_status' => 'active']);
    $user->store_branches()->attach($branch->id);

    foreach ([['Gm', 'Bag', 1000, 1], ['Bag', 'Bag', 1, 1], ['Gm', 'Gm', 1, 1]] as [$alt, $base, $altQty, $baseQty]) {
        SAPMasterfile::create([
            'ItemCode' => 'RM-ESP', 'ItemDescription' => 'Espresso Blend',
            'AltUOM' => $alt, 'BaseUOM' => $base, 'AltQty' => $altQty, 'BaseQty' => $baseQty, 'is_active' => true,
        ]);
    }

    $supplierId = DB::table('suppliers')->insertGetId(['supplier_code' => 'TGIT', 'name' => 'TGI Test', 'is_active' => true]);
    $orderId = DB::table('store_orders')->insertGetId([
        'encoder_id' => $user->id, 'supplier_id' => $supplierId, 'store_branch_id' => $branch->id,
        'order_number' => 'GL4T-00001', 'order_date' => '2026-09-25', 'order_status' => 'received',
    ]);
    $orderItemId = DB::table('store_order_items')->insertGetId([
        'store_order_id' => $orderId, 'item_code' => 'RM-ESP', 'uom' => 'Bag',
        'quantity_ordered' => 2, 'quantity_approved' => 2, 'quantity_commited' => 2, 'quantity_received' => 1,
        'cost_per_quantity' => 0, 'total_cost' => 0,
    ]);
    DB::table('ordered_item_receive_dates')->insert([
        'store_order_item_id' => $orderItemId, 'quantity_received' => 1, 'status' => 'approved',
    ]);

    $pos = POSMasterfile::create(['POSCode' => 'POS-PB', 'POSDescription' => 'Pure Black', 'SRP' => 105]);
    POSMasterfileBOM::create(['POSCode' => $pos->POSCode, 'ItemCode' => 'RM-ESP', 'BOMQty' => 18, 'BOMUOM' => 'Gm']);

    $transactionId = DB::table('store_transactions')->insertGetId([
        'store_branch_id' => $branch->id, 'order_date' => '2026-09-24', 'posted' => 'Y',
        'tim_number' => 'TIM-1', 'receipt_number' => 'R-1',
    ]);
    DB::table('store_transaction_items')->insert([
        'store_transaction_id' => $transactionId, 'product_id' => $pos->id, 'base_quantity' => 2, 'quantity' => 2,
        'price' => 105, 'discount' => 0, 'line_total' => 210, 'net_total' => 210,
    ]);

    $rows = [];
    $this->actingAs($user)
        ->get(route('reports.inventory-movement.index', [
            'branch_id' => $branch->id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-25',
        ]))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$rows) {
            $rows = $page->toArray()['props']['movementData'];
        });

    $espresso = array_values(array_filter($rows, fn ($row) => $row['sap_code'] === 'RM-ESP'));

    expect($espresso)->toHaveCount(1)
        ->and($espresso[0]['uom'])->toBe('Gm')
        ->and((float) $espresso[0]['ordered_qty'])->toBe(2000.0)
        ->and((float) $espresso[0]['received_qty'])->toBe(1000.0)
        ->and((float) $espresso[0]['sales_qty'])->toBe(36.0)
        ->and((float) $espresso[0]['theoretical_qty'])->toBe(964.0)
        ->and($espresso[0]['unconverted_units'])->toBe([]);
});
