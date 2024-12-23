<?php

namespace App\Http\Controllers;

use App\Models\StoreOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IceCreamOrderController extends Controller
{
    public function index()
    {
        $dateOptions = null;
        $monday = Carbon::now()->startOfWeek()->toDateString();
        $saturday = Carbon::now()->startOfWeek()->addDays(5)->toDateString();
        $orders = StoreOrder::with(['store_branch', 'store_order_items', 'store_order_items.product_inventory'])
            ->whereBetween('order_date', [$monday, $saturday])
            ->where('type', 'dts')
            ->get();

        $orders = $orders->groupBy(function ($order) {
            return Carbon::parse($order->order_date)->format('l');
        })->map(function ($dayOrders) {
            return $dayOrders->map(function ($order) {
                return $order->store_order_items->map(function ($item) use ($order) {
                    return [
                        'display_name' => "{$order->store_branch->brand_code}-NONOS {$order->store_branch->location_code}",
                        'quantity_ordered' => $item->quantity_ordered,
                        'ordered_item' => $item->product_inventory->name,
                        'item_code' => $item->product_inventory->inventory_code
                    ];
                });
            })
                ->flatten(1)
                ->groupBy('ordered_item')
                ->map(function ($items, $itemName) {
                    return [
                        'ordered_item' => "$itemName",
                        'total_quantity' => $items->sum('quantity_ordered'),
                        'branches' => $items->map(function ($item) {
                            return [
                                'store' => $item['display_name'],
                                'quantity' => $item['quantity_ordered']
                            ];
                        })->values()->all()
                    ];
                })
                ->values()
                ->all();
        });

        return Inertia::render('IceCreamOrder/Index', [
            'orders' => $orders,
            'dateOptionsFilter' => $dateOptions
        ]);
    }
}
