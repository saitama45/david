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
 * Sales on the Inventory Movement Report are dated by the POS sales date
 * (store_transactions.order_date), not by the import timestamp (created_at).
 * A day's sales file is normally uploaded the next day, so filtering on
 * created_at reported the previous days' receipts under the upload date.
 */
function inventoryMovementFixture(float $bomQty = 1): array
{
    Permission::firstOrCreate(['name' => 'view inventory movement report']);

    $user = User::factory()->create();
    $user->givePermissionTo('view inventory movement report');

    $branch = StoreBranch::create([
        'branch_code' => 'NNFIL',
        'name' => 'Filinvest Super Mall',
        'store_status' => 'active',
    ]);

    $user->store_branches()->attach($branch->id);

    $sap = SAPMasterfile::create([
        'ItemCode' => '199D9A',
        'ItemDescription' => 'Sugar Cookies, medium',
        'AltQty' => 1,
        'BaseQty' => 1,
        'AltUOM' => 'PC',
        'BaseUOM' => 'PC',
        'is_active' => true,
    ]);

    $pos = POSMasterfile::create([
        'POSCode' => 'POS199D9A',
        'POSDescription' => 'Sugar Cookies',
        'SRP' => 50,
    ]);

    POSMasterfileBOM::create([
        'POSCode' => $pos->POSCode,
        'ItemCode' => $sap->ItemCode,
        'BOMQty' => $bomQty,
    ]);

    return ['user' => $user, 'branch' => $branch, 'sap' => $sap, 'pos' => $pos];
}

/**
 * Insert a receipt with an explicit POS sales date and import timestamp. The
 * two differ in production, so they must be settable independently here.
 */
function recordSale(array $fixture, string $receipt, string $orderDate, string $importedAt, int $quantity): void
{
    $transactionId = DB::table('store_transactions')->insertGetId([
        'store_branch_id' => $fixture['branch']->id,
        'order_date' => $orderDate,
        'posted' => 'Y',
        'tim_number' => 'TIM-1',
        'receipt_number' => $receipt,
        'created_at' => $importedAt,
        'updated_at' => $importedAt,
    ]);

    DB::table('store_transaction_items')->insert([
        'store_transaction_id' => $transactionId,
        'product_id' => $fixture['pos']->id,
        'base_quantity' => $quantity,
        'quantity' => $quantity,
        'price' => 50,
        'discount' => 0,
        'line_total' => 50 * $quantity,
        'net_total' => 50 * $quantity,
        'created_at' => $importedAt,
        'updated_at' => $importedAt,
    ]);
}

function movementRows(User $user, StoreBranch $branch, string $dateFrom, string $dateTo): array
{
    $response = test()->actingAs($user)->get(route('reports.inventory-movement.index', [
        'branch_id' => $branch->id,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'per_page' => 50,
    ]));

    $response->assertOk();

    $rows = [];

    $response->assertInertia(function (Assert $page) use (&$rows) {
        $page->component('Reports/InventoryMovementReport/Index')->etc();
        $rows = $page->toArray()['props']['movementData'];
    });

    return $rows;
}

function salesQtyFor(array $rows, string $itemCode): ?float
{
    $row = collect($rows)->firstWhere('sap_code', $itemCode);

    return $row === null ? null : (float) $row['sales_qty'];
}

it('counts sales by POS sales date, not by the date the file was imported', function () {
    $fixture = inventoryMovementFixture();

    // The real 16 September receipts, uploaded on the 17th.
    recordSale($fixture, '26475', '2026-09-16', '2026-09-17 08:12:00', 1);
    recordSale($fixture, '26499', '2026-09-16', '2026-09-17 08:12:00', 3);

    // Earlier POS days that happened to be imported on the 16th.
    recordSale($fixture, '26301', '2026-09-14', '2026-09-16 09:00:00', 1);
    recordSale($fixture, '26292', '2026-09-14', '2026-09-16 09:00:00', 2);
    recordSale($fixture, '26388', '2026-09-15', '2026-09-16 09:00:00', 1);
    recordSale($fixture, '26392', '2026-09-15', '2026-09-16 09:00:00', 1);
    recordSale($fixture, '26280', '2026-09-15', '2026-09-16 09:00:00', 2);
    recordSale($fixture, '26402', '2026-09-15', '2026-09-16 09:00:00', 3);

    $rows = movementRows($fixture['user'], $fixture['branch'], '2026-09-16', '2026-09-16');

    expect(salesQtyFor($rows, '199D9A'))->toBe(4.0);
});

it('leaves an item off the report when only earlier POS days were imported that day', function () {
    $fixture = inventoryMovementFixture();

    recordSale($fixture, '26301', '2026-09-14', '2026-09-16 09:00:00', 1);
    recordSale($fixture, '26402', '2026-09-15', '2026-09-16 09:00:00', 3);

    $rows = movementRows($fixture['user'], $fixture['branch'], '2026-09-16', '2026-09-16');

    // The movement-existence filter and the sales aggregation must agree: with no
    // sales dated 16 September, the item has no movement and is not listed at all.
    expect(salesQtyFor($rows, '199D9A'))->toBeNull();
});

it('lists an item whose only movement is a sale dated in the range', function () {
    $fixture = inventoryMovementFixture();

    recordSale($fixture, '26475', '2026-09-16', '2026-09-17 08:12:00', 4);

    $rows = movementRows($fixture['user'], $fixture['branch'], '2026-09-16', '2026-09-16');

    expect(salesQtyFor($rows, '199D9A'))->toBe(4.0);
});

it('multiplies the sold quantity by the BOM quantity', function () {
    $fixture = inventoryMovementFixture(bomQty: 2.5);

    recordSale($fixture, '26475', '2026-09-16', '2026-09-17 08:12:00', 4);

    $rows = movementRows($fixture['user'], $fixture['branch'], '2026-09-16', '2026-09-16');

    expect(salesQtyFor($rows, '199D9A'))->toBe(10.0);
});

it('includes both ends of the date range', function () {
    $fixture = inventoryMovementFixture();

    recordSale($fixture, '26100', '2026-09-15', '2026-09-20 08:00:00', 1);
    recordSale($fixture, '26475', '2026-09-16', '2026-09-20 08:00:00', 3);
    recordSale($fixture, '26600', '2026-09-17', '2026-09-20 08:00:00', 5);
    recordSale($fixture, '26700', '2026-09-18', '2026-09-20 08:00:00', 7);

    $rows = movementRows($fixture['user'], $fixture['branch'], '2026-09-15', '2026-09-17');

    // 15th and 17th are both inclusive; the 18th is outside the range.
    expect(salesQtyFor($rows, '199D9A'))->toBe(9.0);
});

it('counts only the requested day for a single-day range', function () {
    $fixture = inventoryMovementFixture();

    recordSale($fixture, '26100', '2026-09-15', '2026-09-20 08:00:00', 1);
    recordSale($fixture, '26475', '2026-09-16', '2026-09-20 08:00:00', 3);
    recordSale($fixture, '26600', '2026-09-17', '2026-09-20 08:00:00', 5);

    $rows = movementRows($fixture['user'], $fixture['branch'], '2026-09-16', '2026-09-16');

    expect(salesQtyFor($rows, '199D9A'))->toBe(3.0);
});
