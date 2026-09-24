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
 * instead of formatted strings so the sheet stays sortable and summable.
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
        'DEDUCTIONS / TRANSFERS' => 4,
        'FINAL BALANCE' => 2,
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
        'In Interco',
        'Out Interco',
        'Theoretical',
        'Actual MEC',
    ];

    /** Columns the PDF shades and bolds, keyed by column letter. */
    private const HIGHLIGHTED_COLUMNS = [
        'G' => 'F0F7FF', // Received
        'H' => 'F0FFF4', // Beg Bal Qty
        'M' => 'F5F3FF', // Theoretical
    ];

    private const LAST_COLUMN = 'N';

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
            (float) $item['interco_in_qty'],
            (float) $item['interco_out_qty'],
            (float) $item['theoretical_qty'],
            (float) $item['actual_mec'],
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
                    'All quantities use the unit shown in this column (the SAP base unit, for example 36 Gm of a 1,000 Gm Bag = 0.036 Bag).'
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

        // Text columns read left, UOM centres, every quantity right-aligned with two decimals.
        $sheet->getStyle('A'.$firstRow.':C'.$lastRow)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle('D'.$firstRow.':D'.$lastRow)
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle('E'.$firstRow.':'.$last.$lastRow)->applyFromArray([
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
            'numberFormat' => ['formatCode' => '#,##0.00##'],
        ]);

        foreach (self::HIGHLIGHTED_COLUMNS as $column => $rgb) {
            $sheet->getStyle($column.$firstRow.':'.$column.$lastRow)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rgb]],
                'font' => ['bold' => true],
            ]);
        }

        // Actual MEC is bold in the PDF but carries no fill.
        $sheet->getStyle($last.$firstRow.':'.$last.$lastRow)
            ->getFont()->setBold(true);

        return $sheet;
    }

    private function columnLetter(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }
}
