<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel twin of resources/views/pdf/inventory-movement-report.blade.php — same
 * columns, same grouping and the same highlighted columns, but with real numbers
 * instead of formatted strings so the sheet stays sortable and summable. The reason
 * of an adjustment, a small line under the figure in the PDF, has its own column here.
 *
 * WithStrictNullComparison keeps zero quantities as 0 instead of blank cells —
 * without it a zero column is empty and auto-size never measures it.
 *
 * Column widths come from ShouldAutoSize. PhpSpreadsheet skips cells that span a
 * multi-column merge when it measures, so the merged title and group headers do
 * not stretch the columns — the data and the column labels in row 5 decide.
 */
class InventoryMovementReportExport implements FromCollection, ShouldAutoSize, WithCustomStartCell, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison, WithStyles, WithTitle
{
    private const FIRST_DATA_ROW = 6;

    /** Column groups exactly as the PDF header renders them: label => column span. */
    private const COLUMN_GROUPS = [
        'ITEM INFO' => 4,
        'PROCUREMENT (DATE RANGE)' => 3,
        'BEGINNING' => 1,
        'DEDUCTIONS / TRANSFERS' => 5,
        'FINAL BALANCE' => 6,
    ];

    private const COLUMN_HEADINGS = [
        'Supplier',
        'SAP Code',
        'Item Description',
        'UOM',
        'Ordered',
        'Committed',
        'Received',
        'Beg Bal Qty',
        'Sales Qty',
        'Wastage Qty',
        'Supplies Used',
        'In Interco',
        'Out Interco',
        'Theoretical',
        'Actual MEC',
        'Variance',
        'Adjustment',
        'Final Variance',
        'Adjustment Reason',
    ];

    /** Columns the PDF shades and bolds, keyed by column letter. */
    private const HIGHLIGHTED_COLUMNS = [
        'G' => 'F0F7FF', // Received
        'H' => 'F0FFF4', // Beg Bal Qty
        'N' => 'F5F3FF', // Theoretical
    ];

    private const LAST_COLUMN = 'S';

    /** The last column that holds a quantity; the one after it is the adjustment's reason, a text. */
    private const LAST_QUANTITY_COLUMN = 'R';

    private const REASON_COLUMN = 'S';

    /** A reason can run to 500 characters: it wraps in a column of this width instead of sizing it. */
    private const REASON_COLUMN_WIDTH = 45;

    private const WASTAGE_COLUMN = 'J';

    public function __construct(
        private $movementData,
        private array $filters,
        private $branch,
        private $supplier,
        private string $generatedBy,
        private string $generatedAt
    ) {}

    public function collection()
    {
        return collect($this->movementData);
    }

    public function title(): string
    {
        return 'Inventory Movement';
    }

    public function startCell(): string
    {
        return 'A'.self::FIRST_DATA_ROW;
    }

    public function headings(): array
    {
        // The heading block is written by registerEvents() so it can be merged and grouped.
        return [];
    }

    public function map($item): array
    {
        $item = (array) $item;

        return [
            $item['supplier'] ?: '-',
            $item['sap_code'],
            $item['item_description'],
            $item['uom'],
            (float) $item['ordered_qty'],
            (float) $item['committed_qty'],
            (float) $item['received_qty'],
            (float) $item['beg_bal_qty'],
            (float) $item['sales_qty'],
            (float) $item['wastage_qty'],
            (float) ($item['supplies_qty'] ?? 0),
            (float) $item['interco_in_qty'],
            (float) $item['interco_out_qty'],
            (float) $item['theoretical_qty'],
            (float) $item['actual_mec'],
            (float) $item['variance_qty'],
            (float) ($item['adjustment_qty'] ?? 0),
            (float) ($item['final_variance_qty'] ?? $item['variance_qty']),
            (string) ($item['adjustment_reason'] ?? ''),
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $last = self::LAST_COLUMN;

                $sheet->mergeCells('A1:'.$last.'1');
                $sheet->setCellValue('A1', 'Inventory Movement Report');
                $sheet->getComment('D5')->getText()->createTextRun(
                    'All quantities use the unit shown in this column (the SAP base unit, for example 36 Gm of a 1,000 Gm Bag = 0.0360 Bag).'
                );

                $sheet->mergeCells('A2:'.$last.'2');
                $sheet->setCellValue('A2', Carbon::parse($this->filters['date_from'])->format('M d, Y')
                    .' - '.Carbon::parse($this->filters['date_to'])->format('M d, Y'));

                $sheet->mergeCells('A3:'.$last.'3');
                $sheet->setCellValue('A3', 'Branch: '.($this->branch->name ?? 'N/A')
                    .'  |  Supplier: '.($this->supplier
                        ? $this->supplier->name.' ('.$this->supplier->supplier_code.')'
                        : 'All Suppliers')
                    .'  |  Generated: '.$this->generatedAt
                    .'  |  By: '.$this->generatedBy);

                // Row 4: group headers, each merged across the columns it covers.
                $column = 1;
                foreach (self::COLUMN_GROUPS as $label => $span) {
                    $start = $this->columnLetter($column);
                    $end = $this->columnLetter($column + $span - 1);

                    if ($span > 1) {
                        $sheet->mergeCells($start.'4:'.$end.'4');
                    }

                    $sheet->setCellValue($start.'4', $label);
                    $column += $span;
                }

                // Row 5: the column headings themselves.
                foreach (self::COLUMN_HEADINGS as $index => $heading) {
                    $sheet->setCellValue($this->columnLetter($index + 1).'5', $heading);
                }

                $sheet->getStyle('A1:'.$last.'1')->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
                    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);

                $sheet->getStyle('A2:'.$last.'3')->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F0FE']],
                    'font' => ['bold' => true, 'size' => 11],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);

