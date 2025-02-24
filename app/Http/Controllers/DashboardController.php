<?php

namespace App\Http\Controllers;

use App\Enum\TimePeriod;
use App\Enum\UserRole;
use App\Mail\OneTimePasswordMail;
use App\Models\Branch;
use App\Models\ProductInventory;
use App\Models\ProductInventoryStock;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreTransaction;
use App\Models\StoreTransactionItem;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $timePeriods = TimePeriod::values();
        $time_period = request('time_period') ?? $timePeriods[1];

        $branches = StoreBranch::options();
        $branch = request('branch') ?? $branches->keys()->first();

        try {
            $user = User::rolesAndAssignedBranches();
            $inventoriesQuery = ProductInventoryStock::where('store_branch_id', $branch);
            $time_period != 0 ? $inventoriesQuery->whereMonth('updated_at', $time_period) : $inventoriesQuery->whereYear('updated_at', Carbon::today()->year);
            $inventories = $inventoriesQuery->sum(DB::raw('quantity - used'));

            $upcomingInventories = StoreOrderItem::whereHas('store_order', function ($query) use ($branch) {
                $query->where('store_branch_id', $branch);
                $query->where('order_status', 'approved');
            })->sum('quantity_approved');




            $sales = number_format(
                StoreTransactionItem::whereHas('store_transaction', function ($query) use ($branch, $time_period) {
                    $time_period != 0 ? $query->whereMonth('order_date', $time_period) : $query->whereYear('order_date', Carbon::today()->year);
                    $query->where('store_branch_id', $branch);
                })->sum('net_total'),
                2,
                '.',
                ','
            );

            if ($user['isAdmin']) {
                $orderCounts = StoreOrder::selectRaw(DB::connection()->getDriverName() === 'sqlsrv'
                    ? "
                SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN order_status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN order_status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
            "
                    : "
                COUNT(CASE WHEN order_status = 'pending' THEN 1 END) as pending_count,
                COUNT(CASE WHEN order_status = 'approved' THEN 1 END) as approved_count,
                COUNT(CASE WHEN order_status = 'rejected' THEN 1 END) as rejected_count
            ")
                    ->first();

                return Inertia::render('Dashboard/Index', [
                    'orderCounts' => $orderCounts,
                    'timePeriods' => $timePeriods,
                    'branches' => $branches,
                    'sales' => $sales,
                    'inventories' => $inventories,
                    'upcomingInventories' => $upcomingInventories,
                    'filters' => request()->only(['branch', 'time_period'])
                ]);
            }

            $branches = $user['user']->store_branches->pluck('name', 'id')->toArray();
            $branchId = request('branchId') ?? array_keys($branches)[0];

            $orderCounts = StoreOrder::where('store_branch_id', $branchId)
                ->selectRaw(DB::connection()->getDriverName() === 'sqlsrv'
                    ? "
                SUM(CASE WHEN order_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN order_status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN order_status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
                "
                    : "
                COUNT(CASE WHEN order_status = 'pending' THEN 1 END) as pending_count,
                COUNT(CASE WHEN order_status = 'approved' THEN 1 END) as approved_count,
                COUNT(CASE WHEN order_status = 'rejected' THEN 1 END) as rejected_count
        ")
                ->first();

            $highStockproducts = $this->getHighStockProducts($branchId);
            $mostUsedProducts = $this->getMostUsedProducts($branchId);
            $lowOnStockItems = $this->getLowOnStockItems($branchId);
        } catch (Exception $e) {
            throw $e;
        }


        return Inertia::render('StoreDashboard/Index', [
            'branches' => $branches,
            'orderCounts' => [
                'pending' => $orderCounts->pending_count ?? 0,
                'approved' => $orderCounts->approved_count ?? 0,
                'rejected' => $orderCounts->rejected_count ?? 0
            ],
            'filters' => request()->only(['branchId']),
            'highStockProducts' => $highStockproducts,
            'mostUsedProducts' => $mostUsedProducts,
            'lowOnStockItems' => $lowOnStockItems
        ]);
    }

    public function getHighStockProducts($branchId)
    {
        return ProductInventory::with(['inventory_stocks' => function ($query) use ($branchId) {
            $query->where('store_branch_id', $branchId);
        }])
            ->whereHas('inventory_stocks', function ($query) use ($branchId) {
                $query->where('store_branch_id', $branchId);
            })
            ->select('product_inventories.*')
            ->selectRaw('(SELECT SUM(quantity - used) FROM product_inventory_stocks 
                WHERE product_inventories.id = product_inventory_stocks.product_inventory_id 
                AND store_branch_id = ?) as stock_on_hand', [$branchId])
            ->orderByDesc('stock_on_hand')
            ->take(4)
            ->get()
            ->map(function ($product) {
                $stock = $product->inventory_stocks->first();
                return [
                    'name' => $product->name,
                    'stock' => $stock->quantity - $stock->used,
                ];
            });
    }

    public function getMostUsedProducts($branchId)
    {
        return ProductInventory::with(['inventory_stocks' => function ($query) use ($branchId) {
            $query->where('store_branch_id', $branchId);
        }])
            ->whereHas('inventory_stocks', function ($query) use ($branchId) {
                $query->where('store_branch_id', $branchId);
            })
            ->select('product_inventories.*')
            ->selectRaw('(SELECT SUM(used) FROM product_inventory_stocks 
                WHERE product_inventory_stocks.product_inventory_id = product_inventories.id 
                AND store_branch_id = ?) as total_used', [$branchId])
            ->orderBy('total_used', 'desc')
            ->take(4)
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->name,
                    'used' => $item->total_used ?? 0
                ];
            });
    }

    public function getLowOnStockItems($branchId)
    {
        $usageRecords = DB::table('usage_records as ur')
            ->join('usage_record_items as uri', 'ur.id', '=', 'uri.usage_record_id')
            ->join('menus as m', 'uri.menu_id', '=', 'm.id')
            ->join('menu_ingredients as mi', 'm.id', '=', 'mi.menu_id')
            ->where('ur.store_branch_id', $branchId)
            ->select(
                'mi.product_inventory_id',
                DB::raw(
                    DB::connection()->getDriverName() === 'sqlsrv'
                        ? 'CAST(SUM(CAST(mi.quantity AS DECIMAL(10,2)) * CAST(uri.quantity AS DECIMAL(10,2))) AS DECIMAL(10,2)) as total_quantity_used'
                        : 'SUM(mi.quantity * uri.quantity) as total_quantity_used'
                ),
                DB::raw(
                    DB::connection()->getDriverName() === 'sqlsrv'
                        ? "STRING_AGG(mi.unit, ',') WITHIN GROUP (ORDER BY mi.unit) as units"
                        : "GROUP_CONCAT(DISTINCT mi.unit) as units"
                )
            )
            ->groupBy('mi.product_inventory_id')
            ->get()
            ->mapWithKeys(function ($item) {
                return [
                    $item->product_inventory_id => $item->total_quantity_used,
                    $item->product_inventory_id . '_units' => $item->units
                ];
            })
            ->toArray();

        $query = ProductInventory::query()
            ->with(['unit_of_measurement'])
            ->whereHas('inventory_stocks', function ($query) use ($branchId) {
                $query->where('store_branch_id', $branchId);
            })
            ->with(['inventory_stocks' => function ($query) use ($branchId) {
                $query->where('store_branch_id', $branchId);
            }]);

        return $query
            ->paginate(10)
            ->through(function ($item) use ($usageRecords) {
                $units = isset($usageRecords[$item->id . '_units'])
                    ? '(' . str_replace(',', ', ', $usageRecords[$item->id . '_units']) . ')'
                    : '';

                if ($item->inventory_stocks->first()->quantity - $item->inventory_stocks->first()->used > 10) {
                    return null;
                }

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'inventory_code' => $item->inventory_code,
                    'stock_on_hand' => $item->inventory_stocks->first()->quantity - $item->inventory_stocks->first()->used,
                    'recorded_used' => $item->inventory_stocks->first()->used,
                    'estimated_used' => $usageRecords[$item->id] ?? 0,
                    'ingredient_units' => $units,
                    'uom' => $item->unit_of_measurement->name,
                ];
            });
    }

    public function test()
    {
        try {
            $to = "admin@gmail.com";
            $otp = random_int(000000, 999999);
            $response = Mail::to($to)->send(new OneTimePasswordMail($otp));
            dd($response);
        } catch (Exception $e) {
            dd($e->getMessage());
        }
    }
}
