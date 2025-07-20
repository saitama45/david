<?php

namespace App\Http\Controllers;

use App\Enum\TimePeriod;
use App\Enum\UserRole;
use App\Mail\OneTimePasswordMail;
use App\Models\Branch;
use App\Models\ProductInventory; // Keep if still used elsewhere, but not for store_order_items joins
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreTransaction;
use App\Models\StoreTransactionItem;
use App\Models\SupplierItems; // Import SupplierItems model
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Calculation\Database\DStDevP;

class DashboardController extends Controller
{
    public function index()
    {
        $timePeriods = TimePeriod::values();
        $time_period = request('time_period') ?? 0;

        $inventory_type = request('inventory_type') ?? 'quantity';


        $branches = StoreBranch::options();
        $branch = request('branch') ?? $branches->keys()->first();

        $chart_time_period = request('chart_time_period') ?? 0;


        $inventories = $this->getInventories($branch, $time_period);

        $upcomingInventories = $this->getUpcomingInventories($branch, $time_period);
        $accountPayable = $this->getAccountPayable($branch, $time_period);
        $sales = $this->getSales($branch, $time_period);
        $cogs = $this->getCogs($branch, $time_period);
        $begginingInventory = $this->getBeginningInventory($branch);
        $endingInventory = $this->getEndingInventory($branch);
        $cogsAll = ProductInventoryStockManager::where('store_branch_id', $branch)
            ->where('total_cost', '<', 0)->sum(DB::raw('ABS(total_cost)'));
        $averageInventory = ($begginingInventory + $endingInventory) / 2;
        $dio = $this->getDaysInventoryOutstanding($cogsAll, $averageInventory, $chart_time_period);
        $productInventoryStock = $this->getTop10Products($branch, $inventory_type);
        $dpo = $this->getDaysPayableOutstanding($branch, $cogsAll, $chart_time_period);

        return Inertia::render('Dashboard/Index', [
            'timePeriods' => $timePeriods,
            'branches' => $branches,
            'sales' => $sales,
            'inventories' => $inventories,
            'upcomingInventories' => $upcomingInventories,
            'accountPayable' => $accountPayable,
            'filters' => request()->only(['branch', 'time_period', 'chart_time_period', 'inventory_type']),
            'cogs' => $cogs,
            'dio' => $dio,
            'dpo' => $dpo,
            'top_10' => $productInventoryStock
        ]);
    }

    public function getDaysPayableOutstanding($branch, $cogsAll, $chart_time_period)
    {
        $accountPayableAll = StoreOrderItem::query()
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            // CRITICAL FIX: Join with supplier_items on item_code and ItemCode
            ->join('supplier_items', 'store_order_items.item_code', '=', 'supplier_items.ItemCode')
            ->where('store_orders.store_branch_id', $branch)
            ->where('store_order_items.quantity_received', '>', 0)
            // CRITICAL FIX: Use supplier_items.cost
            ->sum(DB::raw('store_order_items.quantity_received * supplier_items.cost'));

        return $cogsAll > 0 && $accountPayableAll > 0 ? ($accountPayableAll / $cogsAll) * ($chart_time_period == 0 ? 365 : 30) : 0;
    }

    public function getTop10Products($branch, $inventory_type)
    {
        // This method still uses ProductInventoryStock and ProductInventory.
        // If ProductInventoryStockManager is now the source of truth for stock,
        // and its 'product_inventory_id' column was renamed to 'item_code' and stores ItemCode,
        // then this method needs a more significant refactor to join with SupplierItems.
        // For now, I'm assuming ProductInventoryStock still uses product_inventory_id (integer ID)
        // and ProductInventory still has a 'cost' column.
        // If this is NOT the case, please provide the schema/model for ProductInventoryStock
        // and clarify how its 'product_inventory_id' relates to SupplierItems.

        // If ProductInventoryStock.product_inventory_id has also been changed to store ItemCode
        // and should join to SupplierItems.ItemCode, then this block needs to be updated.
        // Assuming 'product_inventories' table and 'product_inventory_id' on 'product_inventory_stocks'
        // are still in use for *actual inventory stocks* (not related to store orders).
        // If this is meant to reflect ordered items, then it needs a full rewrite.

        $query = ProductInventoryStock::with('product')
            ->where('store_branch_id', $branch)
            ->select('*', DB::raw('(quantity - used) as stock_on_hand'));

        if ($inventory_type === 'cost') {
            // This join assumes product_inventory_stocks.product_inventory_id still links to product_inventories.id
            // and product_inventories still has a 'cost' column.
            $query->join('product_inventories', 'product_inventory_stocks.product_inventory_id', '=', 'product_inventories.id')
                ->orderByRaw("(quantity - used) * product_inventories.cost DESC");
        } else {
            $query->orderBy('stock_on_hand', 'desc');
        }

        return $query->take(10)
            ->get()
            ->map(function ($item) {
                // This assumes product->cost is still valid.
                return [
                    'name' => $item->product->select_option_name,
                    'total_cost' => $item->stock_on_hand * $item->product->cost,
                    'quantity' => $item->stock_on_hand
                ];
            });
    }

    public function getDaysInventoryOutstanding($cogsAll, $averageInventory, $chart_time_period)
    {
        return $cogsAll > 0 ? ($averageInventory / $cogsAll) * ($chart_time_period == 0 ? 365 : 30) : 0;
    }

