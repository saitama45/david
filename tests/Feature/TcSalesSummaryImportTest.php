<?php

use App\Http\Controllers\ExcelTemplateController;
use App\Imports\StoreTransactionImport;
use App\Imports\TcSalesSummaryImport;
use App\Models\Entity;
use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\User;
use App\Support\EntityContext;
use App\Support\SalesUploadFormat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The TC entity's manual sales upload reads its daily sales summary (Store Code | Date |
 * Item Code | Item Name | Actual Item Name | Qty | Net Sales) and posts each store and date as
 * one sale through the shared receipt processor. Runs on an isolated test database; the
 * importer is called directly, as the queued job does. Nothing here deletes a record.
 */
function tcFixture(): array
{
    if (DB::selectOne("SELECT 1 AS present FROM sys.indexes WHERE object_id = OBJECT_ID('sap_masterfiles') AND name = 'sap_masterfiles_itemcode_unique'")) {
        DB::statement('DROP INDEX sap_masterfiles_itemcode_unique ON sap_masterfiles');
    }

    $entity = Entity::create(['name' => 'TC', 'code' => 'TC', 'is_active' => true]);
    app(EntityContext::class)->set($entity->id);

    $user = User::factory()->create();
    $branch = StoreBranch::create(['branch_code' => 'TCGL1', 'brand_code' => 'TC', 'location_code' => 'GL1', 'name' => 'TC Glorietta', 'store_status' => 'Active', 'is_active' => 1]);
    $user->store_branches()->attach($branch->id);

    SAPMasterfile::create(['ItemCode' => 'RM-MATCHA', 'ItemDescription' => 'Matcha Powder', 'AltQty' => 1, 'BaseQty' => 1, 'AltUOM' => 'Gm', 'BaseUOM' => 'Gm', 'is_active' => true]);
    foreach (['TCO01010001' => 'Ichigo Matcha', 'TCO01010002' => 'Matcha Latte'] as $code => $name) {
        POSMasterfile::create(['POSCode' => $code, 'POSDescription' => $name, 'SRP' => 190, 'is_active' => true]);
        POSMasterfileBOM::create(['POSCode' => $code, 'POSDescription' => $name, 'ItemCode' => 'RM-MATCHA',
            'ItemDescription' => 'Matcha Powder', 'BOMQty' => 20, 'BOMUOM' => 'Gm', 'UnitCost' => 0.5]);
    }

    return compact('entity', 'user', 'branch');
}

/** A sheet laid out as the TC file is: five blank rows, the header on row 6, data from row 7. */
function tcSheet(array $data): Collection
{
    $rows = collect(array_fill(0, 5, collect(array_fill(0, 7, null))));
    $rows->push(collect(['Store Code', 'Date', 'Item Code', 'Item Name', 'Actual Item Name', 'Qty', 'Net Sales']));
    foreach ($data as $row) {
        $rows->push(collect($row));
    }

    return $rows->values();
}

function tcImport(array $f, Collection $sheet): TcSalesSummaryImport
{
    $import = new TcSalesSummaryImport($f['user'], 'manual');
    $import->collection($sheet);

    return $import;
}

function tcMatchaOut(array $f): float
{
    return (float) DB::table('product_inventory_stock_managers')->where('store_branch_id', $f['branch']->id)
        ->where('action', 'out')->sum('quantity');
}

it('posts the TC template file as one daily sale per store and date', function () {
    $f = tcFixture();

    $import = SalesUploadFormat::importer($f['user']);
    expect($import)->toBeInstanceOf(TcSalesSummaryImport::class);

    // The downloadable template itself: GL1, 11/20/26 (an Excel date), TCO01010001, Qty 37, Net Sales 6,680.84.
    $reader = new \Maatwebsite\Excel\Reader(app(\Maatwebsite\Excel\Files\TemporaryFileFactory::class), new \Maatwebsite\Excel\Transactions\NullTransactionHandler);
    $reader->read($import, storage_path('app/public/excel-templates/tc_sales_template.xlsx'));

    expect($import->getSkippedRows())->toBe([]);

    $sale = DB::table('store_transactions')->where('store_branch_id', $f['branch']->id)->first();
    $item = DB::table('store_transaction_items')->where('store_transaction_id', $sale->id)->first();

    expect(substr($sale->order_date, 0, 10))->toBe('2026-11-20')
        ->and($sale->tim_number)->toBe('DAILY')
        ->and($sale->receipt_number)->toBe('20261120')
        ->and((int) $item->quantity)->toBe(37)
        ->and((float) $item->net_total)->toBe(6680.84)
        ->and((float) $item->line_total)->toBe(6680.84)
        ->and((float) $item->discount)->toBe(0.0)
        ->and((float) $item->price)->toBe(180.5632)
        // 37 sold x 20 Gm of matcha each.
        ->and(tcMatchaOut($f))->toBe(740.0)
        ->and(DB::table('sales_postings')->where('store_transaction_id', $sale->id)->value('source'))->toBe('manual');
});

