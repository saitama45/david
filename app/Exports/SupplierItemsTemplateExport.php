<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Supplier Items upload template: three highlighted example rows, then the
 * user's current items. Example rows carry a SAMPLE- item code, which
 * SupplierItemsImport drops, so re-uploading the file unchanged is harmless.
 */
class SupplierItemsTemplateExport implements FromCollection, WithHeadings, WithStyles
{
    public const SAMPLE_PREFIX = 'SAMPLE-';

    private const SAMPLE_COUNT = 3;

    private SupplierItemsExport $items;

    public function __construct(private array $assignedSupplierCodes = [])
    {
        $this->items = new SupplierItemsExport(null, null, $assignedSupplierCodes);
    }

    public static function isSampleRow($itemCode): bool
    {
        return str_starts_with(strtoupper(trim((string) $itemCode)), self::SAMPLE_PREFIX);
    }

    public function headings(): array
    {
        return $this->items->headings();
    }

    public function collection()
    {
        $code = $this->assignedSupplierCodes[0] ?? 'SUPPCODE';

        return collect([
            ['PASTRY', 'BREAD', 'KITCHEN', 'NONOS', 'FOOD', 'SAMPLE-0001', 'Sample Butter Croissant', '12 PCS/BOX', 'BOX', 450.00, 0, $code, 1, 1],
            ['PASTRY', 'CAKE', 'KITCHEN', 'NONOS', 'FOOD', 'SAMPLE-0002', 'Sample Chocolate Cake Slice', '1 PC', 'PC', 85.50, 0, $code, 2, 1],
            ['PACKAGING', 'BOX', 'COUNTER', 'NONOS', 'NON-FOOD', 'SAMPLE-0003', 'Sample Pastry Box', '50 PCS/PACK', 'PACK', 320.00, 0, $code, 3, 0],
        ])->concat($this->items->query()->get()->map(fn ($item) => $this->items->map($item)));
    }

    public function styles(Worksheet $sheet)
    {
        $this->items->styles($sheet);

        $sheet->getStyle('A2:N'.(1 + self::SAMPLE_COUNT))->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
            'font' => ['italic' => true, 'color' => ['rgb' => '7F6000']],
        ]);

        $sheet->getComment('F1')->getText()->createTextRun(
            'Yellow rows are examples (Item Code starting with SAMPLE-) and are ignored on upload. '
            .'Item Code + Unit must exist in the SAP masterfile; Supplier Code must be assigned to you; ACTIVE is 1 or 0.'
        );

        foreach (range('A', 'N') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
}
