<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use App\Models\StoreOrderItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductOrderSummaryController extends Controller
{
    public function index()
    {
        $search = request('search');
        $dateRange = request('dateRange');
        $startDate = $dateRange ? Carbon::parse($dateRange[0])->addDay()->format('Y-m-d') : Carbon::yesterday()->format('Y-m-d');
        $endDate = $dateRange ? Carbon::parse($dateRange[1])->addDay()->format('Y-m-d') : $startDate;

        // dd(StoreOrderItem::with('store_order')
        //     ->where('product_inventory_id', 1)
        //     ->whereHas('store_order', function ($query) {
        //         $query->whereBetween('order_date', ['2024-12-1', '2024-12-31']);
        //     })->get());

        $query = ProductInventory::query()
            ->with(['store_order_items', 'store_order_items.store_order', 'unit_of_measurement']);

        if ($search) {
            $query->whereAny(['name', 'inventory_code'], 'like', "%$search%");
        }



        $query->withSum(['store_order_items' => function ($query) use ($startDate, $endDate) {
            $query->whereHas('store_order', function ($subQuery) use ($startDate, $endDate) {
                $subQuery->whereBetween('order_date', [$startDate, $endDate]);
            });
        }], 'quantity_ordered')
            ->withSum(['store_order_items' => function ($query) use ($startDate, $endDate) {
                $query->whereHas('store_order', function ($subQuery) use ($startDate, $endDate) {
                    $subQuery->whereBetween('order_date', [$startDate, $endDate]);
                });
            }], 'quantity_received')
            ->whereHas('store_order_items.store_order', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('order_date', [$startDate, $endDate]);
            });



        $items = $query->paginate(10)->withQueryString();

        return Inertia::render('ProductOrderSummary/Index', [
            'items' => $items,
            'filters' => request()->only(['search', 'dateRange'])
        ]);
    }

    public function show($id)
    {
        $item = ProductInventory::with('store_order_items.store_order', 'store_order_items.store_order.store_branch', 'store_order_items.store_order.supplier', 'unit_of_measurement')->find($id);
        $orders = $item->store_order_items;

        return Inertia::render('ProductOrderSummary/Show', [
            'item' => $item,
            'orders' => $orders
        ]);
    }
}
