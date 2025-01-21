<?php

namespace App\Exports;

use App\Models\ProductCategory;
use App\Traits\UseReferenceExport;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Calculation\Category;

class ProductCategoriesExport implements FromQuery, WithHeadings, WithMapping
{
    use UseReferenceExport;

    protected function getModel()
    {
        return ProductCategory::class;
    }
}
