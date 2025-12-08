<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class StoreTransactionSkippedExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize
{
    protected $skippedItems;

    public function __construct(array $skippedItems)
    {
        $this->skippedItems = $skippedItems;
    }

    public function array(): array
    {
        // Map the data to the specific column order requested
        return array_map(function ($item) {
            $dateOfSales = $item['date_of_sales'] ?? '';
            if ($dateOfSales) {
                // Handle numeric Excel serial dates (e.g., 45999)
                if (is_numeric($dateOfSales)) {
                    $dateOfSales = \Carbon\Carbon::createFromDate(1900, 1, 1)->addDays((int)$dateOfSales - 2)->format('m/d/Y');
                } else {
                    $dateOfSales = \Carbon\Carbon::parse($dateOfSales)->format('m/d/Y');
                }
            }
            
            return [
                $item['item_code'] ?? '',
                $item['item_description'] ?? '',
                $item['uom'] ?? '',
                $item['store_code'] ?? '',
                $item['receipt_number'] ?? '', // Added Receipt No.
                $item['qty'] ?? '', // This represents Total Qty
                $item['bom_qty_deduction'] ?? '',
                $item['total_deduction'] ?? '',
                $item['current_soh'] ?? '',
                $item['variance'] ?? '',
                $dateOfSales,
                $item['reason'] ?? '',
            ];
        }, $this->skippedItems);
    }

    public function headings(): array
    {
        return [
            'Item Code',
            'Item Description',
            'UoM',
            'Store Code',
            'Receipt No.',
            'Total Qty',
            'BOM Qty Deduction',
            'Total Deduction',
            'Current SOH',
            'Variance (insufficient balance)',
            'Date of Sales',
            'Reason',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold text with light blue background
            1    => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'E0F2FE'], // Light blue (Tailwind blue-100 equivalent approx)
                ],
            ],
            // Format Date of Sales column (K) as short date
            'K' => [
                'numberFormat' => [
                    'formatCode' => NumberFormat::FORMAT_DATE_XLSX14,
                ],
            ],
        ];
    }
}
