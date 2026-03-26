<?php

namespace App\Exports;

use App\Models\StoreBranch;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SalesBudgetTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'Store Code',
            'Store Name',
            'Jan',
            'Feb',
            'Mar',
            'Apr',
            'May',
            'Jun',
            'Jul',
            'Aug',
            'Sep',
            'Oct',
            'Nov',
            'Dec',
        ];
    }

    public function array(): array
    {
        $user = Auth::user();
        $query = StoreBranch::where('is_active', true);

        if ($user) {
            $user->load(['roles', 'store_branches']);
            $hasAdmin = $user->roles->contains('name', 'admin');
            $assignedBranches = $user->store_branches->pluck('id')->toArray();

            if (!$hasAdmin) {
                $query->whereIn('id', $assignedBranches);
            }
        }

        $branches = $query->orderBy('name')->get();
        
        $data = [];
        foreach ($branches as $branch) {
            $data[] = [
                $branch->branch_code,
                $branch->name,
                '', '', '', '', '', '', '', '', '', '', '', ''
            ];
        }

        return $data;
    }
}
