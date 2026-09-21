<?php

namespace App\Exports;

use App\Imports\SAPMasterfileImport;
use App\Models\SapItemType;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Upload template for the SAP masterlist: one sheet, headings that slug to the
 * keys SAPMasterfileImport reads, an Item Type dropdown, and three sample rows.
 *
 * The samples use SAMPLE- item codes, which the import refuses, so a template
 * uploaded without deleting them cannot add dummy items to the masterfile.
 */
class SAPMasterfileTemplateExport implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    /** Rows the dropdown covers; generous for a masterlist upload. */
    private const VALIDATED_ROWS = 10000;

    /** Excel's limit on an inline list, separators included. */
    private const INLINE_LIST_LIMIT = 255;

    private array $types;

    public function __construct()
    {
        $this->types = SapItemType::active()->orderBy('name')->pluck('name')->all();
    }

    public function headings(): array
    {
        return ['Item No.', 'Item Description', 'AltQty', 'BaseQty', 'AltUom', 'BaseUom', 'Active', 'Item Type'];
    }

    public function array(): array
    {
        $food = $this->type('FOOD', 0);
        $supplies = $this->type('OPERATING SUPPLIES', 1);
        $prefix = SAPMasterfileImport::SAMPLE_PREFIX;

        return [
            // The base row: AltUom equals BaseUom, one unit is one unit.
            ["{$prefix}001", 'SAMPLE - Tomato Sauce', 1, 1, 'KG', 'KG', 1, $food],
            // Another UOM of the same item: a case holds 12 KG. Same code, same base, same type.
            ["{$prefix}001", 'SAMPLE - Tomato Sauce', 1, 12, 'CASE(12)', 'KG', 1, $food],
            // A different item. Leaving Item Type blank would keep an existing item's type.
            ["{$prefix}002", 'SAMPLE - Kitchen Gloves', 1, 1, 'PC', 'PC', 1, $supplies],
        ];
    }

    public function title(): string
    {
        return 'SAP Items';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->getStyle('A1:H1')->getFont()->setBold(true);
                $sheet->getStyle('A2:H4')->getFont()->setItalic(true)->getColor()->setARGB('FF808080');
                $sheet->getComment('A1')->getText()->createTextRun(
                    'Rows 2-4 are samples to follow. Delete them before uploading - SAMPLE- item codes are never imported.'
                );
                $sheet->getComment('H1')->getText()->createTextRun(
                    'Pick from the list. Leave blank to keep the item\'s current type.'
                );

                if ($this->types) {
                    $sheet->setDataValidation('H2:H'.(self::VALIDATED_ROWS + 1), $this->typeValidation($sheet));
                }
            },
        ];
    }

    private function typeValidation(Worksheet $sheet): DataValidation
    {
        $validation = (new DataValidation)
            ->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Unknown Item Type')
            ->setError('Choose an Item Type from the list, or leave it blank.');

        $inline = implode(',', $this->types);
        $fitsInline = strlen($inline) + 2 <= self::INLINE_LIST_LIMIT
            && ! collect($this->types)->contains(fn ($name) => str_contains($name, ',') || str_contains($name, '"'));

        if ($fitsInline) {
            return $validation->setFormula1('"'.$inline.'"');
        }

        // Too long for an inline list, or a name contains its separator: read the
        // list from a sheet Excel keeps hidden from the user.
        $list = $sheet->getParent()->createSheet()->setTitle('ItemTypes');
        foreach ($this->types as $i => $name) {
            $list->setCellValue('A'.($i + 1), $name);
        }
        $list->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

        return $validation->setFormula1('ItemTypes!$A$1:$A$'.count($this->types));
    }

    /** A sample's type: the named one if it is active, else the Nth active type, else blank. */
    private function type(string $preferred, int $fallback): string
    {
        return in_array($preferred, $this->types, true) ? $preferred : ($this->types[$fallback] ?? '');
    }
}
