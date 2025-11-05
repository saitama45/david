<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fix existing interco orders with missing sending_store_branch_id
        // We'll set a default sending store based on the business logic

        Log::info('Starting to fix existing interco orders data...');

        // Get all interco orders with empty or null sending_store_branch_id
        $intercoOrders = DB::table('store_orders')
            ->where('variant', 'INTERCO')
            ->where(function($query) {
                $query->whereNull('sending_store_branch_id')
                      ->orWhere('sending_store_branch_id', '')
                      ->orWhere('sending_store_branch_id', 0);
            })
            ->get();

        Log::info('Found ' . $intercoOrders->count() . ' interco orders to fix');

        // Get available store branches
        $storeBranches = DB::table('store_branches')->pluck('name', 'id')->toArray();
        Log::info('Available store branches:', $storeBranches);

        // For demonstration, let's set Head Office (id=1) as the default sending store
        // In a real scenario, you might want to determine this based on business logic
        $defaultSendingStoreId = 1; // Assuming Head Office has ID 1

        if (!isset($storeBranches[$defaultSendingStoreId])) {
            Log::error('Default sending store ID not found in store_branches table');
            return;
        }

        $updatedCount = 0;
        foreach ($intercoOrders as $order) {
            try {
                // Update the order with a valid sending store ID
                $result = DB::table('store_orders')
                    ->where('id', $order->id)
                    ->update(['sending_store_branch_id' => $defaultSendingStoreId]);

                if ($result) {
                    $updatedCount++;
                    Log::info("Updated order {$order->id} ({$order->interco_number}) with sending store ID {$defaultSendingStoreId}");
                }
            } catch (\Exception $e) {
                Log::error("Failed to update order {$order->id}: " . $e->getMessage());
            }
        }

        Log::info("Successfully updated {$updatedCount} interco orders");

        // Also log information about item codes to help debug the SAP Masterfile mismatch
        $itemCodes = DB::table('store_order_items as soi')
            ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
            ->where('so.variant', 'INTERCO')
            ->distinct()
            ->pluck('soi.item_code')
            ->toArray();

        Log::info('Item codes in existing interco orders:', $itemCodes);

        // Check which of these item codes exist in SAP Masterfile
        $sapCodes = DB::table('sap_masterfiles')
            ->whereIn('ItemCode', $itemCodes)
            ->pluck('ItemCode')
            ->toArray();

        $missingCodes = array_diff($itemCodes, $sapCodes);
        if (!empty($missingCodes)) {
            Log::warning('Item codes in orders but not in SAP Masterfile:', $missingCodes);
        }

        Log::info('Data fix migration completed');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // In the down method, we could revert the changes by setting sending_store_branch_id back to null
        // However, this might not be desirable in a production environment

        Log::info('Reverting interco orders data fix...');

        $revertedCount = DB::table('store_orders')
            ->where('variant', 'INTERCO')
            ->where('sending_store_branch_id', 1) // The default we set in up()
            ->update(['sending_store_branch_id' => null]);

        Log::info("Reverted {$revertedCount} interco orders");
    }
};
