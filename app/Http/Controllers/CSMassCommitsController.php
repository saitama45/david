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
            ->has('storeOrderItems')
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
            ->has('storeOrderItems')
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
        // Select necessary columns with Aggregation
        $selects = [
            'store_order_items.item_code',
            'store_order_items.uom as unit',
            \Illuminate\Support\Facades\DB::raw('MAX(supplier_items.category) as category'),
            \Illuminate\Support\Facades\DB::raw('MAX(supplier_items.classification) as classification'),
            \Illuminate\Support\Facades\DB::raw('MAX(supplier_items.item_name) as supplier_item_name'),
            \Illuminate\Support\Facades\DB::raw('MAX(sap_masterfiles.ItemDescription) as sap_item_description'),
            \Illuminate\Support\Facades\DB::raw('MAX(supplier_items.sort_order) as sort_order'),
            \Illuminate\Support\Facades\DB::raw('MAX(suppliers.supplier_code) as supplier_code'),
            \Illuminate\Support\Facades\DB::raw('SUM(store_order_items.quantity_commited) as total_quantity'),
            \Illuminate\Support\Facades\DB::raw('MAX(store_order_items.updated_at) as updated_at'),
        ];

        // Dynamic Pivot Columns
        $bindings = [];
        foreach ($brandCodes as $code) {
            $selects[] = \Illuminate\Support\Facades\DB::raw("SUM(CASE WHEN store_branches.brand_code = ? THEN store_order_items.quantity_commited ELSE 0 END) as [{$code}]");
            $bindings[] = $code;
            $selects[] = \Illuminate\Support\Facades\DB::raw("SUM(CASE WHEN store_branches.brand_code = ? THEN store_order_items.quantity_approved ELSE 0 END) as [approved_{$code}]");
            $bindings[] = $code;
            // Check if record exists for this branch
            $selects[] = \Illuminate\Support\Facades\DB::raw("MAX(CASE WHEN store_branches.brand_code = ? THEN 1 ELSE 0 END) as [exists_{$code}]");
            $bindings[] = $code;
        }

        $query = \App\Models\StoreOrderItem::query()
            ->select($selects)
            ->addBinding($bindings, 'select')
            // Join store_orders
            ->join('store_orders', 'store_orders.id', '=', 'store_order_items.store_order_id')
            // Join store_branches to get brand_code for pivoting
            ->join('store_branches', 'store_branches.id', '=', 'store_orders.store_branch_id')
            // Left Join suppliers
            ->leftJoin('suppliers', 'suppliers.id', '=', 'store_orders.supplier_id')
            // Left Join sap_masterfiles
            ->leftJoin('sap_masterfiles', function($join) {
                $join->on('sap_masterfiles.ItemCode', '=', 'store_order_items.item_code')
                     ->on('sap_masterfiles.AltUOM', '=', 'store_order_items.uom');
            })
            // Left Join supplier_items
            ->leftJoin('supplier_items', function($join) {
                $join->on('supplier_items.ItemCode', '=', 'sap_masterfiles.ItemCode')
                     ->on('supplier_items.uom', '=', 'sap_masterfiles.AltUOM')
                     ->on('supplier_items.SupplierCode', '=', 'suppliers.supplier_code');
            })
            // Filters
            ->whereDate('store_orders.order_date', $orderDate)
            ->where('store_order_items.quantity_commited', '>=', 0)
            ->where('store_orders.order_status', '!=', 'pending');

        if ($supplierId !== 'all') {
             $query->where('store_orders.supplier_id', $supplierId);
        }

        if ($scheduledBranches && $scheduledBranches->isNotEmpty()) {
             $query->whereIn('store_orders.store_branch_id', $scheduledBranches->pluck('id'));
        }

        if ($categoryFilter !== 'all') {
            $query->where('supplier_items.category', $categoryFilter);
        }

        // Group By Item Key
        $query->groupBy('store_order_items.item_code', 'store_order_items.uom');

        // Apply Sorting
        // Logic: Items with null or 0 sort order go to the end.
        $query->orderByRaw('CASE WHEN ISNULL(MAX(supplier_items.sort_order), 0) = 0 THEN 1 ELSE 0 END, MAX(supplier_items.sort_order) ASC');

        // Execute Query
        $storeOrderItems = $query->get();

        // 3. Map Results (Calculate Remarks and Format)
        $reportItems = $storeOrderItems->map(function ($item) use ($brandCodes) {
            // Convert to array
            $row = $item->toArray();

            // Resolve Item Name
            $itemName = $item->sap_item_description ?: ($item->supplier_item_name ?? 'N/A');
            $row['item_name'] = $itemName;

            // Resolve Category
            if (empty($row['category'])) {
                $row['category'] = 'N/A';
            }

            // Resolve Sort Order
            if (is_null($row['sort_order'])) {
                $row['sort_order'] = 0;
            }

            // Resolve WHSE
            $row['whse'] = $this->getWhseCode($item->supplier_code ?? null);

            // Calculate Remarks
            $allBranchesMetApproved = true;
            foreach ($brandCodes as $code) {
                $committed = (float) ($row[$code] ?? 0);
                $approved = (float) ($row['approved_' . $code] ?? 0);
                
                // If any branch has committed less than approved, condition is false
                if ($committed < $approved) {
                    $allBranchesMetApproved = false;
                }
            }

            $totalQty = (float) ($row['total_quantity'] ?? 0);

            if ($totalQty == 0) {
                $row['remarks'] = '86';
            } elseif ($allBranchesMetApproved) {
                $row['remarks'] = 'Stock Supported';
            } else {
                $row['remarks'] = 'Allocation';
            }

            return $row;
        });

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
            ['label' => 'Remarks', 'field' => 'remarks'],
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
            'supplier_id' => 'required|string|exists:suppliers,supplier_code',
        ]);

        return $this->performUpdate($validated);
    }

    public function bulkUpdateCommit(Request $request)
    {
        $validated = $request->validate([
            'updates' => 'required|array',
            'updates.*.order_date' => 'required|date',
            'updates.*.item_code' => 'required|string|exists:supplier_items,ItemCode',
            'updates.*.brand_code' => 'required|string|exists:store_branches,brand_code',
            'updates.*.new_quantity' => 'required|numeric|min:0',
            'updates.*.supplier_id' => 'required|string|exists:suppliers,supplier_code',
        ]);

        $results = [];
        $errors = [];

        foreach ($validated['updates'] as $index => $data) {
            $response = $this->performUpdate($data);
            if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 302) { // Redirect is success in performUpdate
                $errors[] = "Row $index: " . json_encode($response->getData());
            }
        }

        if (count($errors) > 0) {
             return response()->json(['message' => 'Some updates failed', 'errors' => $errors], 422);
        }

        return response()->json(['message' => 'Bulk update successful']);
    }

    private function performUpdate(array $data)
    {
        // Find the item's category for permission checking
        // Use both ItemCode and SupplierCode (supplier_id passed from frontend is actually supplier_code)
        $supplierItem = \App\Models\SupplierItems::where('ItemCode', $data['item_code'])
            ->where('SupplierCode', $data['supplier_id'])
            ->first();

        // Fallback if not found by exact match (though it should be)
        if (!$supplierItem) {
             $supplierItem = \App\Models\SupplierItems::where('ItemCode', $data['item_code'])->firstOrFail();
        }

        $category = $supplierItem->category;

        // Perform permission check
        $user = Auth::user();
        $isFinishedGood = in_array(strtoupper($category), ['FINISHED GOODS', 'FG', 'FINISHED GOOD']);

        if ($isFinishedGood && !$user->can('edit finished good commits')) {
            return response()->json(['message' => 'You do not have permission to edit items in the FINISHED GOOD category.'], 403);
        }

        if (!$isFinishedGood && !$user->can('edit other commits')) {
            return response()->json(['message' => 'You do not have permission to edit items in this category.'], 403);
        }

        $orderItem = \App\Models\StoreOrderItem::where('item_code', $data['item_code'])
            ->whereHas('store_order', function ($query) use ($data) {
                $query->whereDate('order_date', $data['order_date'])
                      ->whereHas('store_branch', function ($subQuery) use ($data) {
                          $subQuery->where('brand_code', $data['brand_code']);
                      })
                      ->whereHas('supplier', function ($subQuery) use ($data) {
                          $subQuery->where('supplier_code', $data['supplier_id']);
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

        $orderItem->update(['quantity_commited' => $data['new_quantity']]);

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
        $revokedItemsCount = 0;

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

                // Optimize: Get categories map for this order's supplier to ensure accurate permission checks
                $supplierCode = $order->supplier->supplier_code ?? null;
                $supplierCategories = [];
                $fallbackCategories = []; // Fallback map: ItemCode -> Category

                if ($supplierCode) {
                    $supplierItems = \App\Models\SupplierItems::where('SupplierCode', $supplierCode)
                        ->whereIn('ItemCode', $order->store_order_items->pluck('item_code'))
                        ->select('ItemCode', 'uom', 'category')
                        ->get();

                    foreach ($supplierItems as $si) {
                        $code = strtoupper(trim($si->ItemCode));
                        $uom = strtoupper(trim($si->uom));
                        
                        $key = $code . '_' . $uom;
                        $supplierCategories[$key] = $si->category;
                        
                        // Populate fallback: if multiple UOMs exist, this takes the last one, 
                        // but usually category is consistent across UOMs for the same item.
                        $fallbackCategories[$code] = $si->category;
                    }
                }

                foreach ($order->store_order_items as $item) {
                    $itemCount++;
                    
                    $cleanCode = strtoupper(trim($item->item_code));
                    $cleanUom = strtoupper(trim($item->uom));

                    // Resolve Category: 
                    // 1. Strict Supplier Match (Item + UOM)
                    $lookupKey = $cleanCode . '_' . $cleanUom;
                    $itemCategory = $supplierCategories[$lookupKey] ?? null;
                    
                    // 2. Fallback Supplier Match (Item only) - handles UOM mismatches
                    if (!$itemCategory) {
                        $itemCategory = $fallbackCategories[$cleanCode] ?? null;
                    }

                    // 3. SAP Fallback
                    if (!$itemCategory) {
                        $itemCategory = $item->sapMasterfile->Category ?? 'Unknown';
                    }
                    
                    // Ensure category is clean for comparison
                    $itemCategory = trim($itemCategory);

                    $canCommit = $item->canBeCommittedBy($user, $itemCategory);

                    // Check if user can commit this specific item based on permissions
                    if (!$canCommit) {
                        // If the user previously committed this item but shouldn't have (permission changed or bug fixed),
                        // we must revoke their commit signature to ensure data integrity.
                        // Use loose comparison or cast because committed_by might be a string from DB.
                        if ($item->committed_by == $user->id) {
                            $item->removeCommitStatus();
                            $revokedItemsCount++;
                            $orderHasUpdates = true;
                            \Log::info('CS Mass Commits - Item commit revoked', [
                                'item_code' => $item->item_code,
                                'reason' => 'User no longer has permission'
                            ]);
                        }
                        
                        $skippedItemsCount++;
                        continue;
                    }

                    // Mark item as committed by this user (audit tracking)
                    // Check if already committed by this user to avoid unnecessary writes
                    if ($item->committed_by !== $user->id) {
                         $item->markAsCommittedBy($user->id);
                         $updatedItemsCount++;
                         $orderHasUpdates = true;
                    }

                    // Create placeholder receive date record if it doesn't exist
                    if ($item->quantity_commited > 0 && $item->ordered_item_receive_dates()->doesntExist()) {
                        $item->ordered_item_receive_dates()->create([
                            'quantity_received' => $item->quantity_commited,
                            'status' => 'pending',
                            'received_by_user_id' => $user->id,
                        ]);
                    }
                }

                \Log::info('CS Mass Commits - Order processing complete', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'total_items' => $itemCount,
                    'updated_items' => $updatedItemsCount,
                    'revoked_items' => $revokedItemsCount,
                    'has_updates' => $orderHasUpdates
                ]);

                // Update order status based on commit status of its items
                // Always run this if we iterated, just to be safe, but definitely if updates occurred.
                if ($orderHasUpdates || $itemCount > 0) {
                    $oldStatus = $order->order_status;
                    $order->updateOrderStatusBasedOnCommits();

                    \Log::info('CS Mass Commits - Order status updated', [
                        'order_id' => $order->id,
                        'old_status' => $oldStatus,
                        'new_status' => $order->order_status
                    ]);

                    // Set order-level commit info if this is the first time items are being committed
                    if ($order->order_status === \App\Enum\OrderStatus::COMMITTED->value) {
                         if (!$order->commiter_id) {
                             $order->update([
                                 'commiter_id' => $user->id,
                                 'commited_action_date' => Carbon::now(),
                             ]);
                         }
                    } elseif ($order->order_status === \App\Enum\OrderStatus::PARTIAL_COMMITTED->value) {
                        // If it degraded to partial, we might want to ensure commiter_id is set (first committer) or keep it.
                        // Usually we just leave it.
                    }

                    if ($orderHasUpdates) {
                        $updatedOrdersCount++;
                    }
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
        if ($revokedItemsCount > 0) {
             $message[] = $revokedItemsCount . ' item(s) corrected (uncommitted)';
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
