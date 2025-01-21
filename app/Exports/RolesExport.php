<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Spatie\Permission\Models\Role;

class RolesExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;
    protected $search;
    public function __construct($search = null)
    {
        $this->search = $search;
    }
    public function query()
    {
        $query = Role::query()->with('permissions');

        if ($this->search)
            $query->where('name', 'like', "%$this->search%");

        return $query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Permissions',
            'Created At',
        ];
    }

    public function map($role): array
    {
        return [
            $role->id,
            $role->name,
            $role->permissions->pluck('name')->implode(', '),
            $role->created_at->format('Y-m-d H:i:s')
        ];
    }
}
