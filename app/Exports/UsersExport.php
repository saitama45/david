<?php

namespace App\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class UsersExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;
    protected $search;

    protected $role;

    public function __construct($search = null, $role = null)
    {
        $this->search = $search;
        $this->role = $role;
    }

    public function query()
    {
        $query = User::query()->with('roles');

        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->whereAny(['first_name', 'last_name', 'email'], 'like', "%{$search}%");
            });
        }

        if ($this->role) {
            $role = $this->role;
            $query->whereHas('roles', function ($q) use ($role) {
                $q->where('roles.id', $role);
            });
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'First Name',
            'Last Name',
            'Email',
            'Roles',
            'Created At'
        ];
    }

    public function map($user): array
    {
        return [
            $user->id,
            $user->first_name,
            $user->last_name,
            $user->email,
            $user->roles->pluck('name')->implode(', '),
            $user->created_at->format('Y-m-d H:i:s')
        ];
    }
}
