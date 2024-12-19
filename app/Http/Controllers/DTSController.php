<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DTSController extends Controller
{
    public function index()
    {
        return Inertia::render('DTSOrder/Index');
    }

    public function create($variant)
    {
        $suppliers = Supplier::where('supplier_code', 'DROPS')->options();
        $items = ProductInventory::whereHas(
            'product_categories',
            function ($query) use ($variant) {
                $query->where('name', strtoupper($variant));
            }
        )->options();
        if ($variant === 'ice cream') {
            $branches = StoreBranch::whereIn('id', [11, 31, 17, 22])->options();
        }
        if ($variant === 'salmon') {
            $branches = StoreBranch::whereIn('id', [21, 22, 23])->options();
        }

        return Inertia::render('DTSOrder/Create', [
            'suppliers' => $suppliers,
            'items' => $items,
            'branches' => $branches,
            'variant' => $variant
        ]);
    }

    public function getDTSSchedules() {}
}
