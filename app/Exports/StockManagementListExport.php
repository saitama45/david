<?php

namespace App\Exports;

use App\Models\ProductInventory;
use App\Models\StoreBranch;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StockManagementListExport implements FromCollection, WithHeadings, WithMapping
{
    use Exportable;

    protected $search;
    protected $branchId;
    public function __construct($search = null, $branchId = null)
    {
        $this->search = $search;
        $this->branchId = $branchId;
    }

    public function collection()
    {
        $branches = StoreBranch::options();
        $branchId = $this->branchId ?? $branches->keys()->first();

        $usageRecords = DB::table('usage_records as ur')
            ->join('usage_record_items as uri', 'ur.id', '=', 'uri.usage_record_id')
            ->join('menus as m', 'uri.menu_id', '=', 'm.id')
            ->join('menu_ingredients as mi', 'm.id', '=', 'mi.menu_id')
            ->where('ur.store_branch_id', $branchId)
            ->select(
                'mi.product_inventory_id',
                DB::raw(
                    DB::connection()->getDriverName() === 'sqlsrv'
                        ? 'CAST(SUM(CAST(mi.quantity AS DECIMAL(10,2)) * CAST(uri.quantity AS DECIMAL(10,2))) AS DECIMAL(10,2)) as total_quantity_used'
                        : 'SUM(mi.quantity * uri.quantity) as total_quantity_used'
                ),
                DB::raw(
                    DB::connection()->getDriverName() === 'sqlsrv'
                        ? "STRING_AGG(mi.unit, ',') WITHIN GROUP (ORDER BY mi.unit) as units"
                        : "GROUP_CONCAT(DISTINCT mi.unit) as units"
                )
            )
            ->groupBy('mi.product_inventory_id')
            ->get()
            ->mapWithKeys(function ($item) {
                return [
                    $item->product_inventory_id => $item->total_quantity_used,
                    $item->product_inventory_id . '_units' => $item->units
                ];
            })
            ->toArray();



        $query = ProductInventory::query()
            ->with(['unit_of_measurement'])
            ->whereHas('inventory_stocks', function ($query) use ($branchId) {
                $query->where('store_branch_id', $branchId);
            })
            ->with(['inventory_stocks' => function ($query) use ($branchId) {
                $query->where('store_branch_id', $branchId);
            }]);

        if ($this->search) {
            $query->whereAny(['name', 'inventory_code'], 'like', "%$this->search%");
        }

        return $query->get()->map(function ($item) use ($usageRecords) {
            $units = isset($usageRecords[$item->id . '_units'])
                ? '(' . str_replace(',', ', ', $usageRecords[$item->id . '_units']) . ')'
                : '';

            return [
                'id' => $item->id,
                'name' => $item->name,
                'inventory_code' => $item->inventory_code,
                'stock_on_hand' => $item->inventory_stocks->first()->quantity - $item->inventory_stocks->first()->used,
                'recorded_used' => $item->inventory_stocks->first()->used,
                'estimated_used' => $usageRecords[$item->id] ?? 0,
                'ingredient_units' => $units,
                'uom' => $item->unit_of_measurement->name,
            ];
        });
    }


    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Inventory Code',
            'Stock on Hand',
            'Recorded Used',
            'Estimated Used',
            'Ingredient Units',
            'UOM'
        ];
    }

    public function map($row): array
    {
        return [
            $row['id'],
            $row['name'],
            $row['inventory_code'],
            $row['stock_on_hand'] > 0 ? $row['stock_on_hand'] : "0",
            $row['recorded_used'] > 0 ? $row['recorded_used'] : "0",
            $row['estimated_used'] > 0 ? $row['estimated_used'] : "0",
            $row['ingredient_units'],
            $row['uom']
        ];
    }
}
