<?php

namespace App\Http\Controllers;

use App\Models\ProductInventoryStock;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreOrderRemark;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class StoreCommitsController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        // Base query for interco orders - filter for orders with interco_number
        $baseQuery = StoreOrder::with(['store_branch', 'supplier', 'store_order_items'])
            ->whereNotNull('interco_number') // Only show interco orders
            ->whereNotNull('store_branch_id') // Filter for store orders
            ->whereNotNull('interco_status'); // Exclude null interco_status

        $query = $baseQuery->when(!$user->is_admin, function($q) use ($user) {
            // Only show orders for user's assigned stores
            $q->whereIn('sending_store_branch_id', $user->store_branches()->pluck('store_branches.id'));
        });

        // Apply status filter using interco_status
        $currentFilter = $request->get('currentFilter', 'all');
        if ($currentFilter === 'approved') {
            $query->where('interco_status', 'approved');
        } elseif ($currentFilter === 'in_transit') {
            $query->where('interco_status', 'in_transit');
        }

        // Apply search
        $search = $request->get('search');
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('order_number', 'like', "%$search%")
                  ->orWhere('remarks', 'like', "%$search%")
                  ->orWhere('batch_reference', 'like', "%$search%")
                  ->orWhereHas('store_branch', function($subQ) use ($search) {
                      $subQ->where('name', 'like', "%$search%")
                            ->orWhere('branch_name', 'like', "%$search%");
                  })
                  ->orWhereHas('supplier', function($subQ) use ($search) {
                      $subQ->where('name', 'like', "%$search%");
                  });
            });
        }

        $orders = $query->latest()->paginate(10);

        // Calculate counts for tabs (respecting user permissions)
        $baseCountQuery = StoreOrder::whereNotNull('interco_number')
            ->whereNotNull('store_branch_id')
            ->whereNotNull('interco_status') // Exclude null interco_status
            ->when(!$user->is_admin, function($q) use ($user) {
                $q->whereIn('sending_store_branch_id', $user->store_branches()->pluck('store_branches.id'));
            });

        $counts = [
            'all' => (clone $baseCountQuery)->count(),
            'approved' => (clone $baseCountQuery)->where('interco_status', 'approved')->count(),
            'in_transit' => (clone $baseCountQuery)->where('interco_status', 'in_transit')->count(),
        ];

        return Inertia::render('StoreCommits/Index', [
            'orders' => $orders,
            'counts' => $counts,
            'filters' => [
                'currentFilter' => $currentFilter,
                'search' => $search ?? '',
            ],
        ]);
    }

    public function show($id)
    {
        $user = auth()->user();

        $order = StoreOrder::with([
            'store_branch',
            'supplier',
            'store_order_items.sapMasterfile',
            'encoder',
            'approver'
        ])
        ->whereNotNull('interco_number') // Only show interco orders
        ->whereNotNull('store_branch_id') // Use same logic as index method
        ->whereNotNull('interco_status') // Exclude null interco_status
        ->when(!$user->is_admin, function($q) use ($user) {
            $q->whereIn('sending_store_branch_id', $user->store_branches()->pluck('store_branches.id'));
        })
        ->findOrFail($id);

        // Get SOH stock for store branch for each item (adapted from IntercoApprovalController)
        $itemsWithStock = $order->store_order_items->map(function ($item) use ($order) {
            $stock = 0;
            $description = 'No description';
            $sapMasterfile = null;
            $stockLookupMethod = 'primary';

            try {
                $sapMasterfile = $item->sapMasterfile;

                if ($sapMasterfile) {
                    $description = $sapMasterfile->ItemDescription ?? $sapMasterfile->ItemName ?? 'No description';

                    // Primary stock lookup using main sapMasterfile relationship for store branch
                    $stock = ProductInventoryStock::where('store_branch_id', $order->store_branch_id)
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
                            $alternativeStock = ProductInventoryStock::where('store_branch_id', $order->store_branch_id)
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
                                    'store_branch_id' => $order->store_branch_id,
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
                            ->where('product_inventory_stocks.store_branch_id', $order->store_branch_id)
                            ->where('sap_masterfiles.ItemCode', $item->item_code)
                            ->sum('product_inventory_stocks.quantity');

                        if ($directStock > 0) {
                            $stock = $directStock;
                            Log::info("Found stock using direct ItemCode lookup for item {$item->item_code}", [
                                'item_code' => $item->item_code,
                                'stock_found' => $stock,
                                'store_branch_id' => $order->store_branch_id,
                                'method' => 'direct_itemcode_lookup'
                            ]);
                        }
                    }

                    // Final logging
                    if ($stock == 0) {
                        Log::warning("No SOH stock found for item {$item->item_code} in store {$order->store_branch_id}", [
                            'sap_masterfile_id' => $sapMasterfile->id,
                            'product_inventory_id' => $sapMasterfile->id,
                            'store_branch_id' => $order->store_branch_id,
                            'item_uom' => $item->uom,
                            'stock_lookup_method' => $stockLookupMethod
                        ]);
                    } else {
                        Log::info("SOH stock found for item {$item->item_code}", [
                            'stock_amount' => $stock,
                            'sap_masterfile_id' => $sapMasterfile->id,
                            'store_branch_id' => $order->store_branch_id,
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
                'item_description' => $item->item_description,
                'description' => $description,
                'quantity_ordered' => $item->quantity_ordered,
                'quantity_approved' => $item->quantity_approved,
                'quantity_commited' => $item->quantity_commited,
                'item_uom' => $item->item_uom,
                'uom' => $item->uom,
                'alt_uom' => $item->alt_uom,
                'cost_per_quantity' => $item->cost_per_quantity,
                'soh_stock' => $stock,
                'sap_masterfile' => $sapMasterfile,
                'sapMasterfile' => $sapMasterfile, // For compatibility with Vue template
            ];
        });

        return Inertia::render('StoreCommits/Show', [
            'order' => $order,
            'items' => $itemsWithStock,
        ]);
    }

    public function commit(Request $request)
    {
        \Log::info('=== StoreCommits commit method called ===', [
            'request_data' => $request->all(),
            'user_id' => auth()->id(),
            'user_is_admin' => auth()->user()->is_admin,
            'has_commit_permission' => auth()->user()->hasPermissionTo('commit store orders')
        ]);

        $request->validate([
            'order_id' => 'required|exists:store_orders,id',
            'action' => 'required|in:commit,decline',
            'remarks' => 'nullable|string|max:1000',
        ]);

        \Log::info('Request validation passed');

        $user = auth()->user();
        $order = StoreOrder::whereNotNull('store_branch_id') // Validate it's a store order
            ->findOrFail($request->order_id);

        \Log::info('Order found', [
            'order_id' => $order->id,
            'store_branch_id' => $order->store_branch_id,
            'interco_status' => $order->interco_status,
            'interco_status_type' => gettype($order->interco_status),
            'interco_status_value' => $order->interco_status?->value
        ]);

        // Validate that user can commit this order
        if (!$user->is_admin) {
            $assignedBranches = $user->store_branches()->pluck('store_branches.id');
            \Log::info('User assigned branches', [
                'branches' => $assignedBranches->toArray(),
                'order_branch_id' => $order->sending_store_branch_id
            ]);

            if (!$assignedBranches->contains($order->sending_store_branch_id)) {
                \Log::error('User not authorized for this branch');
                return redirect()->back()->withErrors(['error' => 'You are not authorized to commit this order.']);
            }
        } else {
            \Log::info('User is admin');
        }

        // Only allow committing/declining approved orders (fixed enum comparison)
        if ($order->interco_status?->value !== 'approved') {
            \Log::error('Order not in approved status', [
                'current_status_value' => $order->interco_status?->value,
                'required_status' => 'approved'
            ]);
            return redirect()->back()->withErrors(['error' => 'Only approved orders can be committed or declined.']);
        }

        \Log::info('All validations passed, proceeding with commit action');

        try {
            DB::beginTransaction();

            $action = $request->action;
            $newStatus = $action === 'commit' ? 'in_transit' : 'declined';

            \Log::info('Updating order status', [
                'action' => $action,
                'new_status' => $newStatus,
                'commiter_id' => $user->id
            ]);

            // Update order status
            $order->update([
                'interco_status' => $newStatus,
                'commiter_id' => $user->id,
                'commited_action_date' => now(),
            ]);

            // Add remark
            StoreOrderRemark::create([
                'user_id' => $user->id,
                'store_order_id' => $order->id,
                'action' => strtoupper($action),
                'remarks' => $request->remarks ?? '', // Use empty string instead of null
            ]);

            DB::commit();

            \Log::info('Order status updated successfully', [
                'order_id' => $order->id,
                'new_status' => $newStatus
            ]);

            $message = $action === 'commit'
                ? 'Order transited successfully.'
                : 'Order declined successfully.';

            return redirect()->back()->with('success', $message);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to process order commit', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'order_id' => $order->id,
                'action' => $request->action
            ]);
            return redirect()->back()->withErrors(['error' => 'Failed to process order: ' . $e->getMessage()]);
        }
    }

    public function updateCommitQuantity(Request $request, $itemId)
    {
        \Log::info('=== updateCommitQuantity method called ===', [
            'itemId' => $itemId,
            'quantity_commited' => $request->input('quantity_commited'),
            'userId' => auth()->id(),
            'userPermissions' => auth()->user()->permissions ?? [],
            'userIsAdmin' => auth()->user()->is_admin,
            'requestMethod' => request()->method(),
            'requestPath' => request()->path(),
            'hasCommitPermission' => auth()->user()->hasPermissionTo('commit store orders')
        ]);

        $request->validate([
            'quantity_commited' => 'required|numeric|min:0',
        ]);

        \Log::info('Request validation passed');

        $user = auth()->user();
        $orderItem = StoreOrderItem::with('store_order')->findOrFail($itemId);

        \Log::info('OrderItem found', [
            'orderItemId' => $orderItem->id,
            'orderId' => $orderItem->store_order_id,
            'store_branch_id' => $orderItem->store_order->store_branch_id,
            'interco_status' => $orderItem->store_order->interco_status,
        ]);

        // Validate it's a store order
        if (!$orderItem->store_order->store_branch_id) {
            \Log::error('Not a store order', ['orderItem' => $orderItem->toArray()]);
            return back()->withErrors(['error' => 'Not a store order']);
        }

        // Validate that user can modify this order
        if (!$user->is_admin) {
            $assignedBranches = $user->store_branches()->pluck('store_branches.id')->toArray();
            \Log::info('User assigned branches', ['branches' => $assignedBranches]);
            \Log::info('Order sending_store_branch_id', ['sending_store_branch_id' => $orderItem->store_order->sending_store_branch_id]);

            if (!in_array($orderItem->store_order->sending_store_branch_id, $assignedBranches)) {
                \Log::error('User not authorized for this branch', [
                    'userId' => $user->id,
                    'userBranches' => $assignedBranches,
                    'orderBranchId' => $orderItem->store_order->sending_store_branch_id
                ]);
                return back()->withErrors(['error' => 'You are not authorized to modify this order']);
            }
        } else {
            \Log::info('User is admin');
        }

        // Only allow modifying approved orders
        if ($orderItem->store_order->interco_status?->value !== 'approved') {
            \Log::error('Order not approved for modification', [
                'currentStatus' => $orderItem->store_order->interco_status?->value,
                'currentStatusType' => gettype($orderItem->store_order->interco_status),
                'requiredStatus' => 'approved'
            ]);
            return back()->withErrors(['error' => 'Quantity can only be modified for orders with "approved" status']);
        }

        \Log::info('All validations passed, attempting database update');

        try {
            $updateResult = $orderItem->update([
                'quantity_commited' => $request->quantity_commited,
            ]);

            \Log::info('Database update successful', [
                'updateResult' => $updateResult,
                'newQuantity' => $request->quantity_commited
            ]);

            return back()->with('success', 'Quantity updated successfully.');
        } catch (\Exception $e) {
            \Log::error('Database update failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'itemId' => $itemId,
                'quantity' => $request->quantity_commited
            ]);
            return back()->withErrors(['error' => 'Failed to update quantity: ' . $e->getMessage()]);
        }
    }

    public function updateCommitQuantityTest(Request $request, $itemId)
    {
        \Log::info('=== updateCommitQuantityTest method called (NO PERMISSIONS) ===', [
            'itemId' => $itemId,
            'quantity_commited' => $request->input('quantity_commited'),
            'userId' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Test route reached successfully',
            'itemId' => $itemId,
            'quantity_commited' => $request->input('quantity_commited')
        ]);
    }

    public function export()
    {
        // TODO: Implement export functionality
        return response()->json(['message' => 'Export functionality coming soon']);
    }
}