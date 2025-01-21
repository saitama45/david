<?php

namespace App\Exports;

use App\Models\StoreBranch;
use App\Traits\UseReferenceExport;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StoreBranchesExport implements FromQuery, WithHeadings, WithMapping
{
    use UseReferenceExport;

    protected function getModel()
    {
        return StoreBranch::class;
    }
}
