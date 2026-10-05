<?php

namespace App\Exports;

use App\Models\SAPMasterfile;
use App\Support\ItemStockUnit;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * The SOH Update file: every active item of the SAP Masterlist once per unit, to fill in a
 * variance (+ adds, - deducts) in that unit. The upload reads the ID and the Item Code.
 */
class StockMangementSOHExport implements FromCollection, WithHeadings, WithMapping
{
    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return ItemStockUnit::onePerUnit(
            SAPMasterfile::where('is_active', true)
                ->whereNotNull('AltUOM')
                ->where('AltUOM', '!=', '')
                ->get(['id', 'ItemCode', 'ItemDescription', 'AltUOM', 'BaseUOM'])
        )->sortBy(fn ($row) => [$row->ItemDescription, $row->ItemCode, $row->AltUOM])->values();
    }

    public function headings(): array
    {
        return [
            'ID',
            'Item Code',
            'Item Description',
            'UOM',
            'Variance',
            'Remarks'
        ];
    }

    public function map($row): array
    {
        return [
            $row->id,
            $row->ItemCode,
            $row->ItemDescription,
            $row->AltUOM,
            0,
            ''
        ];
    }
}
