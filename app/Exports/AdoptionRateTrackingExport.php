<?php

namespace App\Exports;

use App\Http\Services\AdoptionRateTrackingService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AdoptionRateTrackingExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private Collection $rows, private string $tabKey)
    {
    }

    public function collection(): Collection
    {
        if ($this->tabKey === AdoptionRateTrackingService::TAB_OVERALL_ADOPTION_RATE) {
            return $this->overallRows();
        }

        return $this->rows;
    }

    public function headings(): array
    {
        if ($this->tabKey === AdoptionRateTrackingService::TAB_OVERALL_ADOPTION_RATE) {
            return array_merge(
                ['Store', 'No.', 'Indicator', 'Responsible'],
                $this->overallWeeks()->pluck('label')->all(),
                ['Overall']
            );
        }

        if ($this->tabKey === AdoptionRateTrackingService::TAB_COMMIT_ORDER_TIMELINESS) {
            return [
                'Week No.',
                'Date Range',
                'Store',
                'Supplier Code',
                'Delivery Date',
                'DAVID Commit Date (FG)',
                'On Time? For FG',
                'DAVID Commit Date (Traded)',
                'On Time? For Traded',
                'Remarks',
            ];
        }

        if ($this->tabKey === AdoptionRateTrackingService::TAB_DELIVERY_LOGGING_TIMELINESS) {
            return [
                'Week No.',
                'Date Range',
                'Supplier Code',
                'Store',
                'DR Number',
                'SO/PO Number',
                'SAP DR Date',
                'DAVID Logging Date',
                'On Time?',
                'Remarks',
            ];
        }

        if ($this->tabKey === AdoptionRateTrackingService::TAB_SALES_UPLOAD_TIMELINESS) {
            return [
                'Week No.',
                'Date Range',
                'Date of Sales',
                'Date of Actual Sales Upload',
                'Store',
                'Sales Report Uploaded?',
                'Remarks',
            ];
        }

        if ($this->tabKey === AdoptionRateTrackingService::TAB_WASTAGE_UPLOAD_TIMELINESS) {
            return [
                'Week No.',
                'Date Range',
                'Wastage #',
                'Status',
                'Date of Wastage',
                'Date of Wastage Upload',
                'Level 1 Approval',
                'Level 2 Approval',
                'Store',
                'Wastage Report Uploaded?',
                'Wastage Report Approved?',
                'Remarks',
            ];
        }

        return [
            'Week No.',
            'Date Range',
            'Supplier Code',
            'Store',
            'DAVID Delivery Date',
            'Plotted?',
            'Remarks',
        ];
    }

    public function map($row): array
    {
        if ($this->tabKey === AdoptionRateTrackingService::TAB_OVERALL_ADOPTION_RATE) {
            return $row;
        }

        if ($this->tabKey === AdoptionRateTrackingService::TAB_COMMIT_ORDER_TIMELINESS) {
            return [
                $row['week_no'],
                $row['date_range'],
                $row['store'],
                $row['supplier_code'],
                $row['delivery_date_display'],
                $row['fg_commit_date_display'],
                $row['fg_on_time'],
                $row['traded_commit_date_display'],
                $row['traded_on_time'],
                $row['remarks'],
            ];
        }

        if ($this->tabKey === AdoptionRateTrackingService::TAB_DELIVERY_LOGGING_TIMELINESS) {
            return [
                $row['week_no'],
                $row['date_range'],
                $row['supplier_code'],
                $row['store'],
                $row['dr_number'],
                $row['so_po_number'],
                $row['sap_dr_date_display'],
                $row['david_logging_date_display'],
                $row['on_time'],
                $row['remarks'],
            ];
        }

        if ($this->tabKey === AdoptionRateTrackingService::TAB_SALES_UPLOAD_TIMELINESS) {
            return [
                $row['week_no'],
                $row['date_range'],
                $row['date_of_sales_display'],
                $row['actual_sales_upload_date_display'],
                $row['store'],
                $row['sales_report_uploaded'],
                $row['remarks'],
            ];
        }

        if ($this->tabKey === AdoptionRateTrackingService::TAB_WASTAGE_UPLOAD_TIMELINESS) {
            return [
                $row['week_no'],
                $row['date_range'],
                $row['wastage_no'],
                $row['status'],
                $row['date_of_wastage_display'],
                $row['date_of_wastage_upload_display'],
                $row['level_1_approval'],
                $row['level_2_approval'],
                $row['store'],
                $row['wastage_report_uploaded'],
                $row['wastage_report_approved'],
                $row['remarks'],
            ];
        }

        return [
            $row['week_no'],
            $row['date_range'],
            $row['supplier_code'],
            $row['store'],
            $row['david_delivery_date_display'],
            $row['plotted'],
            $row['remarks'],
        ];
    }

    private function overallWeeks(): Collection
    {
        return collect($this->rows->first()['weeks'] ?? []);
    }

    private function overallRows(): Collection
    {
        $weeks = $this->overallWeeks();

        return $this->rows->flatMap(function (array $section) use ($weeks) {
            $rows = collect($section['indicators'])->map(function (array $indicator) use ($section, $weeks) {
                return array_merge(
                    [
                        $section['store'],
                        $indicator['no'],
                        $indicator['indicator'],
                        $indicator['responsible'],
                    ],
                    $weeks->map(fn (array $week) => $this->formatRate($indicator['rates'][$week['key']] ?? null))->all(),
                    [$this->formatRate($indicator['overall_rate'] ?? null)]
                );
            });

            $rows->push(array_merge(
                [$section['store'], '', 'Average', ''],
                $weeks->map(fn (array $week) => $this->formatRate($section['weekly_averages'][$week['key']] ?? null))->all(),
                [$this->formatRate($section['overall_rate'])]
            ));

            return $rows;
        })->values();
    }

    private function formatRate($rate): string
    {
        return $rate === null ? 'N/A' : rtrim(rtrim(number_format((float) $rate, 2), '0'), '.') . '%';
    }
}
