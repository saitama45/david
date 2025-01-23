<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\StoreOrder;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Facades\Excel;

class IceCreamOrderController extends Controller
{
    public function index()
    {
        $start_date_filter = request('start_date_filter');
        $branch_id_filter = request('branchId');
        $datesOption = $this->generateDateOptions();
        $branches = StoreBranch::options();
        $startDate = $start_date_filter
            ? Carbon::parse($start_date_filter)
            : Carbon::now()->startOfWeek();




        $monday = $startDate->toDateString();
        $tuesday = $startDate->copy()->addDays(1)->toDateString();
        $wednesday = $startDate->copy()->addDays(2)->toDateString();
        $thursday = $startDate->copy()->addDays(3)->toDateString();
        $friday = $startDate->copy()->addDays(4)->toDateString();
        $saturday = $startDate->copy()->addDays(5)->toDateString();

        $mondayBranches = $this->getBranchesId(1, $branch_id_filter);
        $tuesdayBranches = $this->getBranchesId(2, $branch_id_filter);
        $wednesdayBranches = $this->getBranchesId(3, $branch_id_filter);
        $thursdayBranches = $this->getBranchesId(4, $branch_id_filter);
        $fridayBranches = $this->getBranchesId(5, $branch_id_filter);
        $saturdayBranches = $this->getBranchesId(6, $branch_id_filter);

        $mondayOrders = $this->getOrders($mondayBranches, $monday);
        $tuesdayOrders =  $this->getOrders($tuesdayBranches, $tuesday);
        $wednesdayOrders =  $this->getOrders($wednesdayBranches, $wednesday);
        $wednesdayOrders =  $this->getOrders($wednesdayBranches, $wednesday);
        $thursdayOrders =  $this->getOrders($thursdayBranches, $thursday);
        $fridayOrders =  $this->getOrders($fridayBranches, $friday);
        $saturdayOrders =  $this->getOrders($saturdayBranches, $saturday);

        return Inertia::render('IceCreamOrder/Index', [
            'mondayOrders' => $mondayOrders,
            'tuesdayOrders' => $tuesdayOrders,
            'wednesdayOrders' => $wednesdayOrders,
            'thursdayOrders' => $thursdayOrders,
            'fridayOrders' => $fridayOrders,
            'saturdayOrders' => $saturdayOrders,
            'datesOption' => $datesOption,
            'filters' => request()->only(['start_date_filter', 'branchId']),
            'branches' => $branches
        ]);
    }

    public function getBranchesId($scheduleId, $branchId = null)
    {
        $query = StoreBranch::with(['delivery_schedules'])
            ->whereHas('delivery_schedules', function ($query) use ($scheduleId) {
                $query->where('variant', 'ICE CREAM')
                    ->where('delivery_schedule_id', $scheduleId);
            });

        if ($branchId) {
            $query->whereIn('id', $branchId);
        }

        return $query->pluck('id');
    }

