<?php

use App\Exports\InventoryMovementReportExport;
use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Spatie\Permission\Models\Permission;

/**
 * The Excel export must mirror the PDF (same columns, same order) while keeping
 * quantities as real numbers, and must size its own columns.
 */
function excelExportFixture(): array
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
        'BOMQty' => 1,
    ]);

    $transactionId = DB::table('store_transactions')->insertGetId([
        'store_branch_id' => $branch->id,
        'order_date' => '2026-09-16',
        'posted' => 'Y',
        'tim_number' => 'TIM-1',
        'receipt_number' => '26475',
        'created_at' => '2026-09-17 08:12:00',
        'updated_at' => '2026-09-17 08:12:00',
    ]);

    DB::table('store_transaction_items')->insert([
        'store_transaction_id' => $transactionId,
        'product_id' => $pos->id,
        'base_quantity' => 4,
        'quantity' => 4,
        'price' => 50,
        'discount' => 0,
        'line_total' => 200,
        'net_total' => 200,
        'created_at' => '2026-09-17 08:12:00',
        'updated_at' => '2026-09-17 08:12:00',
    ]);

    return ['user' => $user, 'branch' => $branch];
}

function sheetFromResponse($response)
{
    $path = tempnam(sys_get_temp_dir(), 'imr').'.xlsx';

    // Excel::download hands back a BinaryFileResponse over a temp file.
    copy($response->baseResponse->getFile()->getPathname(), $path);

    return [IOFactory::load($path)->getActiveSheet(), $path];
}

function writtenSheet(InventoryMovementReportExport $export)
{
    $path = tempnam(sys_get_temp_dir(), 'imr').'.xlsx';

    file_put_contents($path, Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));

    return [IOFactory::load($path)->getActiveSheet(), $path];
}

it('downloads an xlsx named for the branch and date range', function () {
    $fixture = excelExportFixture();

    Excel::fake();

    $this->actingAs($fixture['user'])->get(route('reports.inventory-movement.export-excel', [
        'branch_id' => $fixture['branch']->id,
        'date_from' => '2026-09-16',
        'date_to' => '2026-09-16',
    ]))->assertOk();

    Excel::assertDownloaded('inventory-movement-report-nnfil-2026-09-16-to-2026-09-16.xlsx');
});

it('writes the same columns as the PDF, with quantities as numbers', function () {
    $fixture = excelExportFixture();

    $export = new InventoryMovementReportExport(
        reportRows($fixture),
        ['date_from' => '2026-09-16', 'date_to' => '2026-09-16'],
        $fixture['branch'],
        null,
        'QA Tester',
        '2026-09-18 15:16:42'
    );

    [$sheet, $path] = writtenSheet($export);

    // Header block: title, range, context line, then grouped headings.
    expect($sheet->getCell('A1')->getValue())->toBe('Inventory Movement Report');
    expect($sheet->getCell('A2')->getValue())->toBe('Sep 16, 2026 - Sep 16, 2026');
    expect($sheet->getCell('A3')->getValue())->toContain('Filinvest Super Mall');
    expect($sheet->getCell('A3')->getValue())->toContain('Generated: 2026-09-18 15:16:42');
    expect($sheet->getCell('A4')->getValue())->toBe('ITEM INFO');
    expect($sheet->getCell('E4')->getValue())->toBe('PROCUREMENT (DATE RANGE)');
    expect($sheet->getCell('H4')->getValue())->toBe('BEGINNING');
    expect($sheet->getCell('I4')->getValue())->toBe('DEDUCTIONS / TRANSFERS');
    expect($sheet->getCell('M4')->getValue())->toBe('FINAL BALANCE');

    $headings = [];

    foreach (range('A', 'N') as $column) {
        $headings[] = $sheet->getCell($column.'5')->getValue();
    }

    expect($headings)->toBe([
        'Supplier', 'SAP Code', 'Item Description', 'UOM',
        'Ordered', 'Committed', 'Received', 'Beg Bal Qty',
        'Sales Qty', 'Wastage Qty', 'In Interco', 'Out Interco',
        'Theoretical', 'Actual MEC',
    ]);

    // First data row holds the item, with Sales Qty as a number, not a string.
    expect($sheet->getCell('B6')->getValue())->toBe('199D9A');
    expect($sheet->getCell('C6')->getValue())->toBe('Sugar Cookies, medium');
    expect($sheet->getCell('I6')->getValue())->toBe(4.0);
    expect($sheet->getCell('M6')->getValue())->toBe(-4.0); // theoretical = 0 - sales
    expect($sheet->getCell('E6')->getValue())->toBe(0.0); // zero quantities stay 0, not blank
    expect($sheet->getCell('N6')->getValue())->toBe(0.0);

    unlink($path);
});

