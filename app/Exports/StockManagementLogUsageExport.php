<?php

namespace App\Exports;

use App\Models\CostCenter;
use App\Models\ProductInventory;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;


class StockManagementLogUsageExport implements FromCollection, WithHeadings, WithMapping, WithEvents
{
    use Exportable;

    public function collection()
    {
        return ProductInventory::get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'inventory_code' => $item->inventory_code,
                    'quantity' => 0,
                    'unit_cost' => 0,
                    'transaction_date' => now()->format('Y-m-d')
                ];
            });
    }

    public function headings(): array
    {
        return [
            'ID',
            'Product Name',
            'Inventory Code',
            'Quantity',
            'Cost Center',
            'Transaction Date',
            'Remarks'
        ];
    }

    public function map($row): array
    {
        return [
            $row['id'],
            $row['name'],
            $row['inventory_code'],
            $row['quantity'],
            '', // Cost Center (column E)
            $row['transaction_date'],
            '' // Remarks
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $rowCount = ProductInventory::count() + 1;

                // Get cost centers
                $costCenters = CostCenter::select(['id', 'name'])->get();

                // Create a hidden sheet for the lookup data
                $workbook = $sheet->getParent();
                $lookupSheet = $workbook->createSheet();
                $lookupSheet->setTitle('CostCenterLookup');

                // Add headers to lookup sheet
                $lookupSheet->setCellValue('A1', 'ID');
                $lookupSheet->setCellValue('B1', 'Name');

                // Add cost center data to lookup sheet
                $row = 2;
                foreach ($costCenters as $costCenter) {
                    $lookupSheet->setCellValue('A' . $row, $costCenter->id);
                    $lookupSheet->setCellValue('B' . $row, $costCenter->name);
                    $row++;
                }

                // Define named ranges for the lists
                $lastRow = count($costCenters) + 1;
                $workbook->addNamedRange(new NamedRange('CostCenterNames', $lookupSheet, '$B$2:$B$' . $lastRow));

                // Apply dropdown validation on the main sheet for Cost Center column (E)
                $validation = $sheet->getCell('E2')->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
                $validation->setAllowBlank(false);
                $validation->setShowInputMessage(true);
                $validation->setShowErrorMessage(true);
                $validation->setShowDropDown(true);
                $validation->setFormula1('CostCenterNames');

                // Copy validation to all rows
                for ($i = 2; $i <= $rowCount; $i++) {
                    $sheet->getCell('E' . $i)->setDataValidation(clone $validation);
                }

                // Add a helper column to lookup the ID value (hidden)
                // Create a separate hidden column H for Cost Center ID
                $sheet->setCellValue('H1', 'Cost Center ID');
                for ($i = 2; $i <= $rowCount; $i++) {
                    // Use column E (Cost Center) in the VLOOKUP formula
                    $sheet->setCellValue('H' . $i, '=VLOOKUP(E' . $i . ',CostCenterLookup!$B$2:$A$' . $lastRow . ',2,FALSE)');
                }

                // Hide the lookup sheet and helper column
                $lookupSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);
                $sheet->getColumnDimension('H')->setVisible(false);
            }
        ];
    }
}
