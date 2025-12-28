<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class POSMasterfileTemplateExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        // Return an empty collection as we only want the headers for the template
        return collect([]);
    }

    public function headings(): array
    {
        return [
            'Product ID',
            'POS Desc',
            'Category',
            'SubCategory',
            ' SRP ',
            'Active',
        ];
    }
}