it('auto-sizes every column and aligns text left, UOM centre, quantities right', function () {
    $fixture = excelExportFixture();

    $export = new InventoryMovementReportExport(
        reportRows($fixture),
        ['date_from' => '2026-09-16', 'date_to' => '2026-09-16'],
        $fixture['branch'],
        null,
        'QA Tester',
        '2026-09-18 15:16:42'
    );

    [$sheet, $path] = writtenSheet($export);

    foreach (range('A', 'N') as $column) {
        $dimension = $sheet->getColumnDimension($column);

        // A written sheet resolves auto-size into a real width; nothing is left
        // at the default -1, and the merged title does not stretch column A.
        expect($dimension->getWidth())->toBeGreaterThan(0.0);
        expect($dimension->getWidth())->toBeLessThan(60.0);
    }

    expect($sheet->getStyle('A6')->getAlignment()->getHorizontal())->toBe(Alignment::HORIZONTAL_LEFT);
    expect($sheet->getStyle('C6')->getAlignment()->getHorizontal())->toBe(Alignment::HORIZONTAL_LEFT);
    expect($sheet->getStyle('D6')->getAlignment()->getHorizontal())->toBe(Alignment::HORIZONTAL_CENTER);
    expect($sheet->getStyle('I6')->getAlignment()->getHorizontal())->toBe(Alignment::HORIZONTAL_RIGHT);
    expect($sheet->getStyle('I6')->getNumberFormat()->getFormatCode())->toBe('#,##0.00');

    unlink($path);
});

it('still streams the PDF export from the shared builder', function () {
    $fixture = excelExportFixture();

    $response = $this->actingAs($fixture['user'])->get(route('reports.inventory-movement.export-pdf', [
        'branch_id' => $fixture['branch']->id,
        'date_from' => '2026-09-16',
        'date_to' => '2026-09-16',
    ]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('stamps the generated time in Asia/Manila even when the app runs on UTC', function () {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');

    $fixture = excelExportFixture();

    $manilaNow = Carbon::now('Asia/Manila');

    $response = $this->actingAs($fixture['user'])->get(route('reports.inventory-movement.export-excel', [
        'branch_id' => $fixture['branch']->id,
        'date_from' => '2026-09-16',
        'date_to' => '2026-09-16',
    ]));

    $response->assertOk();

    [$sheet, $path] = sheetFromResponse($response);

    preg_match('/Generated: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $sheet->getCell('A3')->getValue(), $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    // Manila is UTC+8: a UTC stamp would be eight hours off, far outside this window.
    expect(abs(Carbon::parse($matches[1], 'Asia/Manila')->diffInMinutes($manilaNow)))->toBeLessThan(5);

    unlink($path);
});

/**
 * The rows the report itself produces for the fixture, so the export is tested
 * against real controller output rather than hand-written data.
 */
function reportRows(array $fixture): array
{
    $controller = app(\App\Http\Controllers\InventoryMovementReportController::class);

    $method = new ReflectionMethod($controller, 'getMovementData');
    $method->setAccessible(true);

    $items = SAPMasterfile::where('is_active', true)->get();

    return $method->invoke($controller, $items, [
        'branch_id' => $fixture['branch']->id,
        'date_from' => '2026-09-16',
        'date_to' => '2026-09-16',
    ]);
}