    public function getEndingInventory($branch)
    {
        return ProductInventoryStockManager::query()
            ->where('store_branch_id', $branch)
            ->sum('total_cost');
    }

    public function getBeginningInventory($branch)
    {
        // This method still references 'product_inventory_id' from ProductInventoryStockManager.
        // If ProductInventoryStockManager's 'product_inventory_id' column was also renamed to 'item_code'
        // and stores ItemCode, then this part needs an update to reflect that.
        // Assuming ProductInventoryStockManager still uses product_inventory_id (integer ID)
        // and relates to ProductInventory for details.
        return ProductInventoryStockManager::select('product_inventory_id')
            ->where('store_branch_id', $branch)
            ->selectRaw('MIN(id) as first_transaction_id')
            ->where('quantity', '>', 0)
            ->groupBy('product_inventory_id')
            ->get()
            ->map(function ($item) {
                $transaction = ProductInventoryStockManager::find($item->first_transaction_id);
                return [
                    'product_id' => $item->product_inventory_id, // This will be the old integer ID
                    'first_quantity' => $transaction->quantity,
                    'transaction_date' => $transaction->transaction_date,
                    'unit_cost' => $transaction->unit_cost,
                    'total_cost' => $transaction->total_cost
                ];
            })
            ->sum('total_cost');
    }

    public function getCogs($branch, $time_period)
    {
        $cogsQuery = ProductInventoryStockManager::where('store_branch_id', $branch)
            ->where('total_cost', '<', 0);

        if ($time_period != 0) {
            $cogsQuery->whereMonth('transaction_date', $time_period);
        } else {
            $cogsQuery->whereYear('transaction_date', Carbon::today()->year);
        }

        return number_format(
            $cogsQuery->sum(DB::raw('ABS(total_cost)')),
            2,
            '.',
            ','
        );
    }

    public function getSales($branch, $time_period)
    {
        return number_format(
            StoreTransactionItem::whereHas('store_transaction', function ($query) use ($branch, $time_period) {
                $time_period != 0 ? $query->whereMonth('order_date', $time_period) : $query->whereYear('order_date', Carbon::today()->year);
                $query->where('store_branch_id', $branch);
            })->sum('net_total'),
            2,
            '.',
            ','
        );
    }

    public function getAccountPayable($branch, $time_period)
    {
        $accountPayable = StoreOrderItem::query()
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            // CRITICAL FIX: Join with supplier_items on item_code and ItemCode
            ->join('supplier_items', 'store_order_items.item_code', '=', 'supplier_items.ItemCode')
            ->where('store_orders.store_branch_id', $branch)
            ->where('store_order_items.quantity_received', '>', 0);

        if ($time_period != 0) {
            $accountPayable->whereMonth('store_orders.order_date', $time_period);
        } else {
            $accountPayable->whereYear('store_orders.order_date', Carbon::today()->year);
        }

        return number_format(
            // CRITICAL FIX: Use supplier_items.cost
            $accountPayable->sum(DB::raw('store_order_items.quantity_received * supplier_items.cost')),
            2,
            '.',
            ','
        );
    }

    public function getUpcomingInventories($branch, $time_period)
    {
        $upcomingInventories = StoreOrderItem::query()
            // CRITICAL FIX: Join with supplier_items on item_code and ItemCode
            ->join('supplier_items', 'store_order_items.item_code', '=', 'supplier_items.ItemCode')
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            ->where('store_orders.store_branch_id', $branch)
            ->where('store_orders.order_status', 'committed');

        if ($time_period != 0) {
            $upcomingInventories->whereMonth('store_orders.order_date', $time_period);
        } else {
            $upcomingInventories->whereYear('store_orders.order_date', Carbon::today()->year);
        }

        return number_format(
            // CRITICAL FIX: Use supplier_items.cost
            $upcomingInventories->sum(DB::raw('store_order_items.quantity_commited * supplier_items.cost')),
            2,
            '.',
            ','
        );
    }

    public function getInventories($branch, $time_period)
    {
        $inventoriesQuery = ProductInventoryStockManager::query()
            ->where('store_branch_id', $branch);


        if ($time_period != 0) {
            $inventoriesQuery->whereMonth('transaction_date', '<=', $time_period);
        } else {
            $inventoriesQuery->whereYear('transaction_date', Carbon::today()->year);
        }


        return number_format(
            $inventoriesQuery->sum('total_cost'),
            2,
            '.',
            ','
        );
    }

    public function getHighStockProducts($branchId)
    {
        // This method still uses ProductInventory and product_inventory_stocks.
        // If these tables are also updated to use ItemCode from SupplierItems,
        // this method needs to be refactored to join with SupplierItems.
        // Assuming 'product_inventories' table and 'product_inventory_stocks'
        // are still in use for *actual inventory stocks* (not related to store orders).
        $query = ProductInventory::query()
            ->with(['inventory_stocks' => function ($query) use ($branchId) {
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
        // This method still uses ProductInventory and product_inventory_stocks.
        // If these tables are also updated to use ItemCode from SupplierItems,
        // this method needs to be refactored to join with SupplierItems.
        // Assuming 'product_inventories' table and 'product_inventory_stocks'
        // are still in use for *actual inventory stocks* (not related to store orders).
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
        // This method still uses 'product_inventory_id' in usage_records, menu_ingredients, and ProductInventory.
        // If these tables/models are also updated to use ItemCode from SupplierItems,
        // this method needs to be refactored.
        // Assuming 'product_inventory_id' here still refers to an integer ID from ProductInventory.
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
