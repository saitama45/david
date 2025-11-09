<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Carbon\Carbon;

class WastageExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [
            'Wastage No',
            'Store Branch',
            'Item Code',
            'Item Description',
            'Wastage Quantity',
            'UoM',
            'Cost per Unit',
            'Total Cost',
            'Wastage Reason',
            'Remarks',
            'Status',
            'Encoded Date',
            'Encoded By',
            'Approved Level 1 Date',
            'Approved Level 1 By',
            'Approved Level 2 Date',
            'Approved Level 2 By',
            'Cancelled Date',
            'Cancelled By',
        ];
    }

    /**
     * @param mixed $wastage
     * @return array
     */
    public function map($wastage): array
    {
        return [
            $wastage->wastage_no ?? '',
            $wastage->storeBranch?->name ?? 'Unknown Store',
            $wastage->sapMasterfile?->ItemCode ?? '',
            $wastage->sapMasterfile?->ItemDescription ?? '',
            $wastage->wastage_qty ?? 0,
            $wastage->sapMasterfile?->BaseUOM ?? '',
            $wastage->cost ?? 0,
            $wastage->total_cost ?? 0,
            $wastage->wastage_reason ?? '',
            $wastage->remarks ?? '',
            $wastage->status_label ?? $wastage->wastage_status,
            $wastage->encoded_date ? Carbon::parse($wastage->encoded_date)->format('Y-m-d H:i:s') : '',
            $wastage->encoder?->name ?? '',
            $wastage->approved_lvl1_date ? Carbon::parse($wastage->approved_lvl1_date)->format('Y-m-d H:i:s') : '',
            $wastage->approver1?->name ?? '',
            $wastage->approved_lvl2_date ? Carbon::parse($wastage->approved_lvl2_date)->format('Y-m-d H:i:s') : '',
            $wastage->approver2?->name ?? '',
            $wastage->cancelled_date ? Carbon::parse($wastage->cancelled_date)->format('Y-m-d H:i:s') : '',
            $wastage->canceller?->name ?? '',
        ];
    }

    /**
     * Apply styles to the worksheet
     */
    public function styles(Worksheet $sheet)
    {
        // Apply header styling (light red background for wastage, bold text, centered)
        $sheet->getStyle('A1:S1')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => 'FFB6C1', // Light red background for wastage
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

        // Set column widths for better readability
        $sheet->getColumnDimension('A')->setWidth(18);  // Wastage No
        $sheet->getColumnDimension('B')->setWidth(25);  // Store Branch
        $sheet->getColumnDimension('C')->setWidth(15);  // Item Code
        $sheet->getColumnDimension('D')->setWidth(40);  // Item Description
        $sheet->getColumnDimension('E')->setWidth(15);  // Wastage Quantity
        $sheet->getColumnDimension('F')->setWidth(10);  // UoM
        $sheet->getColumnDimension('G')->setWidth(15);  // Cost per Unit
        $sheet->getColumnDimension('H')->setWidth(15);  // Total Cost
        $sheet->getColumnDimension('I')->setWidth(30);  // Wastage Reason
        $sheet->getColumnDimension('J')->setWidth(25);  // Remarks
        $sheet->getColumnDimension('K')->setWidth(15);  // Status
        $sheet->getColumnDimension('L')->setWidth(20);  // Encoded Date
        $sheet->getColumnDimension('M')->setWidth(20);  // Encoded By
        $sheet->getColumnDimension('N')->setWidth(20);  // Approved Level 1 Date
        $sheet->getColumnDimension('O')->setWidth(20);  // Approved Level 1 By
        $sheet->getColumnDimension('P')->setWidth(20);  // Approved Level 2 Date
        $sheet->getColumnDimension('Q')->setWidth(20);  // Approved Level 2 By
        $sheet->getColumnDimension('R')->setWidth(20);  // Cancelled Date
        $sheet->getColumnDimension('S')->setWidth(20);  // Cancelled By

        // Wrap text for longer fields
        $sheet->getStyle('I:J')->getAlignment()->setWrapText(true);

        // Format currency columns
        $sheet->getStyle('G:H')->getNumberFormat()->setFormatCode('#,##0.00');

        // Format date columns
        $sheet->getStyle('L:S')->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm:ss');

        // Center align certain columns
        $sheet->getStyle('A:F:K')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }
}