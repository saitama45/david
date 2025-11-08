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
        $user = Auth::user();
        $orderDate = $request->input('order_date', Carbon::today()->format('Y-m-d'));
        $supplierCode = $request->input('supplier_id', 'all');
        $categoryFilter = $request->input('category', 'all'); // Add category filter

        $userSuppliers = $user->suppliers()->get();
        $suppliers = $userSuppliers->map(function ($supplier) {
            return [
                'label' => $supplier->name . ' (' . $supplier->supplier_code . ')',
                'value' => $supplier->supplier_code,
            ];
        });

        $supplierId = ($supplierCode === 'all') ? 'all' : Supplier::where('supplier_code', $supplierCode)->first()?->id;

        $dayName = Carbon::parse($orderDate)->format('l');
        $user->load('store_branches.delivery_schedules');

        $branchesForReport = $user->store_branches->filter(function ($branch) use ($dayName, $supplierCode, $userSuppliers) {
            $schedulesOnDay = $branch->delivery_schedules->where('day', strtoupper($dayName));
            if ($schedulesOnDay->isEmpty()) {
                return false;
            }

            if ($supplierCode !== 'all') {
                return $schedulesOnDay->contains(function ($schedule) use ($supplierCode) {
                    return $schedule->pivot->variant === $supplierCode;
                });
            } else {
                $userSupplierCodes = $userSuppliers->pluck('supplier_code');
                return $schedulesOnDay->contains(function ($schedule) use ($userSupplierCodes) {
                    return $userSupplierCodes->contains($schedule->pivot->variant);
                });
            }
        });

        $branches = $branchesForReport->pluck('name', 'id');
        $branchIdsForReport = $branchesForReport->pluck('id');

        // --- NEW: Fetch order statuses for each branch ---
        $branchStatuses = [];
        if ($branchIdsForReport->isNotEmpty()) {
            $orderStatusQuery = StoreOrder::query()
                ->whereDate('order_date', $orderDate)
                ->whereIn('store_branch_id', $branchIdsForReport);

            if ($supplierCode !== 'all') {
                $supplier = Supplier::where('supplier_code', $supplierCode)->first();
                if ($supplier) {
                    $orderStatusQuery->where('supplier_id', $supplier->id);
                }
            } else {
                $userSupplierIds = $userSuppliers->pluck('id');
                $orderStatusQuery->whereIn('supplier_id', $userSupplierIds);
            }

            $orders = $orderStatusQuery->with('store_branch')->get();

            foreach ($orders as $order) {
                $branchStatuses[$order->store_branch->brand_code] = $order->order_status;
            }
        }
        // --- END NEW ---

        // Filter the scheduled branches to only include those with orders in an allowed status
        $allowedStatuses = ['approved', 'committed', 'partial_committed', 'received', 'incomplete'];
        $finalBranchesForDisplay = $branchesForReport->filter(function ($branch) use ($branchStatuses, $allowedStatuses) {
            $status = $branchStatuses[$branch->brand_code] ?? null;
            return in_array(strtolower($status), $allowedStatuses, true);
        });

        $reportData = $this->getCSMassCommitsData(
            $orderDate,
            $supplierId,
            $finalBranchesForDisplay,
            $categoryFilter // Pass category filter
        );

        $availableCategories = $reportData['report']->pluck('category')->unique()->values()->all();

        // Debug: Log if specific item exists
        if ($supplierCode === 'GSI-B' && $orderDate === '2025-10-14') {
            $has632 = $reportData['report']->contains(function($item) {
                return $item['item_code'] === '632A2G';
            });
            \Log::info('CS Mass Commits - Item 632A2G present in report: ' . ($has632 ? 'YES' : 'NO'));
            \Log::info('CS Mass Commits - Total items in report: ' . $reportData['report']->count());
            \Log::info('CS Mass Commits - Category filter: ' . $categoryFilter);
        }

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
            'branchStatuses' => $branchStatuses, // NEW PROP
            'permissions' => [
                'canEditFinishedGood' => $user->can('edit finished good commits'),
                'canEditOther' => $user->can('edit other commits'),
            ],
            'availableCategories' => $availableCategories,
        ]);
    }

    public function export(Request $request)
    {
        $user = Auth::user();
        $orderDate = $request->input('order_date', Carbon::today()->format('Y-m-d'));
        $supplierCode = $request->input('supplier_id', 'all');

        $supplierId = ($supplierCode === 'all') ? 'all' : Supplier::where('supplier_code', $supplierCode)->first()?->id;

        $userSuppliers = $user->suppliers()->get();
        $dayName = Carbon::parse($orderDate)->format('l');
        $user->load('store_branches.delivery_schedules');

        $branchesForReport = $user->store_branches->filter(function ($branch) use ($dayName, $supplierCode, $userSuppliers) {
            $schedulesOnDay = $branch->delivery_schedules->where('day', strtoupper($dayName));
            if ($schedulesOnDay->isEmpty()) {
                return false;
            }

            if ($supplierCode !== 'all') {
                return $schedulesOnDay->contains(function ($schedule) use ($supplierCode) {
                    return $schedule->pivot->variant === $supplierCode;
                });
            } else {
                $userSupplierCodes = $userSuppliers->pluck('supplier_code');
                return $schedulesOnDay->contains(function ($schedule) use ($userSupplierCodes) {
                    return $userSupplierCodes->contains($schedule->pivot->variant);
                });
            }
        });

        // --- START: Apply same status filtering as index method ---
        $branchIdsForReport = $branchesForReport->pluck('id');
        $branchStatuses = [];
        if ($branchIdsForReport->isNotEmpty()) {
            $orderStatusQuery = StoreOrder::query()
                ->whereDate('order_date', $orderDate)
                ->whereIn('store_branch_id', $branchIdsForReport);

            if ($supplierCode !== 'all') {
                $supplier = Supplier::where('supplier_code', $supplierCode)->first();
                if ($supplier) {
                    $orderStatusQuery->where('supplier_id', $supplier->id);
                }
            } else {
                $userSupplierIds = $userSuppliers->pluck('id');
                $orderStatusQuery->whereIn('supplier_id', $userSupplierIds);
            }

            $orders = $orderStatusQuery->with('store_branch')->get();

            foreach ($orders as $order) {
                $branchStatuses[$order->store_branch->brand_code] = $order->order_status;
            }
        }

        $allowedStatuses = ['approved', 'committed', 'partial_committed', 'received', 'incomplete'];
        $finalBranchesForDisplay = $branchesForReport->filter(function ($branch) use ($branchStatuses, $allowedStatuses) {
            $status = $branchStatuses[$branch->brand_code] ?? null;
            return in_array(strtolower($status), $allowedStatuses, true);
        });
        // --- END: Apply same status filtering as index method ---

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
        // 1. Use the scheduled branches passed to the function to build the headers.
        // This ensures all scheduled branches appear as columns, even if they have no committed orders yet.
        $allBranches = $scheduledBranches ? $scheduledBranches->unique('id')->sortBy('brand_code') : collect();
        $brandCodes = $allBranches->pluck('brand_code')->toArray();
        $totalBranches = count($brandCodes);

        // 2. Build the query for actual StoreOrders to populate the data
        $query = StoreOrder::query()
            ->with(['storeOrderItems.supplierItem.sapMasterfiles', 'store_branch'])
            ->whereDate('order_date', $orderDate)
            ->whereHas('storeOrderItems', function ($q) {
                $q->where('quantity_commited', '>', 0);
            });

        if ($supplierId !== 'all') {
            $query->where('supplier_id', $supplierId);
        }

        if ($scheduledBranches && $scheduledBranches->isNotEmpty()) {
            $query->whereIn('store_branch_id', $scheduledBranches->pluck('id'));
        }

        $storeOrders = $query->get(); // Get the actual orders with data

        // 3. Process the orders into the report structure
        $reportItems = $storeOrders->flatMap(function ($order) use ($categoryFilter) {
            return $order->storeOrderItems
                ->filter(function ($orderItem) {
                    return $orderItem->supplierItem !== null;
                })
                ->filter(function ($orderItem) use ($categoryFilter) {
                    if ($categoryFilter === 'all') {
                        return true;
                    }
                    return $orderItem->supplierItem->category === $categoryFilter;
                })
                ->map(function ($orderItem) use ($order) {
                    $supplierItem = $orderItem->supplierItem;
                    $sapMasterfile = $supplierItem ? $supplierItem->sap_master_file : null;

                    return [
                        'category' => $supplierItem ? $supplierItem->category : 'N/A',
                        'item_code' => $orderItem->item_code,
                        'item_name' => $sapMasterfile ? $sapMasterfile->ItemDescription : ($supplierItem ? $supplierItem->item_name : 'N/A'),
                        'unit' => $orderItem->uom,
                        'brand_code' => $order->store_branch->brand_code,
                        'quantity_commited' => (float) $orderItem->quantity_commited,
                        'supplier_id' => $order->supplier_id,
                    ];
                });
        })
        ->groupBy(function ($item) {
            return $item['category'] . '|' . $item['item_code'] . '|' . $item['item_name'] . '|' . $item['unit'];
        })
        ->map(function ($groupedItems) use ($brandCodes) { // Removed $allBranches from use() as it's not needed here
            $firstItem = $groupedItems->first();
            $row = [
                'category' => $firstItem['category'],
                'item_code' => $firstItem['item_code'],
                'item_name' => $firstItem['item_name'],
                'unit' => $firstItem['unit'],
            ];

            // Initialize all possible branch columns to 0.0
            foreach ($brandCodes as $code) {
                $row[$code] = 0.0;
            }

            // Fill in the quantities for branches that have them
            foreach ($groupedItems as $item) {
                if (isset($row[$item['brand_code']])) { // Ensure the brand_code exists as a column
                    $row[$item['brand_code']] += $item['quantity_commited'];
                }
            }

            $row['total_quantity'] = array_sum(array_intersect_key($row, array_flip($brandCodes)));

            $supplierCode = Supplier::find($firstItem['supplier_id'])?->supplier_code;
            $row['whse'] = $this->getWhseCode($supplierCode);

            return $row;
        })
        ->values();

        // 4. Build headers from the definitive $allBranches list
        $staticHeaders = [
            ['label' => 'CATEGORY', 'field' => 'category'],
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