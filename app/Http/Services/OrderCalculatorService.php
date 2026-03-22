<?php

namespace App\Http\Services;

use App\Models\SupplierItems;
use App\Models\ProductInventoryStock;
use App\Models\StoreOrderItem;
use App\Models\StoreOrder;
use App\Models\StoreTransactionItem;
use App\Models\StoreTransaction;
use App\Models\POSMasterfileBOM;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderCalculatorService
{
    public function getCalculatorData(
        $storeBranchId,
        $orderingTemplate,
        $targetDtl,
        $sundayDate,
        $aduMonth,
        $pmixMonths
    ) {
        $items = SupplierItems::with(['sapMasterfiles'])
            ->where('SupplierCode', $orderingTemplate)
            ->where('is_active', true)
            ->get();

        $itemCodes = $items->pluck('ItemCode')->unique()->toArray();

        // 1. SOH (Sunday Ending Inventory)
        $sapIds = [];
        $itemCodeToSapId = [];
        foreach ($items as $item) {
            $sap = $item->sap_master_file;
            if ($sap) {
                $sapIds[] = $sap->id;
                $itemCodeToSapId[$item->ItemCode] = $sap->id;
            }
        }

        $stocks = ProductInventoryStock::where('store_branch_id', $storeBranchId)
            ->whereIn('product_inventory_id', $sapIds)
            ->get()
            ->keyBy('product_inventory_id');

        // 2. Incoming Deliveries (Current Week)
        $startOfWeek = Carbon::parse($sundayDate)->startOfWeek(Carbon::MONDAY)->format('Y-m-d H:i:s');
        $endOfWeek = Carbon::parse($sundayDate)->endOfDay()->format('Y-m-d H:i:s');

        $incomingDeliveries = DB::table('store_order_items')
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            ->where('store_orders.store_branch_id', $storeBranchId)
            ->whereIn('store_orders.order_status', ['approved', 'commited'])
            ->whereBetween('store_orders.order_date', [$startOfWeek, $endOfWeek])
            ->whereIn('store_order_items.item_code', $itemCodes)
            ->select('store_order_items.item_code', DB::raw('SUM(store_order_items.quantity_approved) as total_incoming'))
            ->groupBy('store_order_items.item_code')
            ->pluck('total_incoming', 'item_code');

        // 3. ADU
        $aduStart = Carbon::parse($aduMonth)->startOfMonth();
        $aduEnd = Carbon::parse($aduMonth)->endOfMonth();
        $aduDays = $aduStart->diffInDays($aduEnd) + 1;

        $historicalOrders = DB::table('store_order_items')
            ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
            ->where('store_orders.store_branch_id', $storeBranchId)
            ->whereBetween('store_orders.order_date', [$aduStart->format('Y-m-d H:i:s'), $aduEnd->format('Y-m-d H:i:s')])
            ->whereIn('store_order_items.item_code', $itemCodes)
            ->select('store_order_items.item_code', DB::raw('SUM(store_order_items.quantity_approved) as total_orders'))
            ->groupBy('store_order_items.item_code')
            ->pluck('total_orders', 'item_code');

        // 4. PMIX (Multiple Months)
        $pmixTotalDays = 0;
        $pmixConsumptionsArray = [];

        foreach ($pmixMonths as $month) {
            $pmixStart = Carbon::parse($month)->startOfMonth();
            $pmixEnd = Carbon::parse($month)->endOfMonth();
            $pmixTotalDays += ($pmixStart->diffInDays($pmixEnd) + 1);

            $consumption = DB::table('store_transaction_items')
                ->join('store_transactions', 'store_transaction_items.store_transaction_id', '=', 'store_transactions.id')
                ->join('pos_masterfiles', 'store_transaction_items.product_id', '=', 'pos_masterfiles.id')
                ->join('pos_masterfiles_bom', 'pos_masterfiles.POSCode', '=', 'pos_masterfiles_bom.POSCode')
                ->where('store_transactions.store_branch_id', $storeBranchId)
                ->whereBetween('store_transactions.order_date', [$pmixStart->format('Y-m-d'), $pmixEnd->format('Y-m-d')])
                ->whereIn('pos_masterfiles_bom.ItemCode', $itemCodes)
                ->select('pos_masterfiles_bom.ItemCode as item_code', DB::raw('SUM(store_transaction_items.quantity * pos_masterfiles_bom.BOMQty) as total_consumption'))
                ->groupBy('pos_masterfiles_bom.ItemCode')
                ->pluck('total_consumption', 'item_code');

            $pmixConsumptionsArray[] = $consumption;
        }

        // Aggregate PMIX Consumption across all selected months
        $aggregatedPmixConsumption = [];
        foreach ($pmixConsumptionsArray as $monthConsumption) {
            foreach ($monthConsumption as $itemCode => $qty) {
                if (!isset($aggregatedPmixConsumption[$itemCode])) {
                    $aggregatedPmixConsumption[$itemCode] = 0;
                }
                $aggregatedPmixConsumption[$itemCode] += $qty;
            }
        }

        $result = [];

        foreach ($items as $item) {
            $code = $item->ItemCode;
            $sapId = $itemCodeToSapId[$code] ?? null;
            $soh = 0;
            if ($sapId && isset($stocks[$sapId])) {
                $soh = $stocks[$sapId]->quantity;
            }

            $incoming = $incomingDeliveries[$code] ?? 0;

            // ADU
            $totalOrders = $historicalOrders[$code] ?? 0;
            $adu = $aduDays > 0 ? ($totalOrders / $aduDays) : 0;

            // PMIX
            $totalConsumption = $aggregatedPmixConsumption[$code] ?? 0;
            $pmix = $pmixTotalDays > 0 ? ($totalConsumption / $pmixTotalDays) : 0;

            $result[] = [
                'category' => $item->category,
                'brand' => $item->brand,
                'classification' => $item->classification,
                'item_code' => $code,
                'item_name' => $item->item_name,
                'packaging_config' => $item->packaging_config,
                'uom' => $item->uom,
                'sunday_ending_inventory' => (float)$soh,
                'incoming_deliveries' => (float)$incoming,
                'base_adu' => (float)$adu,
                'base_pmix' => (float)$pmix,
            ];
        }

        return $result;
    }
}
