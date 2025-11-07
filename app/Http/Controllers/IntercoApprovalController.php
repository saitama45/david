<?php

namespace App\Http\Controllers;

use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\ProductInventoryStock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IntercoApprovalController extends Controller
{
    /**
     * Display a listing of interco orders for approval.
     */
    public function index(Request $request)
    {
        $currentFilter = $request->get('currentFilter', 'open');
        $search = $request->get('search');

        // Get current user's assigned store branches
        $user = Auth::user();
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');

        // Base query for interco orders
        $query = StoreOrder::with([
            'sendingStore',
            'store_branch',
            'store_order_items.sapMasterfile'
        ])
        ->whereNotNull('interco_number')
        ->whereNotNull('sending_store_branch_id')
        ->where('variant', 'INTERCO');

        // Apply store-based filtering for ALL users (no role exceptions)
        if ($assignedStoreIds->isNotEmpty()) {
            $query->whereIn('store_branch_id', $assignedStoreIds);
        } else {
            // User has no assigned stores - return empty results
            $query->whereRaw('1 = 0');
        }

        // Apply search filter
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('interco_number', 'like', "%{$search}%")
                  ->orWhere('interco_reason', 'like', "%{$search}%")
                  ->orWhereHas('sendingStore', function ($subQuery) use ($search) {
                      $subQuery->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('store_branch', function ($subQuery) use ($search) {
                      $subQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Apply status filter
        if ($currentFilter === 'open') {
            $query->where('interco_status', 'open');
        } elseif ($currentFilter === 'disapproved') {
            $query->where('interco_status', 'disapproved');
        }
        // 'all' shows all statuses

        $orders = $query->latest()->paginate(10);

        // Get counts for tabs with same store filtering
        $baseCountQuery = StoreOrder::whereNotNull('interco_number')
            ->whereNotNull('sending_store_branch_id')
            ->where('variant', 'INTERCO');

        // Apply the same store filtering to count queries
        if ($assignedStoreIds->isNotEmpty()) {
            $baseCountQuery->whereIn('store_branch_id', $assignedStoreIds);
        }

        $allCount = (clone $baseCountQuery)->count();

        $openCount = (clone $baseCountQuery)->where('interco_status', 'open')->count();

        $disapprovedCount = (clone $baseCountQuery)->where('interco_status', 'disapproved')->count();

        return Inertia::render('IntercoApproval/Index', [
            'orders' => $orders,
            'counts' => [
                'all' => $allCount,
                'open' => $openCount,
                'disapproved' => $disapprovedCount,
            ],
            'filters' => $request->only(['currentFilter', 'search']),
        ]);
    }

    /**
     * Display the specified interco order.
     */
    public function show($id)
    {
        $order = StoreOrder::with([
            'sendingStore',
            'store_branch',
            'store_order_items.sapMasterfile'
        ])
        ->whereNotNull('interco_number')
        ->whereNotNull('sending_store_branch_id')
        ->where('variant', 'INTERCO')
        ->findOrFail($id);

        // Get SOH stock for sending store for each item
        $itemsWithStock = $order->store_order_items->map(function ($item) use ($order) {
            $stock = 0;
            $description = 'No description';
            $sapMasterfile = null;
            $stockLookupMethod = 'primary';

            try {
                $sapMasterfile = $item->sapMasterfile;

                if ($sapMasterfile) {
                    $description = $sapMasterfile->ItemDescription ?? $sapMasterfile->ItemName ?? 'No description';

                    // Primary stock lookup using main sapMasterfile relationship
                    $stock = ProductInventoryStock::where('store_branch_id', $order->sending_store_branch_id)
                        ->where('product_inventory_id', $sapMasterfile->id)
                        ->sum('quantity');

                    // Fallback 1: If stock is 0, try other SAP masterfile records with same ItemCode
                    if ($stock == 0) {
                        $stockLookupMethod = 'fallback_duplicate_check';

                        // Find all SAP masterfile records with this ItemCode
                        $alternativeMasterfiles = \App\Models\SAPMasterfile::where('ItemCode', $item->item_code)
                            ->where('id', '!=', $sapMasterfile->id)
                            ->where('is_active', true)
                            ->get();

                        foreach ($alternativeMasterfiles as $alternative) {
                            $alternativeStock = ProductInventoryStock::where('store_branch_id', $order->sending_store_branch_id)
                                ->where('product_inventory_id', $alternative->id)
                                ->sum('quantity');

                            if ($alternativeStock > 0) {
                                $stock = $alternativeStock;
                                $sapMasterfile = $alternative; // Update to the working masterfile
                                $description = $alternative->ItemDescription ?? $alternative->ItemName ?? 'No description';

                                Log::info("Found stock using alternative SAP masterfile for item {$item->item_code}", [
                                    'original_sap_masterfile_id' => $item->sapMasterfile->id,
                                    'working_sap_masterfile_id' => $alternative->id,
                                    'stock_found' => $stock,
                                    'method' => 'duplicate_itemcode_fallback'
                                ]);
                                break;
                            }
                        }
                    }

                    // Fallback 2: Direct ItemCode lookup if still 0 (for debugging)
                    if ($stock == 0) {
                        $stockLookupMethod = 'direct_itemcode_lookup';
                        $directStock = ProductInventoryStock::join('sap_masterfiles', 'sap_masterfiles.id', '=', 'product_inventory_stocks.product_inventory_id')
                            ->where('product_inventory_stocks.store_branch_id', $order->sending_store_branch_id)
                            ->where('sap_masterfiles.ItemCode', $item->item_code)
                            ->sum('product_inventory_stocks.quantity');

                        if ($directStock > 0) {
                            $stock = $directStock;
                            Log::info("Found stock using direct ItemCode lookup for item {$item->item_code}", [
                                'item_code' => $item->item_code,
                                'stock_found' => $stock,
                                'method' => 'direct_itemcode_lookup'
                            ]);
                        }
                    }

                    // Final logging
                    if ($stock == 0) {
                        Log::warning("No SOH stock found for item {$item->item_code} in store {$order->sending_store_branch_id}", [
                            'sap_masterfile_id' => $sapMasterfile->id,
                            'product_inventory_id' => $sapMasterfile->id,
                            'item_uom' => $item->uom,
                            'stock_lookup_method' => $stockLookupMethod
                        ]);
                    } else {
                        Log::info("SOH stock found for item {$item->item_code}", [
                            'stock_amount' => $stock,
                            'sap_masterfile_id' => $sapMasterfile->id,
                            'stock_lookup_method' => $stockLookupMethod
                        ]);
                    }
                } else {
                    // Log missing sapMasterfile for debugging
                    Log::warning("Missing SAP masterfile for item code: {$item->item_code}", [
                        'store_order_item_id' => $item->id,
                        'sap_masterfile_id' => $item->sap_masterfile_id,
                        'item_code' => $item->item_code
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("Error getting SOH stock for item {$item->item_code}: " . $e->getMessage());
            }

            return [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'description' => $description,
                'quantity_ordered' => $item->quantity_ordered,
                'quantity_approved' => $item->quantity_approved,
                'uom' => $item->uom,
                'alt_uom' => $item->alt_uom,
                'cost_per_quantity' => $item->cost_per_quantity,
                'soh_stock' => $stock,
                'sap_masterfile' => $sapMasterfile,
            ];
        });

        return Inertia::render('IntercoApproval/Show', [
            'order' => [
                'id' => $order->id,
                'interco_number' => $order->interco_number,
                'sending_store' => $order->sendingStore,
                'receiving_store' => $order->store_branch,
                'interco_reason' => $order->interco_reason,
                'remarks' => $order->remarks,
                'interco_status' => $order->interco_status,
                'transfer_date' => $order->transfer_date,
                'order_date' => $order->order_date,
                'created_at' => $order->created_at,
            ],
            'items' => $itemsWithStock,
        ]);
    }

    /**
     * Approve the specified interco order.
     */
    public function approve(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:store_orders,id',
        ]);

        try {
            DB::beginTransaction();

            $order = StoreOrder::findOrFail($validated['order_id']);

            // Update order status to approved with approver information
            $order->update([
                'interco_status' => 'approved',
                'approver_id' => Auth::id(),
                'approval_action_date' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return back()->with('success', 'Interco order approved successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error approving interco order: ' . $e->getMessage());
            return back()->with('error', 'Failed to approve interco order.');
        }
    }

    /**
     * Disapprove the specified interco order.
     */
    public function disapprove(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:store_orders,id',
        ]);

        try {
            DB::beginTransaction();

            $order = StoreOrder::findOrFail($validated['order_id']);

            // Update order status to disapproved with approver information
            $order->update([
                'interco_status' => 'disapproved',
                'approver_id' => Auth::id(),
                'approval_action_date' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return back()->with('success', 'Interco order disapproved successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error disapproving interco order: ' . $e->getMessage());
            return back()->with('error', 'Failed to disapprove interco order.');
        }
    }

    /**
     * Update the approved quantity for an item.
     */
    public function updateQuantity(Request $request, $itemId)
    {
        $validated = $request->validate([
            'quantity_approved' => 'required|numeric|min:0',
            'quantity_commited' => 'required|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            $item = StoreOrderItem::findOrFail($itemId);

            // Update both approved and committed quantities
            $item->update([
                'quantity_approved' => $validated['quantity_approved'],
                'quantity_commited' => $validated['quantity_commited'],
                'updated_at' => now(),
            ]);

            DB::commit();

            return back()->with('success', 'Quantity updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating item quantity: ' . $e->getMessage());
            return back()->with('error', 'Failed to update quantity.');
        }
    }
}