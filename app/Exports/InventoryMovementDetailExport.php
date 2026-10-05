<?php

namespace App\Exports;

use App\Http\Services\InventoryMovementDetailService;
use App\Support\ReportNumber;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel twin of the popup a figure of the Inventory Movement Report opens — the same
 * columns, the same total and the same notes, but every line of the figure instead of one
 * page of them, with dates and quantities as real values and each Ref No. linked to the
 * transaction it opens.
 *
 * WithStrictNullComparison keeps a zero quantity as 0 instead of a blank cell.
 */
class InventoryMovementDetailExport implements FromArray, ShouldAutoSize, WithCustomStartCell, WithEvents, WithStrictNullComparison, WithTitle
{
    private const HEADING_ROW = 4;

    private const FIRST_DATA_ROW = 5;

    private const LAST_COLUMN = 'F';

    private const DATE_FORMAT = 'mmm d, yyyy';

    /**
     * @param  array  $details  InventoryMovementDetailService::details() asked for every line (no paging)
     * @param  array{date_from: string, date_to: string}  $filters
     */
    public function __construct(
        private array $details,
        private string $metric,
        private string $itemCode,
        private string $itemDescription,
        private $branch,
        private array $filters,
        private string $generatedBy,
        private string $generatedAt
    ) {}

    public function array(): array
    {
        return array_map(fn (array $row) => [
            $row['date'] ? Date::PHPToExcel(Carbon::parse($row['date'])) : '-',
            $row['ref_no'] ?: '-',
            $row['details'],
            (float) $row['quantity'],
            $row['uom'] ?: '-',
            // A unit with no SAP conversion is left out of the figure, as the popup says.
            $row['converted'] === null ? 'Excluded' : (float) $row['converted'],
        ], $this->details['rows']);
    }

    public function title(): string
    {
        return InventoryMovementDetailService::LABELS[$this->metric][0];
    }

