<?php

namespace App\Http\Controllers;

use App\Enum\UserRole;
use App\Mail\OneTimePasswordMail;
use App\Models\Branch;
use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\User;
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

        $user = User::with(['roles', 'store_branches'])->findOrFail(Auth::user()->id);

        if (in_array('admin', $user->roles->pluck('name')->toArray())) {
            return Inertia::render('Dashboard/Index');
        }

        if (in_array('rec approver', $user->roles->pluck('name')->toArray())) {
            return Inertia::render('SupplierDashboard/Index');
        }
        if (in_array('so encoder', $user->roles->pluck('name')->toArray()) && !in_array('admin', $user->roles->pluck('name')->toArray())) {
            $assignedBranches = $user->store_branches->pluck('id');
            $branches = StoreBranch::whereIn('id', $assignedBranches)->options();
        }

        $assignedBranches = $user->store_branches->pluck('id')->toArray();
        $userRoles = $user->roles->pluck('name')->toArray();
        $branches = $user->store_branches->pluck('name', 'id')->toArray();
        $branchId = request('branchId') ?? array_keys($branches)[0];

        $orderCounts = StoreOrder::where('store_branch_id', $branchId)
            ->selectRaw('
            COUNT(CASE WHEN order_request_status = "pending" THEN 1 END) as pending_count,
            COUNT(CASE WHEN order_request_status = "approved" THEN 1 END) as approved_count,
            COUNT(CASE WHEN order_request_status = "rejected" THEN 1 END) as rejected_count
        ')
            ->first();

        $highStockproducts = $this->getHighStockProducts($branchId);

        $mostUsedProducts = $this->getMostUsedProducts($branchId);

        $lowOnStockItems = $this->getLowOnStockItems($branchId);


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
            })
        ;
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
                DB::raw('SUM(mi.quantity * uri.quantity) as total_quantity_used'),
                DB::raw('GROUP_CONCAT(DISTINCT mi.unit) as units')
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
                    return;
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
