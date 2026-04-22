<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class OrderCalculatorExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    protected $items;
    protected $headers;

    public function __construct(Collection $items, array $headers)
    {
        $this->items = $items;
        $this->headers = $headers;
    }

    public function collection()
    {
        return $this->items;
    }

    public function headings(): array
    {
        return [
            ['ORDER CALCULATOR COMPUTATION'],
            [$this->headers['store_name'] . ' | ' . $this->headers['template_name']],
            ['Forecasting Week: ' . $this->headers['start_week'] . ' | Target DTL: ' . $this->headers['target_dtl']],
            [''],
            [
                'CODE',
                'NAME',
                'CAT',
                'BRAND',
                'CLASS',
                'PKG',
                'UNIT',
                'SUNDAY E.I.',
                'INCOMING',
                'INC %',
                'ADU',
                'ADU DTL1',
                'ADU DTL2',
                'REV. ADU',
                'ADU SUGG',
                'DAILY PMIX',
                'PMIX DTL1',
                'PMIX DTL2',
                'REV. PMIX',
                'PMIX SUGG'
            ]
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Title styles
        $sheet->mergeCells('A1:T1');
        $sheet->mergeCells('A2:T2');
        $sheet->mergeCells('A3:T3');
        
        $sheet->getStyle('A1:A3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        // Header styles
        $headerRange = 'A5:T5';
        $sheet->getStyle($headerRange)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2E8F0'],
            ],
            'font' => ['bold' => true],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        // ADU Section highlight
        $sheet->getStyle('K5:O5')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E7FF'],
            ],
            'font' => ['color' => ['rgb' => '4338CA']],
        ]);

        // PMIX Section highlight
        $sheet->getStyle('P5:T5')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'DCFCE7'],
            ],
            'font' => ['color' => ['rgb' => '15803D']],
        ]);

        // Data alignment and borders
        $lastRow = $this->items->count() + 5;
        $sheet->getStyle('A6:T' . $lastRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CBD5E1'],
                ],
            ],
        ]);

        $sheet->getStyle('H6:T' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
}