    public function generateDateOptions()
    {
        $firstOrder = StoreOrder::with(['store_order_items.product_inventory'])
            ->where('variant', 'ice cream')
            ->whereHas('store_order_items.product_inventory', function ($query) {
                $query->where('inventory_code', '359A2A');
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


    public function excel()
    {
        $start_date_filter = request('start_date_filter');
        $branch_id_filter = request('branchId');

        $startDate = $start_date_filter
            ? Carbon::parse($start_date_filter)
            : Carbon::now()->startOfWeek();

        $monday = $startDate->toDateString();
        $tuesday = $startDate->copy()->addDays(1)->toDateString();
        $wednesday = $startDate->copy()->addDays(2)->toDateString();
        $thursday = $startDate->copy()->addDays(3)->toDateString();
        $friday = $startDate->copy()->addDays(4)->toDateString();
        $saturday = $startDate->copy()->addDays(5)->toDateString();

        $mondayOrders = $this->getOrders($this->getBranchesId(1, $branch_id_filter), $monday);
        $tuesdayOrders = $this->getOrders($this->getBranchesId(2, $branch_id_filter), $tuesday);
        $wednesdayOrders = $this->getOrders($this->getBranchesId(3, $branch_id_filter), $wednesday);
        $thursdayOrders = $this->getOrders($this->getBranchesId(4, $branch_id_filter), $thursday);
        $fridayOrders = $this->getOrders($this->getBranchesId(5, $branch_id_filter), $friday);
        $saturdayOrders = $this->getOrders($this->getBranchesId(6, $branch_id_filter), $saturday);

        $branchNames = empty($branch_ids_filter) ? 'All Branches' :
            StoreBranch::whereIn('id', $branch_ids_filter)
            ->get()
            ->map(function ($branch) {
                return "{$branch->brand_code}-NONOS {$branch->location_code}";
            })
            ->join(', ');

        $branches = collect([
            $mondayOrders,
            $tuesdayOrders,
            $wednesdayOrders,
            $thursdayOrders,
            $fridayOrders,
            $saturdayOrders
        ])
            ->filter()
            ->flatMap(function ($dayOrders) {
                return $dayOrders->flatMap(function ($order) {
                    return $order['branches']->filter(function ($branch) {
                        return $branch['quantity_ordered'] > 0;
                    })->pluck('display_name');
                });
            })
            ->unique()
            ->values();


        $mondayTotal = $mondayOrders->sum(function ($order) {
            return $order['branches']->sum('quantity_ordered');
        });
        $tuesdayTotal = $tuesdayOrders->sum(function ($order) {
            return $order['branches']->sum('quantity_ordered');
        });
        $wednesdayTotal = $wednesdayOrders->sum(function ($order) {
            return $order['branches']->sum('quantity_ordered');
        });
        $thursdayTotal = $thursdayOrders->sum(function ($order) {
            return $order['branches']->sum('quantity_ordered');
        });
        $fridayTotal = $fridayOrders->sum(function ($order) {
            return $order['branches']->sum('quantity_ordered');
        });
        $saturdayTotal = $saturdayOrders->sum(function ($order) {
            return $order['branches']->sum('quantity_ordered');
        });


        return Excel::download(new class(
            $mondayOrders,
            $tuesdayOrders,
            $wednesdayOrders,
            $thursdayOrders,
            $fridayOrders,
            $saturdayOrders,
            $branches,
            $startDate,
            $branchNames,

            $mondayTotal,
            $tuesdayTotal,
            $wednesdayTotal,
            $thursdayTotal,
            $fridayTotal,
            $saturdayTotal
        ) implements FromView {
            private $mondayOrders;
            private $tuesdayOrders;
            private $wednesdayOrders;
            private $thursdayOrders;
            private $fridayOrders;
            private $saturdayOrders;
            private $branches;
            private $startDate;
            private $branchNames;

            private $mondayTotal;
            private $tuesdayTotal;
            private $wednesdayTotal;
            private $thursdayTotal;
            private $fridayTotal;
            private $saturdayTotal;

            public function __construct(
                $mon,
                $tue,
                $wed,
                $thu,
                $fri,
                $sat,
                $branches,
                $startDate,
                $branchNames,
                $mondayTotal,
                $tuesdayTotal,
                $wednesdayTotal,
                $thursdayTotal,
                $fridayTotal,
                $saturdayTotal
            ) {
                $this->mondayOrders = $mon;
                $this->tuesdayOrders = $tue;
                $this->wednesdayOrders = $wed;
                $this->thursdayOrders = $thu;
                $this->fridayOrders = $fri;
                $this->saturdayOrders = $sat;
                $this->branches = $branches;
                $this->startDate = $startDate;
                $this->branchNames = $branchNames;
                $this->mondayTotal = $mondayTotal;
                $this->tuesdayTotal = $tuesdayTotal;
                $this->wednesdayTotal = $wednesdayTotal;
                $this->thursdayTotal = $thursdayTotal;
                $this->fridayTotal = $fridayTotal;
                $this->saturdayTotal = $saturdayTotal;
            }

            public function view(): View
            {
                return view('ice-cream-orders-summary', [
                    'mondayOrders' => $this->mondayOrders,
                    'tuesdayOrders' => $this->tuesdayOrders,
                    'wednesdayOrders' => $this->wednesdayOrders,
                    'thursdayOrders' => $this->thursdayOrders,
                    'fridayOrders' => $this->fridayOrders,
                    'saturdayOrders' => $this->saturdayOrders,
                    'branches' => $this->branches,
                    'startDate' => $this->startDate,
                    'endDate' => $this->startDate->copy()->addDays(5),
                    'branchFilter' => $this->branchNames,
                    'mondayTotal' => $this->mondayTotal,
                    'tuesdayTotal' => $this->tuesdayTotal,
                    'wednesdayTotal' => $this->wednesdayTotal,
                    'thursdayTotal' => $this->thursdayTotal,
                    'fridayTotal' => $this->fridayTotal,
                    'saturdayTotal' => $this->saturdayTotal
                ]);
            }
        }, 'ice-cream-orders-summary-' . now()->format('Y-m-d') . '.xlsx');
    }

    public function getOrders($branchesId, $day)
    {
        $orders = StoreOrder::with([
            'store_branch',
            'store_order_items',
            'store_order_items.product_inventory'
        ])
            ->whereIn('store_branch_id', $branchesId)
            ->where('order_date', $day)
            ->whereHas('store_order_items.product_inventory', function ($query) {
                $query->whereIn('inventory_code', ['359A2A']);
            })
            ->whereHas('store_order_items', function ($query) {
                $query->where('quantity_ordered', '>', 0);
            })
            ->get()
            ->flatMap(function ($order) {
                return $order->store_order_items->map(function ($item) use ($order) {
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
                });
            })
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
                    ->filter(function ($branch) {
                        return $branch['quantity_ordered'] > 0;
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
            ->filter(function ($item) {
                return $item['total_quantity'] > 0 && $item['branches']->isNotEmpty();
            })
            ->values();

        return $orders;
    }
}
