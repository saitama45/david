<?php

use App\Exports\ActualCostCOGSReportExport;
use App\Exports\DeliveryReportExport;
use App\Exports\IntercoReportExport;
use App\Exports\PMIXReportExport;
use App\Exports\QtyVarianceCostVarianceReportExport;
use App\Exports\WastageReportExport;
use App\Exports\WastageTopItemsExport;
use App\Support\ReportNumber;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Every report prints its quantities, amounts and percentages with four decimals whatever
 * the value, and its Excel export keeps them as real numbers under that format.
 */
function reportSheet($export): Worksheet
{
    $path = tempnam(sys_get_temp_dir(), 'rnf');
    file_put_contents($path, Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));
    $sheet = IOFactory::createReader('Xlsx')->load($path)->getActiveSheet();
    @unlink($path);

    return $sheet;
}

/** The row holding $value in $column. */
function reportRow(Worksheet $sheet, string $column, string $value): int
{
    foreach (range(1, $sheet->getHighestRow()) as $row) {
        if ((string) $sheet->getCell($column.$row)->getValue() === $value) {
            return $row;
        }
    }

    throw new RuntimeException("{$value} is not in column {$column}.");
}

/** [value as Excel shows it, whether the cell is a number] of each cell. */
function reportCells(Worksheet $sheet, int $row, array $columns): array
{
    return array_map(fn ($column) => [
        $sheet->getCell($column.$row)->getFormattedValue(),
        is_numeric($sheet->getCell($column.$row)->getValue()),
    ], array_combine($columns, $columns));
}

it('formats a number with four decimals whatever its value', function () {
    expect(ReportNumber::format(5))->toBe('5.0000')
        ->and(ReportNumber::format(0.1))->toBe('0.1000')
        ->and(ReportNumber::format('1234.5'))->toBe('1,234.5000')
        ->and(ReportNumber::format(0.036125))->toBe('0.0361')
        ->and(ReportNumber::format(null))->toBe('0.0000')
        ->and(ReportNumber::format(-0.00001))->toBe('0.0000')
        ->and(ReportNumber::format(-2.5))->toBe('-2.5000');
});

it('exports Qty Variance / Cost Variance as numbers with four decimals', function () {
    $sheet = reportSheet(new QtyVarianceCostVarianceReportExport([[
        'mec_date' => '2026-09-30', 'store_name' => 'Glorietta 4', 'item_code' => 'RM-1', 'item_description' => 'Cocoa', 'uom' => 'Bag',
        'cost' => 1800, 'actual_inventory' => 2, 'theoretical_inventory' => 2.5, 'qty_variance' => -0.5,
        'actual_cost' => 3600, 'theoretical_cost' => 4500, 'cost_variance' => -900,
    ], [
        // A zero is a value too: 0.0000, not an empty cell.
        'mec_date' => '2026-09-30', 'store_name' => 'Glorietta 4', 'item_code' => 'RM-2', 'item_description' => 'Sugar', 'uom' => 'Kg',
        'cost' => 0, 'actual_inventory' => 3, 'theoretical_inventory' => 3, 'qty_variance' => 0,
        'actual_cost' => 0, 'theoretical_cost' => 0, 'cost_variance' => 0,
    ]]));

    expect(reportCells($sheet, reportRow($sheet, 'C', 'RM-1'), ['F', 'G', 'H', 'I', 'J', 'K', 'L']))->toBe([
        'F' => ['1,800.0000', true], 'G' => ['2.0000', true], 'H' => ['2.5000', true], 'I' => ['-0.5000', true],
        'J' => ['3,600.0000', true], 'K' => ['4,500.0000', true], 'L' => ['-900.0000', true],
    ])->and(reportCells($sheet, reportRow($sheet, 'C', 'RM-2'), ['F', 'I', 'L']))->toBe([
        'F' => ['0.0000', true], 'I' => ['0.0000', true], 'L' => ['0.0000', true],
    ]);
});

it('exports the Delivery Report quantities with four decimals', function () {
    $sheet = reportSheet(new DeliveryReportExport([[
        'expected_delivery_date' => '2026-10-01', 'date_received' => '2026-10-02', 'store_name' => 'Glorietta 4', 'store_code' => 'GL4',
        'supplier_code' => 'TGI', 'status' => 'received', 'item_code' => 'RM-1', 'item_description' => 'Cocoa', 'uom' => 'Bag',
        'quantity_ordered' => 3, 'quantity_committed' => 2.5, 'quantity_received' => 2.5, 'so_number' => 'SO-1', 'dr_number' => 'DR-1',
    ]], ['date_from' => '2026-10-01', 'date_to' => '2026-10-05']));

    // Nothing lost between committed and received: a variance of 0.0000, not an empty cell.
    expect(reportCells($sheet, reportRow($sheet, 'F', 'RM-1'), ['I', 'J', 'K', 'L', 'M']))->toBe([
        'I' => ['3.0000', true], 'J' => ['2.5000', true], 'K' => ['2.5000', true], 'L' => ['0.5000', true], 'M' => ['0.0000', true],
    ]);
});

