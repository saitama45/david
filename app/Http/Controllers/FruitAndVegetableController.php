<?php

namespace App\Http\Controllers;

use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FruitAndVegetableController extends Controller
{

    public function index()
    {
        $search = request('search');
        $start_date_filter = request('start_date_filter');
        $startDate = $start_date_filter ? Carbon::parse($start_date_filter) : Carbon::now()->startOfWeek();

        if ($start_date_filter) {
            session(['fruit_veg_start_date' => $start_date_filter]);
        } else {
            session()->forget('fruit_veg_start_date');
        }

        $inventoryIds = ProductInventory::where('inventory_category_id', 6)
            ->pluck('inventory_code')
            ->toArray();

        $datesOption = $this->generateDateOptions($inventoryIds);


        $dates = [
            'monday' => $startDate->toDateString(),
            'tuesday' => $startDate->copy()->addDays(1)->toDateString(),
            'wednesday' => $startDate->copy()->addDays(2)->toDateString(),
            'thursday' => $startDate->copy()->addDays(3)->toDateString(),
            'friday' => $startDate->copy()->addDays(4)->toDateString(),
            'saturday' => $startDate->copy()->addDays(5)->toDateString(),
        ];

        $storeOrders = StoreOrder::with(['store_order_items.product_inventory'])
            ->where('variant', 'fruits and vegetables')
            ->whereBetween('order_date', [reset($dates), end($dates)])
            ->whereHas('store_order_items.product_inventory', function ($query) {
                $query->where('inventory_category_id', 6);
            })
            ->get();


        $query = ProductInventory::query();
        if ($search) {
            $query->whereAny(['name', 'inventory_code'], 'like', "%{$search}%");
        }


        $formattedProducts = $query->where('inventory_category_id', 6)
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
            'items' => $formattedProducts,
            'datesOption' => $datesOption
        ]);
    }


    public function show($id)
    {
        $start_date_filter = request('start_date_filter');

        $datesOption = $this->generateDateOptions([$id]);

        $startDate = $start_date_filter
            ? Carbon::parse($start_date_filter)
            : (session('fruit_veg_start_date')
                ? Carbon::parse(session('fruit_veg_start_date'))
                : Carbon::now()->startOfWeek());

        $monday = $startDate->toDateString();
        $tuesday = $startDate->copy()->addDays(1)->toDateString();
        $wednesday = $startDate->copy()->addDays(2)->toDateString();
        $thursday = $startDate->copy()->addDays(3)->toDateString();
        $friday = $startDate->copy()->addDays(4)->toDateString();
        $saturday = $startDate->copy()->addDays(5)->toDateString();

        $mondayOrders = $this->getOrders($this->getBranchesId(1), $monday, $id);

        $tuesdayOrders =  $this->getOrders($this->getBranchesId(2), $tuesday, $id);
        $wednesdayOrders =  $this->getOrders($this->getBranchesId(3), $wednesday, $id);
        $thursdayOrders =  $this->getOrders($this->getBranchesId(4), $thursday, $id);
        $fridayOrders =  $this->getOrders($this->getBranchesId(5), $friday, $id);
        $saturdayOrders =  $this->getOrders($this->getBranchesId(6), $saturday, $id);

        return Inertia::render('FruitAndVegetableOrder/Show', [
            'mondayOrders' => $mondayOrders,
            'tuesdayOrders' => $tuesdayOrders,
            'wednesdayOrders' => $wednesdayOrders,
            'thursdayOrders' => $thursdayOrders,
            'fridayOrders' => $fridayOrders,
            'saturdayOrders' => $saturdayOrders,
            'datesOption' => $datesOption,
            'filters' => request()->only(['start_date_filter']),
            'inventory_code' => $id,
            'currentFilter' => session('fruit_veg_start_date')
        ]);
    }

    public function generateDateOptions($id)
    {
        $firstOrder = StoreOrder::with(['store_order_items.product_inventory'])
            ->where('variant', 'fruits and vegetables')
            ->whereHas('store_order_items.product_inventory', function ($query) use ($id) {
                $query->whereIn('inventory_code', $id);
            })
            ->orderBy('order_date', 'asc')
            ->first();

        if (!$firstOrder) {
            return [];
        }

        $startDate = Carbon::parse($firstOrder->order_date)->startOfWeek();
        $currentDate = Carbon::now()->next('Monday');
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

    public function getOrders($branchesId, $day, $id)
    {
        return StoreOrder::with([
            'store_branch',
            'store_order_items',
            'store_order_items.product_inventory'
        ])
            // ->whereIn('store_branch_id', $branchesId)
            ->where('order_date', $day)
            ->whereHas('store_order_items.product_inventory', function ($query) use ($id) {
                $query->where('inventory_code', $id);
            })
            ->get()
            ->flatMap(function ($order) use ($id) {
                return $order->store_order_items->map(function ($item) use ($order, $id) {
                    if ($id === $item->product_inventory->inventory_code) {
                        return [
                            'item' => $item->product_inventory->name,
                            'item_code' => $item->product_inventory->inventory_code,
                            'branch_key' => $order->store_branch->id,
                            'branch' => [
                                'display_name' => "{$order->store_branch->brand_code}-NONOS {$order->store_branch->location_code}",
                                'quantity_ordered' => DB::connection()->getDriverName() === 'sqlsrv'
                                    ? (float)$item->quantity_ordered
                                    : $item->quantity_ordered
                            ]
                        ];
                    }
                    return null;
                });
            })
            ->filter()
            ->groupBy('item')
            ->map(function ($itemGroup) {
                $firstItem = $itemGroup->first();

                $branches = $itemGroup
                    ->groupBy('branch_key')
                    ->map(function ($branchGroup) {
                        $first = $branchGroup->first();
                        $quantity = $branchGroup->sum(function ($item) {
                            return $item['branch']['quantity_ordered'];
                        });

                        return [
                            'display_name' => $first['branch']['display_name'],
                            'quantity_ordered' => DB::connection()->getDriverName() === 'sqlsrv'
                                ? (float)$quantity
                                : $quantity
                        ];
                    })
                    ->values();

                $total_quantity = DB::connection()->getDriverName() === 'sqlsrv'
                    ? (float)$branches->sum('quantity_ordered')
                    : $branches->sum('quantity_ordered');

                return [
                    'item' => $firstItem['item'],
                    'item_code' => $firstItem['item_code'],
                    'total_quantity' => $total_quantity,
                    'branches' => $branches
                ];
            })
            ->values();
    }
}
