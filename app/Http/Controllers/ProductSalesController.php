<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductSalesController extends Controller
{
    public function index()
    {
        $items = ProductInventory::with('store_order_items')
            ->withSum('store_order_items as total_sold', 'quantity_received')
            ->paginate(10);


        return Inertia::render('ProductSales/Index', [
            'items' => $items
        ]);
    }
}
