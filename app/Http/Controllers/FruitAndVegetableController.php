<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\StoreOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FruitAndVegetableController extends Controller
{
    public function index()
    {
        $start_date_filter = request('start_date_filter');


        $datesOption = $this->generateDateOptions();

        $startDate =  $start_date_filter ? Carbon::parse($start_date_filter) : Carbon::now()->startOfWeek();

        $monday = $startDate->toDateString();

        $tuesday = $startDate->copy()->addDays(1)->toDateString();
        $wednesday = $startDate->copy()->addDays(2)->toDateString();
        $thursday = $startDate->copy()->addDays(3)->toDateString();
        $friday = $startDate->copy()->addDays(4)->toDateString();
        $saturday = $startDate->copy()->addDays(5)->toDateString();


        $mondayOrders = $this->getOrders($this->getBranchesId(1), $monday);
        $tuesdayOrders =  $this->getOrders($this->getBranchesId(2), $tuesday);
        $wednesdayOrders =  $this->getOrders($this->getBranchesId(3), $wednesday);
        $thursdayOrders =  $this->getOrders($this->getBranchesId(4), $thursday);
        $fridayOrders =  $this->getOrders($this->getBranchesId(5), $friday);
        $saturdayOrders =  $this->getOrders($this->getBranchesId(6), $saturday);
        return Inertia::render('FruitAndVegetableOrder/Index', [
            'mondayOrders' => $mondayOrders,
            'tuesdayOrders' => $tuesdayOrders,
            'wednesdayOrders' => $wednesdayOrders,
            'thursdayOrders' => $thursdayOrders,
            'fridayOrders' => $fridayOrders,
            'saturdayOrders' => $saturdayOrders,
            'datesOption' => $datesOption,
            'filters' => request()->only(['start_date_filter'])
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