it('exports the Actual Cost / COGS Report quantities and values with four decimals', function () {
    $sheet = reportSheet(new ActualCostCOGSReportExport(collect([[
        'store_branch' => 'Glorietta 4', 'item_code' => 'RM-1', 'item_description' => 'Cocoa', 'uom' => 'Bag', 'unit_cost' => 1800,
        'beginning_inventory' => 1, 'beginning_value' => 1800, 'deliveries' => 2, 'deliveries_value' => 3600,
        'interco' => 0, 'interco_value' => 0, 'ending_inventory' => 0.5, 'ending_value' => 900, 'actual_cost' => 4500,
    ]])));

    expect(reportCells($sheet, reportRow($sheet, 'B', 'RM-1'), ['E', 'F', 'G', 'J', 'L', 'N']))->toBe([
        'E' => ['1,800.0000', true], 'F' => ['1.0000', true], 'G' => ['1,800.0000', true],
        'J' => ['0.0000', true], 'L' => ['0.5000', true], 'N' => ['4,500.0000', true],
    ]);
});

it('exports the PMIX Report quantities and sales with four decimals, per store and in total', function () {
    $sheet = reportSheet(new PMIXReportExport([[
        'POSCode' => 'FG-1', 'POSDescription' => 'Mocha Latte', 'Category' => 'Beverages', 'SubCategory' => 'Hot',
        'stores' => [7 => ['quantity' => 12, 'sales' => 2160.5, 'take_out' => 0, 'dine_in' => 12]],
    ]], [7 => 'Glorietta 4'], ['date_from' => '2026-10-01', 'date_to' => '2026-10-05']));

    // E-H is the store, I-L the total. No take out is 0.0000, not an empty cell.
    expect(reportCells($sheet, reportRow($sheet, 'A', 'FG-1'), ['E', 'F', 'G', 'H', 'I', 'J', 'K']))->toBe([
        'E' => ['12.0000', true], 'F' => ['2,160.5000', true], 'G' => ['0.0000', true], 'H' => ['12.0000', true],
        'I' => ['12.0000', true], 'J' => ['2,160.5000', true], 'K' => ['0.0000', true],
    ]);
});

it('exports the Wastage Report with four decimals on every line and a total of its own', function () {
    $line = fn (string $no, float $qty, float $cost) => [
        'Wastage #' => $no, 'Store' => 'Glorietta 4', 'Item Code' => 'RM-1', 'Item Description' => 'Cocoa', 'UoM' => 'Gm',
        'Quantity' => $qty, 'Unit Cost' => $cost, 'Total Cost' => $qty * $cost, 'Status' => 'Approved Level 2',
        'Reason' => 'Spoilage', 'Remarks' => '', 'Date' => '10/05/2026 09:00 AM',
    ];
    $sheet = reportSheet(new WastageReportExport([$line('W-1', 64, 1.34), $line('W-2', 220, 0.26), $line('W-3', 390, 0.1)]));

    // Every line, the first and the last included, keeps its own figures.
    foreach (['W-1' => ['64.0000', '₱1.3400', '₱85.7600'], 'W-2' => ['220.0000', '₱0.2600', '₱57.2000'], 'W-3' => ['390.0000', '₱0.1000', '₱39.0000']] as $no => $expected) {
        $row = reportRow($sheet, 'B', $no);

        expect([$sheet->getCell('F'.$row)->getValue(), ...array_column(reportCells($sheet, $row, ['G', 'H', 'I']), 0)])
            ->toBe(['Gm', ...$expected]);
    }

    $total = reportRow($sheet, 'F', 'TOTAL:');

    expect($total)->toBeGreaterThan(reportRow($sheet, 'B', 'W-3'))
        ->and(array_column(reportCells($sheet, $total, ['G', 'I']), 0))->toBe(['674.0000', '₱181.9600']);
});

it('exports the top waste items with four decimals for quantity, amount and share', function () {
    $sheet = reportSheet(new WastageTopItemsExport([[
        'Month' => 'September 2026', 'Rank' => 1, 'Item Code' => 'RM-1', 'Item Description' => 'Cocoa', 'UoM' => 'Gm',
        'Total Qty' => 128, 'Total Amount' => 171.52, '% of Month' => 47.1263, 'Records' => 2,
    ]], ['date_from' => '2026-09-01', 'date_to' => '2026-10-05', 'top_limit' => 10]));

    $row = reportRow($sheet, 'D', 'RM-1');

    expect(reportCells($sheet, $row, ['G', 'H', 'I']))->toBe([
        'G' => ['128.0000', true], 'H' => ['₱171.5200', true], 'I' => ['47.1263%', true],
    ])
        // Rank and the number of records stay whole.
        ->and((string) $sheet->getCell('C'.$row)->getFormattedValue())->toBe('1')
        ->and((string) $sheet->getCell('J'.$row)->getFormattedValue())->toBe('2');
});

it('formats the Interco Report quantity and cost columns with four decimals', function () {
    expect((new IntercoReportExport([]))->columnFormats())->toBe([
        'C' => '#,##0.0000', 'D' => '#,##0.0000', 'M' => '#,##0.0000', 'N' => '#,##0.0000',
    ]);
});