    public function startCell(): string
    {
        return 'A'.self::FIRST_DATA_ROW;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $last = self::LAST_COLUMN;
                $uom = $this->details['uom'];
                [$label, $dateLabel] = InventoryMovementDetailService::LABELS[$this->metric];
                $lines = count($this->details['rows']);
                $totalRow = self::FIRST_DATA_ROW + $lines;

                // Rows 1 to 3: what the popup's header and summary bar say.
                foreach ([
                    1 => trim("{$label}: {$this->itemCode} {$this->itemDescription}"),
                    2 => ($this->branch ? $this->branch->name.' ('.$this->branch->branch_code.')' : 'N/A')
                        .'  |  '.Carbon::parse($this->filters['date_from'])->format('M j, Y')
                        .' to '.Carbon::parse($this->filters['date_to'])->format('M j, Y'),
                    3 => 'Total: '.ReportNumber::format($this->details['total']).' '.$uom
                        .'  |  Generated: '.$this->generatedAt
                        .'  |  By: '.$this->generatedBy,
                ] as $row => $text) {
                    $sheet->mergeCells("A{$row}:{$last}{$row}");
                    $sheet->setCellValue("A{$row}", $text);
                }

                foreach ([$dateLabel, 'Ref No.', 'Details', 'Qty', 'UOM', 'In '.$uom] as $index => $heading) {
                    $sheet->setCellValue(chr(65 + $index).self::HEADING_ROW, $heading);
                }

                // Each Ref No. opens its transaction, as it does in the popup.
                foreach (array_values($this->details['rows']) as $index => $line) {
                    if (empty($line['ref_url'])) {
                        continue;
                    }

                    $cell = 'B'.(self::FIRST_DATA_ROW + $index);
                    $sheet->getCell($cell)->getHyperlink()->setUrl($line['ref_url']);
                    $sheet->getStyle($cell)->getFont()->setUnderline(true)->getColor()->setRGB('1D4ED8');
                }

                $sheet->mergeCells("A{$totalRow}:E{$totalRow}");
                $sheet->setCellValue("A{$totalRow}", 'Total of all '.$this->details['total_rows'].' line'.($this->details['total_rows'] === 1 ? '' : 's'));
                $sheet->setCellValue("F{$totalRow}", (float) $this->details['total']);

                $row = $this->writeNotes($sheet, $totalRow + 2);
                $this->writeCalculation($sheet, $row, $label, $uom);
                $this->style($sheet, $lines, $totalRow);
            },
        ];
    }

    /**
     * The popup's notes, each across the sheet: why some lines are not in the figure, and
     * what Supplies Used means.
     *
     * @return int the next free row
     */
    private function writeNotes(Worksheet $sheet, int $row): int
    {
        $notes = array_filter([
            $this->details['unconverted_units']
                ? 'Lines in '.implode(', ', $this->details['unconverted_units']).' have no SAP conversion to '.$this->details['uom'].' and are not in the figure.'
                : null,
            $this->details['note'],
        ]);

        foreach ($notes as $note) {
            $sheet->mergeCells("A{$row}:".self::LAST_COLUMN.$row);
            $sheet->setCellValue("A{$row}", $note);
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            // A merged cell does not grow with its text.
            $sheet->getRowDimension($row)->setRowHeight(32);
            $row++;
        }

        return $notes ? $row + 1 : $row;
    }

    /** Supplies Used has no transaction: the popup shows how it was worked out, and so does the sheet. */
    private function writeCalculation(Worksheet $sheet, int $row, string $label, string $uom): void
    {
        if (! $this->details['calculation']) {
            return;
        }

        $sheet->mergeCells("A{$row}:".self::LAST_COLUMN.$row);
        $sheet->setCellValue("A{$row}", "How {$label} was worked out ({$uom})");
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);

        foreach ($this->details['calculation'] as $line) {
            $row++;
            $sheet->setCellValue("A{$row}", $line['sign']);
            $sheet->mergeCells("B{$row}:E{$row}");
            $sheet->setCellValue("B{$row}", $line['label']);
            $sheet->setCellValue("F{$row}", (float) $line['value']);
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("F{$row}")->applyFromArray([
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                'numberFormat' => ['formatCode' => ReportNumber::EXCEL],
            ]);

            if ($line['sign'] === '=') {
                $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '9CA3AF']]],
                ]);
            }
        }
    }

    private function style(Worksheet $sheet, int $lines, int $totalRow): void
    {
        $last = self::LAST_COLUMN;
        $heading = self::HEADING_ROW;
        $centered = ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER];

        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => $centered,
        ]);
        $sheet->getStyle("A2:{$last}3")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F0FE']],
            'font' => ['bold' => true, 'size' => 11],
            'alignment' => $centered,
        ]);
        $sheet->getStyle("A{$heading}:{$last}{$heading}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B8D4F1']],
            'font' => ['bold' => true, 'size' => 10],
            'alignment' => $centered,
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->getRowDimension(3)->setRowHeight(20);

        $sheet->getStyle('A'.self::FIRST_DATA_ROW.":{$last}{$totalRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
        ]);

        if ($lines > 0) {
            $lastLine = $totalRow - 1;

            $sheet->getStyle('A'.self::FIRST_DATA_ROW.":A{$lastLine}")->applyFromArray([
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
                'numberFormat' => ['formatCode' => self::DATE_FORMAT],
            ]);
            $sheet->getStyle('B'.self::FIRST_DATA_ROW.":C{$lastLine}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle('E'.self::FIRST_DATA_ROW.":E{$lastLine}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // Both quantities right-aligned with four decimals, the total included.
        foreach (['D', 'F'] as $column) {
            $sheet->getStyle($column.self::FIRST_DATA_ROW.":{$column}{$totalRow}")->applyFromArray([
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                'numberFormat' => ['formatCode' => ReportNumber::EXCEL],
            ]);
        }

        $sheet->getStyle("A{$totalRow}:{$last}{$totalRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
            'font' => ['bold' => true],
        ]);
        $sheet->getStyle("A{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Keep the headings visible while scrolling a long list.
        $sheet->freezePane('A'.self::FIRST_DATA_ROW);
    }
}
