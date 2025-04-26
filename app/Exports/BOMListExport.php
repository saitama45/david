<?php

namespace App\Exports;

use App\Models\Menu;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class BOMListExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    protected $search;

    public function __construct($search = null)
    {
        $this->search = $search;
    }
    public function query()
    {
        $query = Menu::latest()->with('category');

        if ($this->search)
            $query->whereAny(['name', 'product_id'], 'like', "%$this->search%");

        return $query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Product ID',
            'Name',
            'Price'
        ];
    }

    public function map($row): array
    {
        return [
            $row->id,
            $row->product_id,
            $row->name,
            $row->price
        ];
    }
}
