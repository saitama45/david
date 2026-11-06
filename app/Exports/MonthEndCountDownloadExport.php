<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Strict null comparison: without it the writer treats a Current SOH of 0 as an empty cell.
class MonthEndCountDownloadExport implements FromCollection, WithHeadings, WithStrictNullComparison, WithStyles
{
    /** The columns the store fills in, always the last three. */
    private const FILLABLE = ['Bulk Qty', 'Loose Qty', 'Remarks'];

    protected $items;

    /**
     * @param  bool  $withCurrentSoh  whether the template carries the Current SOH column
     *                                (config month_end_count.template_current_soh)
     */
    public function __construct(Collection $items, private bool $withCurrentSoh = true)
    {
        $this->items = $items;
    }

    public function collection()
    {
        // Blank text and the user-fillable columns stay empty cells, as before.
        return $this->items->map(fn ($row) => array_map(fn ($value) => $value === '' ? null : $value, $row));
    }

    public function headings(): array
    {
        return [
            'Item Code',
            'Item Name',
            'Category 1',
            'Area',
            'Category 2',
            'Packaging',
            'Conversion',
            'Bulk UOM',
            'Loose UOM',
            ...($this->withCurrentSoh ? ['Current SOH'] : []),
            ...self::FILLABLE,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $headings = $this->headings();
        $last = Coordinate::stringFromColumnIndex(count($headings));

        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => '87CEEB',
                ],
            ],
            'font' => [
                'bold' => true,
                'size' => 12,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // Color the user-fillable columns
        foreach (self::FILLABLE as $heading) {
            $column = Coordinate::stringFromColumnIndex(array_search($heading, $headings, true) + 1);
            $sheet->getStyle("{$column}1")->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => [
                        'rgb' => 'FFFF00', // Yellow
                    ],
                ],
            ]);
        }
    }
}
