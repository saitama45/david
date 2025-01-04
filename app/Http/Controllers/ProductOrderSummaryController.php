<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use App\Models\StoreOrderItem;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductOrderSummaryController extends Controller
{
    public function index()
    {
        $search = request('search');
        $dateRange = request('dateRange');
        $supplierId = request('supplierId');
        $suppliers = Supplier::options();
        $startDate = $dateRange ? Carbon::parse($dateRange[0])->addDay()->format('Y-m-d') : Carbon::yesterday()->format('Y-m-d');
        $endDate = $dateRange ? Carbon::parse($dateRange[1])->addDay()->format('Y-m-d') : $startDate;

        $query = ProductInventory::query()
            ->with(['store_order_items', 'store_order_items.store_order', 'unit_of_measurement']);

        if ($search) {
            $query->whereAny(['name', 'inventory_code'], 'like', "%$search%");
        }

        $query->withSum(['store_order_items' => function ($query) use ($startDate, $endDate, $supplierId) {
            $query->whereHas('store_order', function ($subQuery) use ($startDate, $endDate, $supplierId) {
                $subQuery->whereBetween('order_date', [$startDate, $endDate]);
                if ($supplierId) {
                    $subQuery->where('supplier_id', $supplierId);
                }
            });
        }], 'quantity_ordered')
            ->withSum(['store_order_items' => function ($query) use ($startDate, $endDate, $supplierId) {
                $query->whereHas('store_order', function ($subQuery) use ($startDate, $endDate, $supplierId) {
                    $subQuery->whereBetween('order_date', [$startDate, $endDate]);
                    if ($supplierId) {
                        $subQuery->where('supplier_id', $supplierId);
                    }
                });
            }], 'quantity_received')
            ->whereHas('store_order_items.store_order', function ($query) use ($startDate, $endDate, $supplierId) {
                $query->whereBetween('order_date', [$startDate, $endDate]);
                if ($supplierId) {
                    $query->where('supplier_id', $supplierId);
                }
            });

        $items = $query->paginate(10)->withQueryString();

        return Inertia::render('ProductOrderSummary/Index', [
            'items' => $items,
            'suppliers' => $suppliers,
            'filters' => request()->only(['search', 'dateRange', 'supplierId'])
        ]);
    }

    public function show($id)
    {
        $dateRange = request('dateRange');
        $supplierId = request('supplierId');
        $startDate = $dateRange ? Carbon::parse($dateRange[0])->addDay()->format('Y-m-d') : Carbon::yesterday()->format('Y-m-d');
        $endDate = $dateRange ? Carbon::parse($dateRange[1])->addDay()->format('Y-m-d') : $startDate;

        $item = ProductInventory::with(['unit_of_measurement'])
            ->with(['store_order_items' => function ($query) use ($startDate, $endDate, $supplierId) {
                $query->whereHas('store_order', function ($subQuery) use ($startDate, $endDate, $supplierId) {
                    $subQuery->whereBetween('order_date', [$startDate, $endDate]);
                    if ($supplierId) {
                        $subQuery->where('supplier_id', $supplierId);
                    }
                })->with(['store_order.store_branch', 'store_order.supplier']);
            }])
            ->findOrFail($id);

        $orders = $item->store_order_items;

        return Inertia::render('ProductOrderSummary/Show', [
            'item' => $item,
            'orders' => $orders,
            'filters' => request()->only(['dateRange'])
        ]);
    }
}
