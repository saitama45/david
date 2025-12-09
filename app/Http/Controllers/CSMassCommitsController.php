<?php

namespace App\Http\Controllers;

use App\Exports\CSMassCommitsExport;
use App\Models\StoreBranch;
use App\Models\Supplier;
use App\Models\StoreOrder;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class CSMassCommitsController extends Controller
{
    public function index(Request $request)
    {
        set_time_limit(300);
        $user = Auth::user();
        $orderDate = $request->input('order_date', Carbon::today()->format('Y-m-d'));
        $supplierCode = $request->input('supplier_id', 'all');
        $categoryFilter = $request->input('category', 'all');

        $userSuppliers = $user->suppliers()->get();
        $suppliers = $userSuppliers->map(function ($supplier) {
            return [
                'label' => $supplier->name . ' (' . $supplier->supplier_code . ')',
                'value' => $supplier->supplier_code,
            ];
        });

        $supplierId = ($supplierCode === 'all') ? 'all' : Supplier::where('supplier_code', $supplierCode)->first()?->id;

        $dayName = Carbon::parse($orderDate)->format('l');
        
        // Get User Branch IDs
        $userBranchIds = $user->store_branches->pluck('id');

        // Direct query for orders matching all criteria (Date, User Branches, Supplier, Allowed Statuses)
        $allowedStatuses = ['approved', 'committed', 'partial_committed', 'received', 'incomplete'];
        
        $orders = StoreOrder::with('store_branch')
            ->whereDate('order_date', $orderDate)
            ->whereIn('store_branch_id', $userBranchIds)
            ->where('variant', 'mass regular')
            ->when($supplierId !== 'all', function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId);
            })
            ->whereIn('order_status', $allowedStatuses)
            ->whereHas('storeOrderItems', function ($q) {
                $q->where('quantity_commited', '>', 0);
            })
            ->get();

        // Extract unique branches directly from the orders
        $finalBranchesForDisplay = $orders->pluck('store_branch')->unique('id')->values();
        
        // Map statuses
        $branchStatuses = [];
        foreach ($orders as $order) {
            $branchStatuses[$order->store_branch->brand_code] = $order->order_status;
        }

        $reportData = $this->getCSMassCommitsData(
            $orderDate,
            $supplierId,
            $finalBranchesForDisplay,
            $categoryFilter
        );

        $availableCategories = $reportData['report']->pluck('category')->unique()->values()->all();

        return Inertia::render('CSMassCommits/Index', [
            'filters' => [
                'order_date' => $orderDate,
                'supplier_id' => $supplierCode,
                'category' => $categoryFilter,
            ],
            'branches' => $finalBranchesForDisplay->pluck('name', 'id'),
            'suppliers' => $suppliers,
            'report' => $reportData['report'],
            'dynamicHeaders' => $reportData['dynamicHeaders'],
            'totalBranches' => $reportData['totalBranches'],
            'branchStatuses' => $branchStatuses,
            'permissions' => [
                'canEditFinishedGood' => $user->can('edit finished good commits'),
                'canEditOther' => $user->can('edit other commits'),
            ],
            'availableCategories' => $availableCategories,
        ]);
    }

    public function export(Request $request)
    {
        set_time_limit(300);
        $user = Auth::user();
        $orderDate = $request->input('order_date', Carbon::today()->format('Y-m-d'));
        $supplierCode = $request->input('supplier_id', 'all');

        $supplierId = ($supplierCode === 'all') ? 'all' : Supplier::where('supplier_code', $supplierCode)->first()?->id;

        $userSuppliers = $user->suppliers()->get();
        $dayName = Carbon::parse($orderDate)->format('l');
        
        // Get User Branch IDs
        $userBranchIds = $user->store_branches->pluck('id');

        // Direct query for orders matching all criteria (Date, User Branches, Supplier, Allowed Statuses)
        $allowedStatuses = ['approved', 'committed', 'partial_committed', 'received', 'incomplete'];
        
        $orders = StoreOrder::with('store_branch')
            ->whereDate('order_date', $orderDate)
            ->whereIn('store_branch_id', $userBranchIds)
            ->where('variant', 'mass regular')
            ->when($supplierId !== 'all', function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId);
            })
            ->whereIn('order_status', $allowedStatuses)
            ->whereHas('storeOrderItems', function ($q) {
                $q->where('quantity_commited', '>', 0);
            })
            ->get();

        // Extract unique branches directly from the orders
        $finalBranchesForDisplay = $orders->pluck('store_branch')->unique('id')->values();
        
        // Map statuses (though not strictly needed for export logic itself if not passed to view, but kept for consistency if used by helper)
        $branchStatuses = [];
        foreach ($orders as $order) {
            $branchStatuses[$order->store_branch->brand_code] = $order->order_status;
        }

        $reportData = $this->getCSMassCommitsData(
            $orderDate,
            $supplierId,
            $finalBranchesForDisplay
        );

        return Excel::download(
            new CSMassCommitsExport(
                $reportData['report'],
                $reportData['dynamicHeaders'],
                $reportData['totalBranches'],
                $orderDate
            ),
            'cs-mass-commits-' . Carbon::parse($orderDate)->format('Y-m-d') . '.xlsx'
        );
    }

    private function getCSMassCommitsData(string $orderDate, $supplierId = 'all', ?Collection $scheduledBranches = null, string $categoryFilter = 'all'): array
    {
        // If scheduledBranches is passed but empty (e.g. all orders are pending), return empty report
        if ($scheduledBranches !== null && $scheduledBranches->isEmpty()) {
            $staticHeaders = [
                ['label' => 'CATEGORY', 'field' => 'category'],
                ['label' => 'CLASSIFICATION', 'field' => 'classification'],
                ['label' => 'ITEM CODE', 'field' => 'item_code'],
                ['label' => 'ITEM NAME', 'field' => 'item_name'],
                ['label' => 'UNIT', 'field' => 'unit'],
            ];
            $trailingHeaders = [
                ['label' => 'TOTAL', 'field' => 'total_quantity'],
                ['label' => 'WHSE', 'field' => 'whse'],
            ];
            return [
                'report' => collect(),
                'dynamicHeaders' => array_merge($staticHeaders, $trailingHeaders),
                'totalBranches' => 0,
            ];
        }

        // 1. Use the scheduled branches passed to the function to build the headers.
        $allBranches = $scheduledBranches ? $scheduledBranches->unique('id')->sortBy('brand_code') : collect();
        $brandCodes = $allBranches->pluck('brand_code')->toArray();
        $totalBranches = count($brandCodes);

        // 2. Build the query to mirror the provided SQL structure
        // Select necessary columns
        $query = \App\Models\StoreOrderItem::query()
            ->select(
                'store_order_items.*',
                'supplier_items.sort_order as supplier_sort_order',
                'supplier_items.category as supplier_category',
                'supplier_items.classification as supplier_classification',
                'supplier_items.item_name as supplier_item_name',
                'sap_masterfiles.ItemDescription as sap_item_description'
            )
            // Left Join store_orders
            ->leftJoin('store_orders', 'store_orders.id', '=', 'store_order_items.store_order_id')
            // Left Join suppliers (needed for linking supplier_items via SupplierCode)
            ->leftJoin('suppliers', 'suppliers.id', '=', 'store_orders.supplier_id')
            // Left Join sap_masterfiles (Key bridge: ItemCode AND AltUOM matches store_order_items)
            ->leftJoin('sap_masterfiles', function($join) {
                $join->on('sap_masterfiles.ItemCode', '=', 'store_order_items.item_code')
                     ->on('sap_masterfiles.AltUOM', '=', 'store_order_items.uom');
            })
            // Left Join supplier_items (Linked via SAP ItemCode/UOM and SupplierCode from suppliers table)
            ->leftJoin('supplier_items', function($join) {
                $join->on('supplier_items.ItemCode', '=', 'sap_masterfiles.ItemCode')
                     ->on('supplier_items.uom', '=', 'sap_masterfiles.AltUOM')
                     ->on('supplier_items.SupplierCode', '=', 'suppliers.supplier_code');
            })
            // Eager load for PHP side access (branches/suppliers/etc)
            ->with(['store_order.store_branch', 'store_order.supplier'])
            // Filters
            ->whereDate('store_orders.order_date', $orderDate)
            ->where('store_order_items.quantity_commited', '>', 0);

        if ($supplierId !== 'all') {
             $query->where('store_orders.supplier_id', $supplierId);
        }

        if ($scheduledBranches && $scheduledBranches->isNotEmpty()) {
             $query->whereIn('store_orders.store_branch_id', $scheduledBranches->pluck('id'));
        }

        if ($categoryFilter !== 'all') {
            $query->where('supplier_items.category', $categoryFilter);
        }

        // Apply Sorting: items with sort_order = 0 or NULL go to the end
        $query->orderByRaw('CASE WHEN ISNULL(supplier_items.sort_order, 0) = 0 THEN 1 ELSE 0 END, supplier_items.sort_order ASC');

        $storeOrderItems = $query->get();

        // 3. Aggregate and pivot in PHP
        $reportItems = $storeOrderItems->groupBy(function ($item) {
            return strtoupper(trim($item->item_code)) . '|' . strtoupper(trim($item->uom));
        })->map(function ($group) use ($brandCodes) {
            $first = $group->first();
            
            $category = $first->supplier_category ?? 'N/A';
            $classification = $first->supplier_classification;

            // CRITICAL FIX: Iterate through group to find the definitive sort order.
            // If ANY item in this group has sort_order 0 (or null), the whole row is treated as sort_order 0.
            // This ensures it gets pushed to the end, overriding any non-zero sort_orders that might have been picked up by first().
            $sortOrder = null;
            foreach ($group as $item) {
                $s = $item->supplier_sort_order;
                if ($s === 0 || $s === '0' || $s === null) {
                    $sortOrder = 0;
                    break; 
                }
                 // Keep the first non-zero sort order encountered as a fallback
                if ($sortOrder === null) {
                    $sortOrder = $s;
                }
            }
            // If loop finishes and sortOrder is still null (e.g. empty group), default to 0
            if ($sortOrder === null) {
                 $sortOrder = 0;
            }
            
            // Item Name Priority: SAP Description > SAP Name > Supplier Item Name > N/A
            $itemName = $first->sap_item_description ?: ($first->sap_item_name_alt ?: ($first->supplier_item_name ?? 'N/A'));

            $row = [
                'category' => $category,
                'classification' => $classification,
                'item_code' => $first->item_code,
                'item_name' => $itemName,
                'unit' => $first->uom,
                'sort_order' => $sortOrder, 
                'whse' => $this->getWhseCode($first->store_order->supplier->supplier_code ?? null),
            ];

            // Initialize branches
            foreach ($brandCodes as $code) {
                $row[$code] = 0.0;
            }

            // Sum quantities
            foreach ($group as $item) {
                if ($item->store_order && $item->store_order->store_branch) {
                    $brand = $item->store_order->store_branch->brand_code;
                    if (isset($row[$brand])) {
                        $row[$brand] += $item->quantity_commited;
                    }
                }
            }

            $row['total_quantity'] = array_sum(array_intersect_key($row, array_flip($brandCodes)));

            return $row;
        })
        ->values();
        // 4. Build headers
        $staticHeaders = [
            ['label' => 'CATEGORY', 'field' => 'category'],
            ['label' => 'CLASSIFICATION', 'field' => 'classification'],
            ['label' => 'ITEM CODE', 'field' => 'item_code'],
            ['label' => 'ITEM NAME', 'field' => 'item_name'],
            ['label' => 'UNIT', 'field' => 'unit'],
        ];

        $dynamicBranchHeaders = $allBranches->map(function ($branch) {
            return ['label' => $branch->brand_code, 'field' => $branch->brand_code];
        })->toArray();

        $trailingHeaders = [
            ['label' => 'TOTAL', 'field' => 'total_quantity'],
            ['label' => 'WHSE', 'field' => 'whse'],
        ];

        $allHeaders = array_merge($staticHeaders, $dynamicBranchHeaders, $trailingHeaders);

        return [
            'report' => $reportItems,
            'dynamicHeaders' => $allHeaders,
            'totalBranches' => $totalBranches,
        ];
    }

    private function getWhseCode(?string $supplierCode): string
    {
        switch ($supplierCode) {
            case 'GSI-P':
                return '03';
            case 'GSI-B':
                return '03';
            case 'PUL-O':
                return '01';
            default:
                return 'N/A';
        }
    }

    public function updateCommit(Request $request)
    {
        $validated = $request->validate([
            'order_date' => 'required|date',
            'item_code' => 'required|string|exists:supplier_items,ItemCode',
            'brand_code' => 'required|string|exists:store_branches,brand_code',
            'new_quantity' => 'required|numeric|min:0',
        ]);

        // Find the item's category for permission checking
        $supplierItem = \App\Models\SupplierItems::where('ItemCode', $validated['item_code'])->firstOrFail();
        $category = $supplierItem->category;

        // Perform permission check
        $user = Auth::user();
        $isFinishedGood = in_array($category, ['FINISHED GOODS', 'FG', 'FINISHED GOOD']);

        if ($isFinishedGood && !$user->can('edit finished good commits')) {
            return response()->json(['message' => 'You do not have permission to edit items in the FINISHED GOOD category.'], 403);
        }

        if (!$isFinishedGood && !$user->can('edit other commits')) {
            return response()->json(['message' => 'You do not have permission to edit items in this category.'], 403);
        }

        $orderItem = \App\Models\StoreOrderItem::where('item_code', $validated['item_code'])
            ->whereHas('store_order', function ($query) use ($validated) {
                $query->whereDate('order_date', $validated['order_date'])
                      ->whereHas('store_branch', function ($subQuery) use ($validated) {
                          $subQuery->where('brand_code', $validated['brand_code']);
                      });
            })
            ->first();

        if (!$orderItem) {
            return response()->json(['message' => 'Order item not found for the specified criteria.'], 404);
        }

        // Validate that the order is not already received or incomplete
        $orderStatus = strtolower($orderItem->store_order->order_status);
        if (in_array($orderStatus, ['received', 'incomplete'])) {
            return response()->json(['message' => 'Cannot edit. Order is already ' . $orderStatus . '.'], 422);
        }

        $orderItem->update(['quantity_commited' => $validated['new_quantity']]);

        return redirect()->back()->with('success', 'Commit quantity updated successfully.');
    }

    public function confirmAll(Request $request)
    {
        $validated = $request->validate([
            'order_date' => 'required|date',
            'supplier_id' => 'required|string',
        ]);

        \Log::info('CS Mass Commits - confirmAll started', [
            'user_id' => Auth::id(),
            'order_date' => $validated['order_date'],
            'supplier_id' => $validated['supplier_id']
        ]);

        $user = Auth::user();
        $user->load('store_branches', 'suppliers');

        \Log::info('CS Mass Commits - User permissions loaded', [
            'can_edit_finished_good_commits' => $user->can('edit finished good commits'),
            'can_edit_other_commits' => $user->can('edit other commits'),
            'assigned_branches_count' => $user->store_branches->count(),
            'assigned_suppliers_count' => $user->suppliers->count()
        ]);

        $userBranchIds = $user->store_branches->pluck('id');
        $userSupplierIds = $user->suppliers->pluck('id');

        $query = StoreOrder::query()
            ->whereDate('order_date', $validated['order_date'])
            ->whereIn('store_branch_id', $userBranchIds)
            ->whereNotIn('order_status', ['received', 'incomplete']);

        // If a specific supplier is chosen, filter by it. Otherwise, filter by all user's assigned suppliers.
        if ($validated['supplier_id'] !== 'all') {
            $supplier = Supplier::where('supplier_code', $validated['supplier_id'])->first();
            if ($supplier) {
                $query->where('supplier_id', $supplier->id);
            }
        } else {
            $query->whereIn('supplier_id', $userSupplierIds);
        }

        // Get the orders that are about to be updated, along with their items and relationships for permission checking
        $ordersToCommit = $query->with([
            'store_order_items.sapMasterfile',
            'store_order_items.supplierItem',
            'store_order_items.ordered_item_receive_dates'
        ])->get();

        \Log::info('CS Mass Commits - Orders query executed', [
            'orders_found' => $ordersToCommit->count(),
            'order_date' => $validated['order_date'],
            'supplier_filter' => $validated['supplier_id']
        ]);

        $updatedOrdersCount = 0;
        $updatedItemsCount = 0;
        $skippedItemsCount = 0;

        if ($ordersToCommit->count() > 0) {
            // Process each order and its items based on user permissions
            foreach ($ordersToCommit as $order) {
                $orderHasUpdates = false;
                $itemCount = 0;

                \Log::debug('CS Mass Commits - Processing order', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'items_count' => $order->store_order_items->count()
                ]);

                foreach ($order->store_order_items as $item) {
                    $itemCount++;
                    $itemCategory = $item->sapMasterfile->Category ?? 'Unknown';
                    $canCommit = $item->canBeCommittedBy($user);

                    \Log::debug('CS Mass Commits - Checking item permissions', [
                        'item_code' => $item->item_code,
                        'item_category' => $itemCategory,
                        'can_commit' => $canCommit,
                        'item_id' => $item->id
                    ]);

                    // Check if user can commit this specific item based on permissions
                    if (!$canCommit) {
                        $skippedItemsCount++;
                        \Log::debug('CS Mass Commits - Item skipped due to permissions', [
                            'item_code' => $item->item_code,
                            'item_category' => $itemCategory,
                            'reason' => 'User lacks required permission'
                        ]);
                        continue;
                    }

                    // Mark item as committed by this user (audit tracking)
                    $item->markAsCommittedBy($user->id);

                    \Log::debug('CS Mass Commits - Item committed successfully', [
                        'item_code' => $item->item_code,
                        'item_category' => $itemCategory,
                        'committed_by' => $user->id,
                        'quantity_commited' => $item->quantity_commited
                    ]);

                    // Create placeholder receive date record if it doesn't exist
                    if ($item->quantity_commited > 0 && $item->ordered_item_receive_dates()->doesntExist()) {
                        $item->ordered_item_receive_dates()->create([
                            'quantity_received' => $item->quantity_commited,
                            'status' => 'pending',
                            'received_by_user_id' => $user->id,
                        ]);

                        \Log::debug('CS Mass Commits - Receive date record created', [
                            'item_code' => $item->item_code,
                            'quantity' => $item->quantity_commited
                        ]);
                    }

                    $updatedItemsCount++;
                    $orderHasUpdates = true;
                }

                \Log::info('CS Mass Commits - Order processing complete', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'total_items' => $itemCount,
                    'updated_items' => $updatedItemsCount,
                    'has_updates' => $orderHasUpdates
                ]);

                // Update order status based on commit status of its items
                if ($orderHasUpdates) {
                    $oldStatus = $order->order_status;
                    $order->updateOrderStatusBasedOnCommits();

                    \Log::info('CS Mass Commits - Order status updated', [
                        'order_id' => $order->id,
                        'old_status' => $oldStatus,
                        'new_status' => $order->order_status
                    ]);

                    // Set order-level commit info if this is the first time items are being committed
                    if ($order->order_status === \App\Enum\OrderStatus::COMMITTED->value) {
                        $order->update([
                            'commiter_id' => $user->id,
                            'commited_action_date' => Carbon::now(),
                        ]);

                        \Log::info('CS Mass Commits - Order fully committed', [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'commiter_id' => $user->id
                        ]);
                    }

                    $updatedOrdersCount++;
                }
            }
        } else {
            \Log::warning('CS Mass Commits - No orders found for processing', [
                'order_date' => $validated['order_date'],
                'supplier_filter' => $validated['supplier_id'],
                'user_branches' => $userBranchIds->toArray(),
                'user_suppliers' => $userSupplierIds->toArray()
            ]);
        }

        // Build appropriate success message
        $message = [];
        if ($updatedOrdersCount > 0) {
            $message[] = $updatedOrdersCount . ' order(s) processed';
        }
        if ($updatedItemsCount > 0) {
            $message[] = $updatedItemsCount . ' item(s) committed';
        }
        if ($skippedItemsCount > 0) {
            $message[] = $skippedItemsCount . ' item(s) skipped due to permissions';
        }

        \Log::info('CS Mass Commits - Processing complete', [
            'updated_orders' => $updatedOrdersCount,
            'updated_items' => $updatedItemsCount,
            'skipped_items' => $skippedItemsCount,
            'user_id' => $user->id
        ]);

        if (empty($message)) {
            \Log::warning('CS Mass Commits - No items were committed', [
                'user_id' => $user->id,
                'reason' => 'All items were skipped due to permissions or no items found'
            ]);
            return redirect()->back()->with('info', 'No items were available for commit based on your permissions.');
        }

        return redirect()->back()->with('success', implode(' | ', $message) . '.');
    }
}
