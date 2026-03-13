<?php

namespace App\Http\Controllers;

use App\Enum\TimePeriod;
use App\Enum\UserRole;
use App\Mail\OneTimePasswordMail;
use App\Models\Branch;
// use App\Models\ProductInventory; // This model is now explicitly NOT used for stock/order items
use App\Models\ProductInventoryStock; // This model is now linked to SAPMasterfile
use App\Models\ProductInventoryStockManager; // This model is now linked to SAPMasterfile
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreTransaction;
use App\Models\StoreTransactionItem;
use App\Models\SupplierItems; // Import SupplierItems model
use App\Models\User;
use App\Models\SAPMasterfile; // Ensure SAPMasterfile is imported
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Calculation\Database\DStDevP;
use Illuminate\Support\Facades\Log; // Import Log facade for error logging

class DashboardController extends Controller
{
    public function index()
    {
        $timePeriods = TimePeriod::values();
        $time_period = request('time_period') ?? 0;

        $inventory_type = request('inventory_type') ?? 'quantity';


        $branches = StoreBranch::options();
        // Default to 'all' instead of relying on keys() which might be numeric
        $branch = request('branch') ?? 'all';

        // Resolve the actual branch IDs to filter by
        $branchIds = $branch === 'all'
            ? $branches->pluck('value')->filter(fn($v) => $v !== 'all')->toArray()
            : [$branch];

        $chart_time_period = request('chart_time_period') ?? 0;


        $inventories = $this->getInventories($branchIds, $time_period);

        $upcomingInventories = $this->getUpcomingInventories($branchIds, $time_period);
        $accountPayable = $this->getAccountPayable($branchIds, $time_period);
        $sales = $this->getSales($branchIds, $time_period);
        $cogs = $this->getCogs($branchIds, $time_period);
        $begginingInventory = $this->getBeginningInventory($branchIds);
        $endingInventory = $this->getEndingInventory($branchIds);
        $cogsAll = ProductInventoryStockManager::whereIn('store_branch_id', $branchIds)
            ->where('total_cost', '<', 0)->sum(DB::raw('ABS(total_cost)'));
        $averageInventory = ($begginingInventory + $endingInventory) / 2;
        $dio = $this->getDaysInventoryOutstanding($cogsAll, $averageInventory, $chart_time_period);
        $productInventoryStock = $this->getTop10Products($branchIds, $inventory_type);
        $dpo = $this->getDaysPayableOutstanding($branchIds, $cogsAll, $chart_time_period);

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

    public function getDaysPayableOutstanding($branchIds, $cogsAll, $chart_time_period)
    {
        $accountPayableAll = StoreOrderItem::query()
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            ->join('supplier_items', 'store_order_items.item_code', '=', 'supplier_items.ItemCode')
            ->whereIn('store_orders.store_branch_id', $branchIds)
            ->where('store_order_items.quantity_received', '>', 0)
            ->sum(DB::raw('store_order_items.quantity_received * supplier_items.cost'));

        return $cogsAll > 0 && $accountPayableAll > 0 ? ($accountPayableAll / $cogsAll) * ($chart_time_period == 0 ? 365 : 30) : 0;
    }

    public function getTop10Products($branchIds, $inventory_type)
    {
        // Now using sapMasterfile relationship and SAPMasterfile properties
        $query = ProductInventoryStock::with('sapMasterfile')
            ->whereIn('store_branch_id', $branchIds)
            ->whereHas('sapMasterfile') // Ensure there's a linked SAPMasterfile entry
            ->select('*', DB::raw('(quantity - used) as stock_on_hand'));

        if ($inventory_type === 'cost') {
            // Join with sap_masterfiles to order by calculated cost
            $query->join('sap_masterfiles', 'product_inventory_stocks.product_inventory_id', '=', 'sap_masterfiles.id')
                ->orderByRaw("(quantity - used) * sap_masterfiles.cost DESC"); // Assuming SAPMasterfile has a 'cost' column
        } else {
            $query->orderBy('stock_on_hand', 'desc');
        }

        return $query->take(10)
            ->get()
            ->map(function ($item) {
                // Accessing sapMasterfile properties
                return [
                    'name' => $item->sapMasterfile->ItemDescription, // Use ItemDescription for the name
                    'total_cost' => $item->stock_on_hand * $item->sapMasterfile->cost, // Use cost from SAPMasterfile
                    'quantity' => $item->stock_on_hand
                ];
            });
    }

    public function getDaysInventoryOutstanding($cogsAll, $averageInventory, $chart_time_period)
    {
        return $cogsAll > 0 ? ($averageInventory / $cogsAll) * ($chart_time_period == 0 ? 365 : 30) : 0;
    }

    public function getEndingInventory($branchIds)
    {
        return ProductInventoryStockManager::query()
            ->whereIn('store_branch_id', $branchIds)
            ->sum('total_cost');
    }

    public function getBeginningInventory($branchIds)
    {
        // Optimized: Get the first transaction IDs in one query to avoid N+1 problem
        $firstTransactionIds = ProductInventoryStockManager::whereIn('store_branch_id', $branchIds)
            ->where('quantity', '>', 0)
            ->selectRaw('MIN(id) as id')
            ->groupBy('product_inventory_id')
            ->pluck('id');

        return ProductInventoryStockManager::whereIn('id', $firstTransactionIds)
            ->sum('total_cost');
    }

    public function getCogs($branchIds, $time_period)
    {
        $cogsQuery = ProductInventoryStockManager::whereIn('store_branch_id', $branchIds)
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

    public function getSales($branchIds, $time_period)
    {
        // Optimized: Use join instead of whereHas for better performance
        $query = StoreTransactionItem::join('store_transactions', 'store_transaction_items.store_transaction_id', '=', 'store_transactions.id')
            ->whereIn('store_transactions.store_branch_id', $branchIds);

        if ($time_period != 0) {
            $query->whereMonth('store_transactions.order_date', $time_period);
        } else {
            $query->whereYear('store_transactions.order_date', Carbon::today()->year);
        }

        return number_format(
            $query->sum('store_transaction_items.net_total'),
            2,
            '.',
            ','
        );
    }

    public function getAccountPayable($branchIds, $time_period)
    {
        $accountPayable = StoreOrderItem::query()
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            ->join('supplier_items', 'store_order_items.item_code', '=', 'supplier_items.ItemCode')
            ->whereIn('store_orders.store_branch_id', $branchIds)
            ->where('store_order_items.quantity_received', '>', 0);

        if ($time_period != 0) {
            $accountPayable->whereMonth('store_orders.order_date', $time_period);
        } else {
            $accountPayable->whereYear('store_orders.order_date', Carbon::today()->year);
        }

        return number_format(
            $accountPayable->sum(DB::raw('store_order_items.quantity_received * supplier_items.cost')),
            2,
            '.',
            ','
        );
    }

    public function getUpcomingInventories($branchIds, $time_period)
    {
        $upcomingInventories = StoreOrderItem::query()
            ->join('supplier_items', 'store_order_items.item_code', '=', 'supplier_items.ItemCode')
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            ->whereIn('store_orders.store_branch_id', $branchIds)
            ->where('store_orders.order_status', 'committed');

        if ($time_period != 0) {
            $upcomingInventories->whereMonth('store_orders.order_date', $time_period);
        } else {
            $upcomingInventories->whereYear('store_orders.order_date', Carbon::today()->year);
        }

        return number_format(
            $upcomingInventories->sum(DB::raw('store_order_items.quantity_commited * supplier_items.cost')),
            2,
            '.',
            ','
        );
    }

    public function getInventories($branchIds, $time_period)
    {
        $inventoriesQuery = ProductInventoryStockManager::query()
            ->whereIn('store_branch_id', $branchIds);


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

    public function getHighStockProducts($branchIds)
    {
        // Now using sapMasterfile relationship and SAPMasterfile properties
        $query = ProductInventoryStock::with('sapMasterfile')
            ->whereIn('store_branch_id', $branchIds)
            ->whereHas('sapMasterfile') // Ensure there's a linked SAPMasterfile entry
            ->select('product_inventory_stocks.*') // Select all columns from product_inventory_stocks
            ->selectRaw('(quantity - used) as stock_on_hand') // Calculate stock on hand
            ->orderByDesc('stock_on_hand')
            ->take(4)
            ->get()
            ->map(function ($stock) {
                // Accessing properties via the sapMasterfile relationship
                return [
                    'name' => $stock->sapMasterfile->ItemDescription, // Use ItemDescription from SAPMasterfile
                    'stock' => $stock->quantity - $stock->used,
                ];
            });
        return $query;
    }

    public function getMostUsedProducts($branchIds)
    {
        // Now using sapMasterfile relationship and SAPMasterfile properties
        return ProductInventoryStock::with('sapMasterfile')
            ->whereIn('store_branch_id', $branchIds)
            ->whereHas('sapMasterfile') // Ensure there's a linked SAPMasterfile entry
            ->select('product_inventory_stocks.*') // Select all columns from product_inventory_stocks
            ->selectRaw('used as total_used') // Directly use the 'used' column
            ->orderBy('total_used', 'desc')
            ->take(4)
            ->get()
            ->map(function ($stock) {
                // Accessing properties via the sapMasterfile relationship
                return [
                    'name' => $stock->sapMasterfile->ItemDescription, // Use ItemDescription from SAPMasterfile
                    'used' => $stock->used ?? 0 // Use the 'used' quantity from ProductInventoryStock
                ];
            });
    }

    public function getLowOnStockItems($branchIds)
    {
        $usageRecords = DB::table('usage_records as ur')
            ->join('usage_record_items as uri', 'ur.id', '=', 'uri.usage_record_id')
            ->join('menus as m', 'uri.menu_id', '=', 'm.id')
            ->join('menu_ingredients as mi', 'm.id', '=', 'mi.menu_id')
            // Assuming mi.product_inventory_id now stores SAPMasterfile ID
            ->whereIn('ur.store_branch_id', $branchIds)
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

        // Now, the query for low stock items should use ProductInventoryStock and SAPMasterfile
        $query = ProductInventoryStock::query()
            ->with(['sapMasterfile']) // Load the SAPMasterfile relationship
            ->whereIn('store_branch_id', $branchIds)
            ->whereHas('sapMasterfile') // Ensure there's a linked SAPMasterfile entry
            ->get() // Get all relevant stock items first
            ->filter(function ($stockItem) {
                // Filter based on stock_on_hand being <= 10
                return ($stockItem->quantity - $stockItem->used) <= 10;
            })
            ->map(function ($stockItem) use ($usageRecords) {
                $units = isset($usageRecords[$stockItem->product_inventory_id . '_units'])
                    ? '(' . str_replace(',', ', ', $usageRecords[$stockItem->product_inventory_id . '_units']) . ')'
                    : '';

                return [
                    'id' => $stockItem->sapMasterfile->id, // Use SAPMasterfile ID
                    'name' => $stockItem->sapMasterfile->ItemDescription, // Use ItemDescription
                    'inventory_code' => $stockItem->sapMasterfile->ItemCode, // Use ItemCode
                    'stock_on_hand' => $stockItem->quantity - $stockItem->used,
                    'recorded_used' => $stockItem->used, // This is from ProductInventoryStock
                    'estimated_used' => $usageRecords[$stockItem->product_inventory_id] ?? 0,
                    'ingredient_units' => $units,
                    'uom' => $stockItem->sapMasterfile->BaseUOM, // Assuming BaseUOM is the primary UOM for display
                ];
            });

        return $query->take(10); // Limiting to 10 for consistency with other "top" methods
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