it('does not post the same store and date twice', function () {
    $f = tcFixture();
    $sheet = tcSheet([['GL1', '2026-11-20', 'TCO01010001', 'Ichigo', 'Ichigo Matcha', 37, 6680.84]]);

    expect(tcImport($f, $sheet)->getSkippedRows())->toBe([]);
    $again = tcImport($f, $sheet);

    expect($again->getSkippedRows())->toHaveCount(1)
        ->and($again->getSkippedRows()[0]['reason'])->toBe('Sales of this store for this date were already uploaded; nothing was posted again.')
        ->and(DB::table('store_transactions')->count())->toBe(1)
        ->and(tcMatchaOut($f))->toBe(740.0);
});

it('posts a store day only when every item of it can post, and matches the store by branch code too', function () {
    $f = tcFixture();

    $import = tcImport($f, tcSheet([
        // Nov 20: one unknown item holds the whole day back.
        ['GL1', '11/20/26', 'TCO01010001', 'Ichigo', 'Ichigo Matcha', 10, 1805.63],
        ['GL1', '11/20/26', 'TCO09999999', 'Unknown', 'Unknown Drink', 1, 150],
        // Nov 21, the store by branch code and by location code: one day. Text numbers with
        // separators, an item listed with nothing sold, and a total line.
        ['TCGL1', '11/21/2026', 'TCO01010001', 'Ichigo', 'Ichigo Matcha', '2', '1,200.50'],
        ['gl1', '11/21/2026', 'TCO01010002', 'Latte', 'Matcha Latte', 1, 190],
        ['TCGL1', '11/21/2026', 'TCO01010002', 'Latte', 'Matcha Latte', '-', '-'],
        [null, null, null, 'Grand Total', null, 14, 3346.13],
        // A store that is not TC's.
        ['XX9', '11/21/2026', 'TCO01010001', 'Ichigo', 'Ichigo Matcha', 1, 190],
    ]));

    $reasons = collect($import->getSkippedRows())->pluck('reason', 'row_number')->all();

    expect(array_keys($reasons))->toBe([7, 8, 13])
        ->and($reasons[7])->toBe('Missing or ambiguous POS masterfile: TCO09999999.')
        ->and($reasons[13])->toBe('Store Code XX9 is not a store of this entity.')
        ->and(DB::table('store_transactions')->count())->toBe(1)
        ->and(substr(DB::table('store_transactions')->value('order_date'), 0, 10))->toBe('2026-11-21')
        ->and(DB::table('store_transaction_items')->count())->toBe(2)
        ->and((float) DB::table('store_transaction_items')->sum('net_total'))->toBe(1390.5)
        ->and((float) DB::table('store_transaction_items')->where('quantity', 2)->value('price'))->toBe(600.25)
        // Only Nov 21 posted: 3 x 20 Gm.
        ->and(tcMatchaOut($f))->toBe(60.0);
});

it('refuses a file without the TC columns, and keeps the POS receipt format for other entities', function () {
    $f = tcFixture();

    expect(fn () => tcImport($f, collect([collect(['Product ID', 'Branch', 'Qty'])])))
        ->toThrow(Exception::class, 'Could not find the header row of the TC sales file.');

    expect((new ExcelTemplateController)->storeTransactionsTemplate()->getFile()->getFilename())->toBe('tc_sales_template.xlsx');

    $other = Entity::create(['name' => 'Other', 'code' => 'OT'.uniqid(), 'is_active' => true]);
    app(EntityContext::class)->set($other->id);

    expect(SalesUploadFormat::active())->toBe(SalesUploadFormat::POS_RECEIPTS)
        ->and(SalesUploadFormat::importer($f['user']))->toBeInstanceOf(StoreTransactionImport::class)
        ->and(SalesUploadFormat::forEntity($f['entity']->id))->toBe(SalesUploadFormat::DAILY_SUMMARY);
});
