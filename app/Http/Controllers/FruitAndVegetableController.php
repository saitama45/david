<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FruitAndVegetableController extends Controller
{
    public function index()
    {
        $search = request('search');
        $start_date_filter = request('start_date_filter');
        $startDate = $start_date_filter ? Carbon::parse($start_date_filter) : Carbon::now()->startOfWeek();

        $dates = [
            'monday' => $startDate->toDateString(),
            'tuesday' => $startDate->copy()->addDays(1)->toDateString(),
            'wednesday' => $startDate->copy()->addDays(2)->toDateString(),
            'thursday' => $startDate->copy()->addDays(3)->toDateString(),
            'friday' => $startDate->copy()->addDays(4)->toDateString(),
            'saturday' => $startDate->copy()->addDays(5)->toDateString(),
        ];

        $storeOrders = StoreOrder::with(['store_order_items.product_inventory'])
            ->where('type', 'dts')
            ->whereBetween('order_date', [reset($dates), end($dates)])
            ->whereHas('store_order_items.product_inventory', function ($query) {
                $query->where('inventory_category_id', 6);
            })
            ->get();

            


        $formattedProducts = ProductInventory::where('inventory_category_id', 6)
            ->paginate(10)
            ->through(function ($product) use ($storeOrders, $dates) {

                $quantityByDay = collect($dates)->mapWithKeys(function ($date, $dayName) use ($storeOrders, $product) {


                    $quantity = $storeOrders
                        ->where('order_date', $date)
                        ->flatMap(function ($order) {
                            return $order->store_order_items;
                        })
                        ->where('product_inventory_id', $product->id)
                        ->sum('quantity_ordered');

                    return [$dayName => (int)$quantity];
                });

                return [
                    'name' => $product->name,
                    'inventory_code' => $product->inventory_code,
                    'quantity_ordered' => $quantityByDay
                ];
            });

        return Inertia::render('FruitAndVegetableOrder/Index', [
            'filters' => request()->only(['start_date_filter', 'search']),
            'items' => $formattedProducts
        ]);
    }


    public function generateDateOptions()
    {
        $firstOrder = StoreOrder::with(['store_order_items.product_inventory'])
            ->where('type', 'dts')
            ->whereHas('store_order_items.product_inventory', function ($query) {
                $query->whereIn('inventory_category_id', [6]);
            })
            ->orderBy('order_date', 'asc')
            ->first();


        if (!$firstOrder) {
            return [];
        }

        $startDate = Carbon::parse($firstOrder->order_date)->startOfWeek();
        $currentDate = Carbon::now()->startOfWeek();
        $dateOptions = [];
        $weekCounter = 1;

        while ($startDate <= $currentDate) {
            $endDate = $startDate->copy()->addDays(5);

            $dateOptions[] = [
                'name' => $startDate->format('F d, Y') . ' - ' . $endDate->format('F d, Y'),
                'code' => $startDate->format('Y-m-d')
            ];

            $startDate->addWeek();
            $weekCounter++;
        }

        return array_reverse($dateOptions);
    }

    public function getBranchesId($scheduleId)
    {
        return StoreBranch::with([
            'delivery_schedules'
        ])
            ->whereHas('delivery_schedules', function ($query) use ($scheduleId) {
                $query->where('variant', 'FRUITS AND VEGETABLES')
                    ->where('delivery_schedule_id', $scheduleId);
            })
            ->pluck('id');
    }

    public function getOrders($branchesId, $day)
    {

        return StoreOrder::with([
            'store_branch',
            'store_order_items',
            'store_order_items.product_inventory'
        ])
            ->whereIn('store_branch_id', $branchesId)
            ->where('order_date', $day)
            ->whereHas('store_order_items.product_inventory', function ($query) {
                $query->whereIn('inventory_category_id', [6]);
            })
            ->get()
            ->flatMap(function ($order) {
                return $order->store_order_items->map(function ($item) use ($order) {
                    return [
                        'item' => $item->product_inventory->name,
                        'item_code' => $item->product_inventory->inventory_code,
                        'branch' => [
                            'display_name' => "{$order->store_branch->brand_code}-NONOS {$order->store_branch->location_code}",
                            'quantity_ordered' => $item->quantity_ordered
                        ]
                    ];
                });
            })
            ->groupBy('item')
            ->map(function ($itemGroup) {
                $firstItem = $itemGroup->first();
                $total_quantity = $itemGroup->sum(function ($item) {
                    return $item['branch']['quantity_ordered'];
                });
                return [
                    'item' => $firstItem['item'],
                    'item_code' => $firstItem['item_code'],
                    'total_quantity' => $total_quantity,
                    'branches' => $itemGroup->pluck('branch')
                ];
            })
            ->values();
    }
}
