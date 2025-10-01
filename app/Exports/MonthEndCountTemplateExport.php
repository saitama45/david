<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class MonthEndCountTemplateExport implements FromCollection, WithHeadings
{
    protected $items;

    public function __construct(Collection $items)
    {
        $this->items = $items;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return $this->items;
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'ItemCode',
            'item_name',
            'packaging_config',
            'config',
            'uom',
            'bulk_qty',
            'loose_qty',
            'loose_uom',
            'remarks',
            'total_qty',
            'sap_masterfile_id', // Hidden field for internal use
        ];
    }
}