                $sheet->getStyle('A4:'.$last.'4')->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '87CEEB']],
                    'font' => ['bold' => true, 'size' => 11],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
                ]);

                $sheet->getStyle('A5:'.$last.'5')->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B8D4F1']],
                    'font' => ['bold' => true, 'size' => 10],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
                ]);

                $sheet->getRowDimension(1)->setRowHeight(30);
                $sheet->getRowDimension(2)->setRowHeight(20);
                $sheet->getRowDimension(3)->setRowHeight(20);
                $sheet->getRowDimension(4)->setRowHeight(20);
                $sheet->getRowDimension(5)->setRowHeight(20);

                // A wastage figure that includes a wasted Sub-Prep is shaded and carries a note
                // saying which Sub-Prep, as the page marks it.
                foreach (collect($this->movementData)->values() as $index => $item) {
                    $subPreps = ((array) $item)['wastage_sub_preps'] ?? [];

                    if (empty($subPreps)) {
                        continue;
                    }

                    $cell = self::WASTAGE_COLUMN.(self::FIRST_DATA_ROW + $index);
                    $sheet->getComment($cell)->getText()->createTextRun(implode("\n", array_map(
                        fn ($subPrep) => sprintf(
                            'Sub-Prep %s %s: %s %s wasted = %s %s of this item',
                            $subPrep['code'], $subPrep['description'], \App\Support\ReportNumber::format($subPrep['wasted_qty']), $subPrep['uom'],
                            \App\Support\ReportNumber::format($subPrep['quantity']), ((array) $item)['uom']
                        ),
                        $subPreps
                    )));
                    $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF3C7');
                }

                // ShouldAutoSize has already claimed every column; the reason is taken back from it.
                $sheet->getColumnDimension(self::REASON_COLUMN)->setAutoSize(false)->setWidth(self::REASON_COLUMN_WIDTH);

                // Keep the headings visible while scrolling a long item list.
                $sheet->freezePane('A'.self::FIRST_DATA_ROW);
            },
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $last = self::LAST_COLUMN;
        $firstRow = self::FIRST_DATA_ROW;
        $lastRow = max($sheet->getHighestRow(), $firstRow);

        $sheet->getStyle('A'.$firstRow.':'.$last.$lastRow)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
        ]);

        // Text columns read left, UOM centres, every quantity right-aligned with four decimals.
        $sheet->getStyle('A'.$firstRow.':C'.$lastRow)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle('D'.$firstRow.':D'.$lastRow)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle('E'.$firstRow.':'.self::LAST_QUANTITY_COLUMN.$lastRow)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            'numberFormat' => ['formatCode' => \App\Support\ReportNumber::EXCEL],
        ]);

        $sheet->getStyle(self::REASON_COLUMN.$firstRow.':'.self::REASON_COLUMN.$lastRow)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setWrapText(true);

        foreach (self::HIGHLIGHTED_COLUMNS as $column => $rgb) {
            $sheet->getStyle($column.$firstRow.':'.$column.$lastRow)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rgb]],
                'font' => ['bold' => true],
            ]);
        }

        // Actual MEC, Variance, Adjustment and Final Variance are bold in the PDF but carry no fill.
        $sheet->getStyle('O'.$firstRow.':'.self::LAST_QUANTITY_COLUMN.$lastRow)
            ->getFont()->setBold(true);

        return $sheet;
    }

    private function columnLetter(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }
}
