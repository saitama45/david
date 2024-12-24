<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\StoreOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IceCreamOrderController extends Controller
{
    public function index()
    {
        $startDate = Carbon::now()->startOfWeek();
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



        return Inertia::render('IceCreamOrder/Index', [
            'mondayOrders' => $mondayOrders,
            'tuesdayOrders' => $tuesdayOrders,
            'wednesdayOrders' => $wednesdayOrders,
            'thursdayOrders' => $thursdayOrders,
            'fridayOrders' => $fridayOrders,
            'saturdayOrders' => $saturdayOrders
        ]);
    }

    public function getBranchesId($scheduleId)
    {
        return StoreBranch::with([
            'delivery_schedules'
        ])
            ->whereHas('delivery_schedules', function ($query) use ($scheduleId) {
                $query->where('variant', 'ICE CREAM')
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
                $query->whereIn('inventory_code', ['359A2A']);
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
                return [
                    'item' => $firstItem['item'],
                    'item_code' => $firstItem['item_code'],
                    'branches' => $itemGroup->pluck('branch')
                ];
            })
            ->values();
    }
}
