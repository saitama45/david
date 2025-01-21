<?php

namespace App\Exports;

use App\Models\Supplier;
use App\Traits\UseReferenceExport;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class SuppliersExport implements FromQuery, WithHeadings, WithMapping
{
    use UseReferenceExport;

    protected function getModel()
    {
        return Supplier::class;
    }
}
