<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
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
                $query->where('name', $variant);
            }
        )->options();

        return Inertia::render('DTSOrder/Create', [
            'suppliers' => $suppliers,
            'items' => $items
        ]);
    }
}
