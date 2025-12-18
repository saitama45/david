<?php

namespace App\Http\Controllers;

use App\Models\DTSDeliverySchedule;
use App\Models\OrdersCutoff;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Exports\DTSMassOrderExport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class DTSMassOrdersController extends Controller
{
    /**
     * Get base query for DTS mass orders with proper filtering
     * Ensures consistent filtering across all queries
     */
    private function getBaseBatchQuery()
    {
        return StoreOrder::whereNotNull('batch_reference')
            ->where('variant', 'mass dts')
            ->whereHas('store_order_items'); // Only include batches with valid order items
    }

    public function index(Request $request)
    {
        $allowedVariants = ['ICE CREAM', 'FRUITS AND VEGETABLES'];

        $variants = DTSDeliverySchedule::whereIn('variant', $allowedVariants)
            ->distinct()
            ->pluck('variant')
            ->map(function ($variant) {
                return [
                    'label' => $variant,
                    'value' => $variant
                ];
            })
            ->values();

        $filterQuery = $request->input('filterQuery', 'committed');
        $search = $request->input('search');

        // Build query for mass order batches
        $query = $this->getBaseBatchQuery()
            ->leftJoin('delivery_receipts', 'store_orders.id', '=', 'delivery_receipts.store_order_id')
            ->select([
                'batch_reference as batch_number',
                'encoder_id',
                \DB::raw('MIN(order_date) as date_from'),
                \DB::raw('MAX(order_date) as date_to'),
                \DB::raw('COUNT(DISTINCT store_orders.id) as total_orders'),
                \DB::raw("CASE 
                    WHEN COUNT(DISTINCT store_orders.order_status) = 1 THEN MIN(store_orders.order_status)
                    WHEN SUM(CASE WHEN store_orders.order_status = 'received' THEN 1 ELSE 0 END) > 0 
                         AND SUM(CASE WHEN store_orders.order_status != 'received' THEN 1 ELSE 0 END) > 0 
                         THEN 'partial_received'
                    WHEN SUM(CASE WHEN store_orders.order_status = 'incomplete' THEN 1 ELSE 0 END) > 0 
                         THEN 'incomplete'
                    ELSE MIN(store_orders.order_status)
                END as status"),
                \DB::raw('MIN(store_orders.remarks) as remarks'),
                \DB::raw('MIN(store_orders.created_at) as created_at'),
                \DB::raw('MAX(store_orders.updated_at) as updated_at'),
                \DB::raw('MIN(delivery_receipts.sap_so_number) as sap_so_number_for_batch') // Taking MIN as a representative
            ])
            ->with('encoder')
            ->groupBy('batch_reference', 'encoder_id');

        if ($request->filled('search')) {
            $searchTerm = $request->input('search');

            // Find batch_references that have a matching sap_so_number
            $matchingBatches = \DB::table('store_orders')
                ->join('delivery_receipts', 'store_orders.id', '=', 'delivery_receipts.store_order_id')
                ->where('delivery_receipts.sap_so_number', 'like', '%' . $searchTerm . '%')
                ->whereNotNull('store_orders.batch_reference')
                ->distinct()
                ->pluck('store_orders.batch_reference');

            $query->where(function($q) use ($searchTerm, $matchingBatches) {
                $q->where('batch_reference', 'like', '%' . $searchTerm . '%')
                  ->orWhereIn('batch_reference', $matchingBatches);
            });
        }

        if ($filterQuery !== 'all') {
            switch ($filterQuery) {
                case 'approved':
                    $query->whereNotExists(function ($subquery) {
                        $subquery->select(\DB::raw(1))
                            ->from('store_orders as so')
                            ->whereRaw('so.batch_reference = store_orders.batch_reference')
                            ->where('so.order_status', '!=', 'approved');
                    });
                    break;
                case 'committed':
                    $query->whereNotExists(function ($subquery) {
                        $subquery->select(\DB::raw(1))
                            ->from('store_orders as so')
                            ->whereRaw('so.batch_reference = store_orders.batch_reference')
                            ->where('so.order_status', '!=', 'committed');
                    });
                    break;
                case 'incomplete':
                    $query->whereExists(function ($subquery) {
                        $subquery->select(\DB::raw(1))
                            ->from('store_orders as so')
                            ->whereRaw('so.batch_reference = store_orders.batch_reference')
                            ->where('so.order_status', 'incomplete');
                    });
                    break;
                case 'received':
                    $query->whereNotExists(function ($subquery) {
                        $subquery->select(\DB::raw(1))
                            ->from('store_orders as so')
                            ->whereRaw('so.batch_reference = store_orders.batch_reference')
                            ->where('so.order_status', '!=', 'received');
                    });
                    break;
            }
        }

        $batches = $query->orderBy('created_at', 'desc')->paginate(20);

        // Calculate counts for each status with proper batch-level filtering to ensure data integrity
        $baseBatchQuery = $this->getBaseBatchQuery()->select('batch_reference')->distinct();

        $counts = [
            'all' => $baseBatchQuery->count('batch_reference'),
            'approved' => (clone $baseBatchQuery)->whereNotExists(function ($subquery) {
                $subquery->select(\DB::raw(1))
                    ->from('store_orders as so')
                    ->whereRaw('so.batch_reference = store_orders.batch_reference')
                    ->where('so.order_status', '!=', 'approved');
            })->count('batch_reference'),
            'committed' => (clone $baseBatchQuery)->whereNotExists(function ($subquery) {
                $subquery->select(\DB::raw(1))
                    ->from('store_orders as so')
                    ->whereRaw('so.batch_reference = store_orders.batch_reference')
                    ->where('so.order_status', '!=', 'committed');
            })->count('batch_reference'),
            'incomplete' => (clone $baseBatchQuery)->whereExists(function ($subquery) {
                $subquery->select(\DB::raw(1))
                    ->from('store_orders as so')
                    ->whereRaw('so.batch_reference = store_orders.batch_reference')
                    ->where('so.order_status', 'incomplete');
            })->count('batch_reference'),
            'received' => (clone $baseBatchQuery)->whereNotExists(function ($subquery) {
                $subquery->select(\DB::raw(1))
                    ->from('store_orders as so')
                    ->whereRaw('so.batch_reference = store_orders.batch_reference')
                    ->where('so.order_status', '!=', 'received');
            })->count('batch_reference'),
        ];

        // Calculate total quantity for each batch, extract variant, and fix status display
        $batches->getCollection()->transform(function ($batch) {
            // Get total quantity
            $totalQuantity = \DB::table('store_order_items')
                ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
                ->where('store_orders.batch_reference', $batch->batch_number)
                ->sum('store_order_items.quantity_ordered');

            $batch->total_quantity = $totalQuantity;

            // Extract variant from remarks (format: "Mass DTS Order - ICE CREAM")
            $batch->variant = 'N/A';
            if ($batch->remarks && strpos($batch->remarks, 'Mass DTS Order - ') !== false) {
                $batch->variant = str_replace('Mass DTS Order - ', '', $batch->remarks);
            }

            // Check if batch can be edited based on cutoff
            $batch->can_edit = $this->canEditBatch($batch);
            
            // Ensure status is properly formatted for display
            $batch->status = strtoupper($batch->status);

            return $batch;
        });

        return Inertia::render('DTSMassOrders/Index', [
            'variants' => $variants,
            'batches' => $batches,
            'filters' => [
                'filterQuery' => $filterQuery,
                'search' => $search,
            ],
            'counts' => $counts
        ]);
    }

    public function create(Request $request)
    {
        $variant = $request->input('variant');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        // Determine ItemCode based on variant
        $itemCodeMap = [
            'ICE CREAM' => '359A2A',
            'SALMON' => '269A2A',
            // Add more variants as needed
        ];

        $itemCode = $itemCodeMap[$variant] ?? null;
        $sapItem = null;

        if ($itemCode) {
            // For ICE CREAM, get the GAL(3.8) version
            if ($variant === 'ICE CREAM') {
                $sapItem = SAPMasterfile::where('ItemCode', $itemCode)
                    ->where('is_active', 1)
                    ->where('AltUOM', 'LIKE', '%GAL%')
                    ->first();
            } else {
                // For other variants, get the first active record
                $sapItem = SAPMasterfile::where('ItemCode', $itemCode)
                    ->where('is_active', 1)
                    ->first();
            }
        }

        // Generate date range
        $dates = [];
        $dayOfWeekMap = [
            'MONDAY' => 1,
            'TUESDAY' => 2,
            'WEDNESDAY' => 3,
            'THURSDAY' => 4,
            'FRIDAY' => 5,
            'SATURDAY' => 6,
            'SUNDAY' => 7
        ];

        if ($dateFrom && $dateTo) {
            $start = Carbon::parse($dateFrom);
            $end = Carbon::parse($dateTo);

            while ($start->lte($end)) {
                $dayName = strtoupper($start->format('l'));
                $dates[] = [
                    'date' => $start->format('Y-m-d'),
                    'display' => $dayName . '- ' . strtoupper($start->format('M')) . ' ' . $start->format('j'),
                    'day_of_week' => $dayName,
                    'delivery_schedule_id' => $dayOfWeekMap[$dayName] ?? null
                ];
                $start->addDay();
            }
        }

        // Fetch user assigned stores using the relationship approach
        $stores = [];

        // Only fetch stores if user is authenticated
        if (auth()->check()) {
            $user = auth()->user();
            $stores = $user->store_branches()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(function ($store) use ($variant) {
                    // Get delivery schedule IDs for this store and variant
                    $deliveryScheduleIds = \DB::table('d_t_s_delivery_schedules')
                        ->where('store_branch_id', $store->id)
                        ->where('variant', $variant)
                        ->pluck('delivery_schedule_id')
                        ->map(function ($id) {
                            return (int) $id; // Convert to integer
                        })
                        ->toArray();

                    return [
                        'id' => $store->id,
                        'name' => $store->name,
                        'branch_code' => $store->branch_code,
                        'brand_code' => $store->brand_code,
                        'complete_address' => $store->complete_address,
                        'label' => $store->name,
                        'delivery_schedule_ids' => $deliveryScheduleIds
                    ];
                })
                ->values()
                ->toArray();
        }

        // For FRUITS AND VEGETABLES, fetch all supplier items for DROPS supplier
        // Exclude ICE CREAM (359A2A) and SALMON (269A2A)
        $supplierItems = [];
        if ($variant === 'FRUITS AND VEGETABLES') {
            $supplierItems = \App\Models\SupplierItems::where('SupplierCode', 'DROPS')
                ->where('is_active', 1)
                ->whereNotIn('ItemCode', ['359A2A', '269A2A'])
                ->select('id', 'ItemCode', 'item_name', 'uom', 'cost')
                ->orderBy('item_name')
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'item_code' => $item->ItemCode,
                        'item_name' => $item->item_name,
                        'uom' => $item->uom,
                        'price' => $item->cost
                    ];
                })
                ->toArray();
        }

        return Inertia::render('DTSMassOrders/Create', [
            'variant' => $variant,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'stores' => $stores,
            'dates' => $dates,
            'sap_item' => $sapItem ? [
                'item_code' => $sapItem->ItemCode,
                'item_description' => $sapItem->ItemDescription,
                'alt_uom' => $sapItem->AltUOM,
                'base_uom' => $sapItem->BaseUOM
            ] : null,
            'supplier_items' => $supplierItems
        ]);
    }

    public function store(Request $request)
    {
        $variant = $request->input('variant');

        // Different validation and processing for FRUITS AND VEGETABLES
        if ($variant === 'FRUITS AND VEGETABLES') {
            $request->validate([
                'variant' => 'required|string',
                'orders' => 'required|array',
                'supplier_items' => 'required|array',
            ]);

            $orders = $request->input('orders'); // { itemId: { date: { storeId: quantity } } }
            $supplierItems = $request->input('supplier_items');

            try {
                \DB::beginTransaction();

                $batchNumber = $this->generateBatchNumber();
                $lastOrderNumbers = []; // Initialize map to track last order numbers for each store branch

                // Pivot the orders data to be date/store centric
                $pivotedOrders = [];
                foreach ($orders as $itemId => $dateOrders) {
                    foreach ($dateOrders as $date => $storeOrders) {
                        foreach ($storeOrders as $storeBranchId => $quantity) {
                            if (empty($quantity) || $quantity <= 0) continue;
                            $pivotedOrders[$date][$storeBranchId][$itemId] = $quantity;
                        }
                    }
                }

                // Now iterate through the pivoted structure
                foreach ($pivotedOrders as $date => $storeOrders) {
                    foreach ($storeOrders as $storeBranchId => $itemOrders) {
                        // 1. Create ONE StoreOrder for this date and store
                        $orderNumber = $this->generateOrderNumber($storeBranchId, $lastOrderNumbers);

                        $storeOrder = StoreOrder::create([
                            'encoder_id' => auth()->id(),
                            'supplier_id' => 5, // DROPSHIPPING supplier
                            'store_branch_id' => $storeBranchId,
                            'order_number' => $orderNumber,
                            'order_date' => $date,
                            'order_status' => 'committed',
                            'variant' => 'mass dts',
                            'batch_reference' => $batchNumber,
                            'remarks' => "Mass DTS Order - {$variant}",
                        ]);

                        // 2. Create multiple StoreOrderItems for this StoreOrder
                        foreach ($itemOrders as $itemId => $quantity) {
                            $supplierItemDetails = collect($supplierItems)->firstWhere('id', (int)$itemId);
                            if (!$supplierItemDetails) continue;

                            $storeOrderItem = \App\Models\StoreOrderItem::create([
                                'store_order_id' => $storeOrder->id,
                                'item_code' => $supplierItemDetails['item_code'],
                                'quantity_ordered' => $quantity,
                                'quantity_approved' => $quantity,
                                'quantity_commited' => $quantity,
                                'quantity_received' => 0,
                                'cost_per_quantity' => $supplierItemDetails['price'],
                                'total_cost' => $supplierItemDetails['price'] * $quantity,
                                'uom' => $supplierItemDetails['uom'],
                                'remarks' => null,
                            ]);

                            if ($storeOrderItem->quantity_commited > 0 && $storeOrderItem->ordered_item_receive_dates()->doesntExist()) {
                                $storeOrderItem->ordered_item_receive_dates()->create([
                                    'quantity_received' => $storeOrderItem->quantity_commited,
                                    'status' => 'pending',
                                    'received_by_user_id' => auth()->id(),
                                ]);
                            }
                        }
                    }
                }

                \DB::commit();

                return redirect()->route('dts-mass-orders.index')->with('success', "Mass orders placed successfully! Batch: {$batchNumber}");
            } catch (\Exception $e) {
                \DB::rollBack();
                \Log::error('Error creating FRUITS AND VEGETABLES mass DTS orders', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]);
                return back()->withErrors(['error' => 'Failed to place orders: ' . $e->getMessage()]);
            }
        } else {
            // Original logic for ICE CREAM and SALMON
            $request->validate([
                'variant' => 'required|string',
                'orders' => 'required|array',
                'orders.*' => 'array',
                'sap_item' => 'required|array',
            ]);

            $orders = $request->input('orders');
            $sapItem = $request->input('sap_item');

            try {
                \DB::beginTransaction();

                $batchNumber = $this->generateBatchNumber();
                $lastOrderNumbers = []; // Initialize map to track last order numbers for each store branch

                foreach ($orders as $date => $stores) {
                    foreach ($stores as $storeBranchId => $quantity) {
                        if (empty($quantity) || $quantity <= 0) {
                            continue;
                        }

                        $orderNumber = $this->generateOrderNumber($storeBranchId, $lastOrderNumbers);

                        $supplierItem = \App\Models\SupplierItems::where('ItemCode', $sapItem['item_code'])
                            ->where('is_active', true)
                            ->first();

                        $costPerQuantity = $supplierItem ? $supplierItem->cost : 0;
                        $totalCost = $costPerQuantity * $quantity;

                        $storeOrder = StoreOrder::create([
                            'encoder_id' => auth()->id(),
                            'supplier_id' => 5, // DROPSHIPPING supplier
                            'store_branch_id' => $storeBranchId,
                            'order_number' => $orderNumber,
                            'order_date' => $date,
                            'order_status' => 'committed',
                            'variant' => 'mass dts',
                            'batch_reference' => $batchNumber,
                            'remarks' => "Mass DTS Order - {$variant}",
                        ]);

                        $storeOrderItem = \App\Models\StoreOrderItem::create([
                            'store_order_id' => $storeOrder->id,
                            'item_code' => $sapItem['item_code'],
                            'quantity_ordered' => $quantity,
                            'quantity_approved' => $quantity,
                            'quantity_commited' => $quantity,
                            'quantity_received' => 0,
                            'cost_per_quantity' => $costPerQuantity,
                            'total_cost' => $totalCost,
                            'uom' => $sapItem['alt_uom'],
                            'remarks' => null,
                        ]);

                        if ($storeOrderItem->quantity_commited > 0 && $storeOrderItem->ordered_item_receive_dates()->doesntExist()) {
                            $storeOrderItem->ordered_item_receive_dates()->create([
                                'quantity_received' => $storeOrderItem->quantity_commited,
                                'status' => 'pending',
                                'received_by_user_id' => auth()->id(),
                            ]);
                        }
                    }
                }

                \DB::commit();

                return redirect()->route('dts-mass-orders.index')->with('success', "Mass orders placed successfully! Batch: {$batchNumber}");
            } catch (\Exception $e) {
                \DB::rollBack();
                \Log::error('Error creating mass DTS orders: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                return back()->withErrors(['error' => 'Failed to place orders: ' . $e->getMessage()]);
            }
        }
    }

    private function generateBatchNumber()
    {
        $today = Carbon::now()->format('Ymd');
        $prefix = "MDTS-{$today}-";

        // Get the last batch number for today from store_orders
        $lastBatch = StoreOrder::where('batch_reference', 'LIKE', "{$prefix}%")
            ->orderBy('id', 'desc')
            ->first();

        if ($lastBatch) {
            // Extract the sequence number
            preg_match('/(\d+)$/', $lastBatch->batch_reference, $matches);
            $lastSequence = isset($matches[1]) ? (int)$matches[1] : 0;
            $newSequence = $lastSequence + 1;
        } else {
            $newSequence = 1;
        }

        // Format: MDTS-YYYYMMDD-XXX (3 digits)
        return $prefix . str_pad($newSequence, 3, '0', STR_PAD_LEFT);
    }

    private function generateOrderNumber($storeBranchId, &$lastOrderNumberMap)
    {
        $branch = StoreBranch::find($storeBranchId);
        if (!$branch) {
            throw new \Exception("Store branch not found");
        }

        // If we haven't determined the starting number for this branch yet, do it now.
        if (!isset($lastOrderNumberMap[$storeBranchId])) {
            $lastOrder = StoreOrder::where('store_branch_id', $storeBranchId)
                ->where('variant', '<>', 'INTERCO')
                ->orderBy('order_number', 'desc')
                ->first();

            if ($lastOrder && $lastOrder->order_number) {
                // Set the last known order number in the map. The next step will increment it.
                $lastOrderNumberMap[$storeBranchId] = $lastOrder->order_number;
            } else {
                // No previous orders, so we'll start the sequence from 0, so the first number is 1.
                $lastOrderNumberMap[$storeBranchId] = $branch->branch_code . '-00000';
            }
        }

        $lastOrderNumberString = $lastOrderNumberMap[$storeBranchId];

        // Default prefix and number in case the regex doesn't match
        $prefix = $branch->branch_code . '-';
        $lastNumber = 0;

        // Match the prefix (everything up to the last hyphen) and the number
        if (preg_match('/^(.*-)(\d+)$/', $lastOrderNumberString, $matches)) {
            $prefix = $matches[1];
            $lastNumber = (int)$matches[2];
        }

        $newNumber = $lastNumber + 1;
        $newOrderNumber = $prefix . str_pad($newNumber, 5, '0', STR_PAD_LEFT);

        // Update the map for the next call.
        $lastOrderNumberMap[$storeBranchId] = $newOrderNumber;

        return $newOrderNumber;
    }

    public function show($batchNumber)
    {
        // Get all orders for this batch
        $orders = StoreOrder::where('batch_reference', $batchNumber)
            ->with(['store_branch', 'encoder', 'store_order_items'])
            ->get();

        if ($orders->isEmpty()) {
            return redirect()->route('dts-mass-orders.index')->withErrors(['error' => 'Batch not found']);
        }

        $firstOrder = $orders->first();

        // Extract variant from remarks
        $variant = 'N/A';
        if ($firstOrder->remarks && strpos($firstOrder->remarks, 'Mass DTS Order - ') !== false) {
            $variant = str_replace('Mass DTS Order - ', '', $firstOrder->remarks);
        }

        // Get date range
        $dateFrom = $orders->min('order_date');
        $dateTo = $orders->max('order_date');

        // Get SAP item info or supplier items
        $itemCode = null;
        $sapItem = null;
        $supplierItems = [];

        $firstOrderItem = \App\Models\StoreOrderItem::where('store_order_id', $firstOrder->id)->first();
        if ($firstOrderItem) {
            $itemCode = $firstOrderItem->item_code;

            if ($variant === 'FRUITS AND VEGETABLES') {
                // Get unique items from batch
                $itemsInBatch = \App\Models\StoreOrderItem::whereIn('store_order_id', $orders->pluck('id'))
                    ->select('item_code')
                    ->distinct()
                    ->pluck('item_code');

                // Fetch supplier items
                $supplierItems = \App\Models\SupplierItems::where('SupplierCode', 'DROPS')
                    ->whereIn('ItemCode', $itemsInBatch)
                    ->where('is_active', 1)
                    ->select('id', 'ItemCode', 'item_name', 'uom', 'cost')
                    ->orderBy('item_name')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'item_code' => $item->ItemCode,
                            'item_name' => $item->item_name,
                            'uom' => $item->uom,
                            'price' => $item->cost
                        ];
                    })
                    ->toArray();
            } else {
                $sapItem = SAPMasterfile::where('ItemCode', $itemCode)
                    ->where('is_active', 1)
                    ->where('AltUOM', 'LIKE', $variant === 'ICE CREAM' ? '%GAL%' : '%')
                    ->first();
            }
        }

        // Generate dates array
        $dates = [];
        $start = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);
        $dayOfWeekMap = [
            'MONDAY' => 1, 'TUESDAY' => 2, 'WEDNESDAY' => 3, 'THURSDAY' => 4,
            'FRIDAY' => 5, 'SATURDAY' => 6, 'SUNDAY' => 7
        ];

        while ($start->lte($end)) {
            $dayName = strtoupper($start->format('l'));
            $dates[] = [
                'date' => $start->format('Y-m-d'),
                'display' => $dayName . '- ' . strtoupper($start->format('M')) . ' ' . $start->format('j'),
                'day_of_week' => $dayName,
                'delivery_schedule_id' => $dayOfWeekMap[$dayName] ?? null
            ];
            $start->addDay();
        }

        // Get unique stores and sort by name
        $stores = $orders->map(function ($order) use ($variant) {
            $deliveryScheduleIds = \DB::table('d_t_s_delivery_schedules')
                ->where('store_branch_id', $order->store_branch_id)
                ->where('variant', $variant)
                ->pluck('delivery_schedule_id')
                ->map(function ($id) { return (int) $id; })
                ->toArray();

            return [
                'id' => $order->store_branch->id,
                'name' => $order->store_branch->name,
                'branch_code' => $order->store_branch->branch_code,
                'brand_code' => $order->store_branch->brand_code,
                'complete_address' => $order->store_branch->complete_address,
                'label' => $order->store_branch->name,
                'delivery_schedule_ids' => $deliveryScheduleIds
            ];
        })->unique('id')->sortBy('name')->values();

        // Build orders data structure
        $ordersData = [];
        if ($variant === 'FRUITS AND VEGETABLES') {
            // Structure: { itemId: { date: { storeId: quantity } } }
            foreach ($orders as $order) {
                foreach($order->store_order_items as $orderItem) {
                    if ($orderItem) {
                        // Find supplier item id
                        $supplierItem = collect($supplierItems)->firstWhere('item_code', $orderItem->item_code);
                        if ($supplierItem) {
                            $ordersData[$supplierItem['id']][$order->order_date][$order->store_branch_id] = [
                                'approved' => $orderItem->quantity_approved,
                                'committed' => $orderItem->quantity_commited,
                            ];
                        }
                    }
                }
            }
        } else {
            // Structure: { date: { storeId: { approved: quantity, committed: quantity } } }
            foreach ($orders as $order) {
                $orderItem = \App\Models\StoreOrderItem::where('store_order_id', $order->id)->first();
                if ($orderItem) {
                    $ordersData[$order->order_date][$order->store_branch_id] = [
                        'approved' => $orderItem->quantity_approved,
                        'committed' => $orderItem->quantity_commited,
                    ];
                }
            }
        }

        return Inertia::render('DTSMassOrders/Show', [
            'batch_number' => $batchNumber,
            'variant' => $variant,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'stores' => $stores,
            'dates' => $dates,
            'sap_item' => $sapItem ? [
                'item_code' => $sapItem->ItemCode,
                'item_description' => $sapItem->ItemDescription,
                'alt_uom' => $sapItem->AltUOM,
                'base_uom' => $sapItem->BaseUOM
            ] : null,
            'supplier_items' => $supplierItems,
            'orders' => $ordersData,
            'status' => $firstOrder->order_status,
            'created_at' => $firstOrder->created_at,
            'encoder' => $firstOrder->encoder
        ]);
    }

    public function edit($batchNumber)
    {
        // Get all orders for this batch
        $orders = StoreOrder::where('batch_reference', $batchNumber)
            ->with(['store_branch', 'store_order_items'])
            ->get();

        if ($orders->isEmpty()) {
            return redirect()->route('dts-mass-orders.index')->withErrors(['error' => 'Batch not found']);
        }

        $firstOrder = $orders->first();

        // Extract variant from remarks
        $variant = 'N/A';
        if ($firstOrder->remarks && strpos($firstOrder->remarks, 'Mass DTS Order - ') !== false) {
            $variant = str_replace('Mass DTS Order - ', '', $firstOrder->remarks);
        }

        // Get date range
        $dateFrom = $orders->min('order_date');
        $dateTo = $orders->max('order_date');

        // Get SAP item
        $firstOrderItem = \App\Models\StoreOrderItem::where('store_order_id', $firstOrder->id)->first();
        $sapItem = null;
        if ($firstOrderItem) {
            $sapItem = SAPMasterfile::where('ItemCode', $firstOrderItem->item_code)
                ->where('is_active', 1)
                ->where('AltUOM', 'LIKE', $variant === 'ICE CREAM' ? '%GAL%' : '%')
                ->first();
        }

        // Generate dates between date_from and date_to
        $dates = [];
        $dayOfWeekMap = [
            'MONDAY' => 1,
            'TUESDAY' => 2,
            'WEDNESDAY' => 3,
            'THURSDAY' => 4,
            'FRIDAY' => 5,
            'SATURDAY' => 6,
            'SUNDAY' => 7
        ];

        $start = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);

        while ($start->lte($end)) {
            $dayName = strtoupper($start->format('l'));
            $dates[] = [
                'date' => $start->format('Y-m-d'),
                'display' => $dayName . '- ' . strtoupper($start->format('M')) . ' ' . $start->format('j'),
                'day_of_week' => $dayName,
                'delivery_schedule_id' => $dayOfWeekMap[$dayName] ?? null
            ];
            $start->addDay();
        }

        // Fetch user assigned stores
        $stores = [];
        if (auth()->check()) {
            $user = auth()->user();
            $stores = $user->store_branches()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(function ($store) use ($variant) {
                    // Get delivery schedule IDs for this store and variant
                    $deliveryScheduleIds = \DB::table('d_t_s_delivery_schedules')
                        ->where('store_branch_id', $store->id)
                        ->where('variant', $variant)
                        ->pluck('delivery_schedule_id')
                        ->map(function ($id) {
                            return (int) $id; // Convert to integer
                        })
                        ->toArray();

                    return [
                        'id' => $store->id,
                        'name' => $store->name,
                        'branch_code' => $store->branch_code,
                        'brand_code' => $store->brand_code,
                        'complete_address' => $store->complete_address,
                        'label' => $store->name,
                        'delivery_schedule_ids' => $deliveryScheduleIds
                    ];
                })
                ->values()
                ->toArray();
        }

        // Build existing orders array
        $existingOrders = [];
        $receivedStatus = [];
        $supplierItems = [];

        if ($variant === 'FRUITS AND VEGETABLES') {
            // For FRUITS AND VEGETABLES: group by item_code
            // Get all unique items from the batch orders
            $itemsInBatch = \App\Models\StoreOrderItem::whereIn('store_order_id', $orders->pluck('id'))
                ->select('item_code')
                ->distinct()
                ->pluck('item_code');

            // Fetch supplier items for these items
            $supplierItems = \App\Models\SupplierItems::where('SupplierCode', 'DROPS')
                ->whereIn('ItemCode', $itemsInBatch)
                ->where('is_active', 1)
                ->select('id', 'ItemCode', 'item_name', 'uom', 'cost')
                ->orderBy('item_name')
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'item_code' => $item->ItemCode,
                        'item_name' => $item->item_name,
                        'uom' => $item->uom,
                        'price' => $item->cost
                    ];
                })
                ->toArray();

            // Build existing orders: { itemId: { date: { storeId: quantity } } }
            foreach ($orders as $order) {
                foreach ($order->store_order_items as $orderItem) {
                    if ($orderItem) {
                        // Find the supplier item id
                        $supplierItem = collect($supplierItems)->firstWhere('item_code', $orderItem->item_code);
                        if ($supplierItem) {
                            $existingOrders[$supplierItem['id']][$order->order_date][$order->store_branch_id] = $orderItem->quantity_ordered;
                            // Check if item is received (quantity_received > 0)
                             $receivedStatus[$supplierItem['id']][$order->order_date][$order->store_branch_id] = ($orderItem->quantity_received > 0);
                        }
                    }
                }
            }
        } else {
            // For ICE CREAM/SALMON: { date: { storeId: quantity } }
            foreach ($orders as $order) {
                $orderItem = $order->store_order_items->first();
                if ($orderItem) {
                    $existingOrders[$order->order_date][$order->store_branch_id] = $orderItem->quantity_ordered;
                    // Check if item is received (quantity_received > 0)
                    $receivedStatus[$order->order_date][$order->store_branch_id] = ($orderItem->quantity_received > 0);
                }
            }
        }

        return Inertia::render('DTSMassOrders/Edit', [
            'batch_number' => $batchNumber,
            'variant' => $variant,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'stores' => $stores,
            'dates' => $dates,
            'sap_item' => $sapItem ? [
                'item_code' => $sapItem->ItemCode,
                'item_description' => $sapItem->ItemDescription,
                'alt_uom' => $sapItem->AltUOM,
                'cost_per_quantity' => $sapItem->CostPerQuantity ?? 0
            ] : null,
            'existing_orders' => $existingOrders,
            'received_status' => $receivedStatus,
            'status' => $firstOrder->order_status,
            'supplier_items' => $supplierItems
        ]);
    }

    public function update(Request $request, $batchNumber)
    {
        try {
            \DB::beginTransaction();

            $orders = $request->input('orders', []);
            $variant = $request->input('variant');

            // Delete all existing orders for this batch
            StoreOrder::where('batch_reference', $batchNumber)->delete();

            if ($variant === 'FRUITS AND VEGETABLES') {
                // Handle FRUITS AND VEGETABLES variant
                $supplierItems = $request->input('supplier_items', []);
                $lastOrderNumbers = []; // Initialize map

                // Re-create orders for each item
                foreach ($orders as $itemId => $dateOrders) {
                    $supplierItem = collect($supplierItems)->firstWhere('id', (int)$itemId);
                    if (!$supplierItem) continue;

                    foreach ($dateOrders as $date => $storeOrders) {
                        foreach ($storeOrders as $storeBranchId => $quantity) {
                            if (empty($quantity) || $quantity <= 0) {
                                continue;
                            }

                            $costPerQuantity = $supplierItem['price'];
                            $totalCost = $quantity * $costPerQuantity;

                            // Get last order number for this store
                            $orderNumber = $this->generateOrderNumber($storeBranchId, $lastOrderNumbers);

                            // Create StoreOrder
                            $storeOrder = StoreOrder::create([
                                'encoder_id' => auth()->id(),
                                'supplier_id' => 5,
                                'store_branch_id' => $storeBranchId,
                                'order_number' => $orderNumber,
                                'order_date' => $date,
                                'order_status' => 'committed',
                                'variant' => 'mass dts',
                                'batch_reference' => $batchNumber,
                                'remarks' => "Mass DTS Order - {$variant}",
                            ]);

                            // Create StoreOrderItem
                            $storeOrderItem = \App\Models\StoreOrderItem::create([
                                'store_order_id' => $storeOrder->id,
                                'item_code' => $supplierItem['item_code'],
                                'quantity_ordered' => $quantity,
                                'quantity_approved' => $quantity,
                                'quantity_commited' => $quantity,
                                'quantity_received' => 0,
                                'cost_per_quantity' => $costPerQuantity,
                                'total_cost' => $totalCost,
                                'uom' => $supplierItem['uom'],
                                'remarks' => null,
                            ]);

                            if ($storeOrderItem->quantity_commited > 0 && $storeOrderItem->ordered_item_receive_dates()->doesntExist()) {
                                $storeOrderItem->ordered_item_receive_dates()->create([
                                    'quantity_received' => $storeOrderItem->quantity_commited,
                                    'status' => 'pending',
                                    'received_by_user_id' => auth()->id(),
                                ]);
                            }
                        }
                    }
                }
            } else {
                // Handle ICE CREAM/SALMON variant
                $sapItem = $request->input('sap_item');
                $lastOrderNumbers = []; // Initialize map

                // Re-create orders with updated quantities
                foreach ($orders as $date => $storeOrders) {
                    foreach ($storeOrders as $storeBranchId => $quantity) {
                        if (empty($quantity) || $quantity <= 0) {
                            continue;
                        }

                        $costPerQuantity = $sapItem['cost_per_quantity'] ?? 0;
                        $totalCost = $quantity * $costPerQuantity;

                        // Get last order number for this store
                        $orderNumber = $this->generateOrderNumber($storeBranchId, $lastOrderNumbers);

                        // Create StoreOrder
                        $storeOrder = StoreOrder::create([
                            'encoder_id' => auth()->id(),
                            'supplier_id' => 5,
                            'store_branch_id' => $storeBranchId,
                            'order_number' => $orderNumber,
                            'order_date' => $date,
                            'order_status' => 'committed',
                            'variant' => 'mass dts',
                            'batch_reference' => $batchNumber,
                            'remarks' => "Mass DTS Order - {$variant}",
                        ]);

                        // Create StoreOrderItem
                        $storeOrderItem = \App\Models\StoreOrderItem::create([
                            'store_order_id' => $storeOrder->id,
                            'item_code' => $sapItem['item_code'],
                            'quantity_ordered' => $quantity,
                            'quantity_approved' => $quantity,
                            'quantity_commited' => $quantity,
                            'quantity_received' => 0,
                            'cost_per_quantity' => $costPerQuantity,
                            'total_cost' => $totalCost,
                            'uom' => $sapItem['alt_uom'],
                            'remarks' => null,
                        ]);

                        if ($storeOrderItem->quantity_commited > 0 && $storeOrderItem->ordered_item_receive_dates()->doesntExist()) {
                            $storeOrderItem->ordered_item_receive_dates()->create([
                                'quantity_received' => $storeOrderItem->quantity_commited,
                                'status' => 'pending',
                                'received_by_user_id' => auth()->id(),
                            ]);
                        }
                    }
                }
            }

            \DB::commit();

            return redirect()->route('dts-mass-orders.index')->with('success', "Batch {$batchNumber} updated successfully!");
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Error updating mass DTS orders: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to update orders: ' . $e->getMessage()]);
        }
    }

    public function export($batchNumber)
    {
        // Get all orders for this batch
        $orders = StoreOrder::where('batch_reference', $batchNumber)
            ->with(['store_branch', 'encoder', 'store_order_items'])
            ->get();

        if ($orders->isEmpty()) {
            return redirect()->route('dts-mass-orders.index')->withErrors(['error' => 'Batch not found']);
        }

        $firstOrder = $orders->first();

        // Extract variant
        $variant = 'N/A';
        if ($firstOrder->remarks && strpos($firstOrder->remarks, 'Mass DTS Order - ') !== false) {
            $variant = str_replace('Mass DTS Order - ', '', $firstOrder->remarks);
        }

        // Get date range
        $dateFrom = $orders->min('order_date');
        $dateTo = $orders->max('order_date');

        $batchData = [
            'batch_number' => $batchNumber,
            'variant' => $variant,
            'status' => $firstOrder->order_status,
            'date_range' => Carbon::parse($dateFrom)->format('M d, Y') . ' - ' . Carbon::parse($dateTo)->format('M d, Y'),
            'encoder' => $firstOrder->encoder->first_name . ' ' . $firstOrder->encoder->last_name,
            'created_at' => Carbon::parse($firstOrder->created_at)->format('m/d/Y h:i A')
        ];

        if ($variant === 'FRUITS AND VEGETABLES') {
            // FRUITS AND VEGETABLES export structure
            $firstOrderItem = \App\Models\StoreOrderItem::where('store_order_id', $firstOrder->id)->first();

            // Get unique items from batch
            $itemsInBatch = \App\Models\StoreOrderItem::whereIn('store_order_id', $orders->pluck('id'))
                ->select('item_code')
                ->distinct()
                ->pluck('item_code');

            // Fetch supplier items
            $supplierItems = \App\Models\SupplierItems::where('SupplierCode', 'DROPS')
                ->whereIn('ItemCode', $itemsInBatch)
                ->where('is_active', 1)
                ->select('id', 'ItemCode', 'item_name', 'uom', 'cost')
                ->orderBy('item_name')
                ->get();

            // Generate dates array
            $dates = [];
            $start = Carbon::parse($dateFrom);
            $end = Carbon::parse($dateTo);
            $dayOfWeekMap = [
                'MONDAY' => 1, 'TUESDAY' => 2, 'WEDNESDAY' => 3, 'THURSDAY' => 4,
                'FRIDAY' => 5, 'SATURDAY' => 6, 'SUNDAY' => 7
            ];

            while ($start->lte($end)) {
                $dayName = strtoupper($start->format('l'));
                $dates[] = [
                    'date' => $start->format('Y-m-d'),
                    'display' => $dayName . '- ' . strtoupper($start->format('M')) . ' ' . $start->format('j'),
                    'day_of_week' => $dayName,
                    'delivery_schedule_id' => $dayOfWeekMap[$dayName] ?? null
                ];
                $start->addDay();
            }

            // Get unique stores and sort by name
            $stores = $orders->map(function ($order) use ($variant) {
                $deliveryScheduleIds = \DB::table('d_t_s_delivery_schedules')
                    ->where('store_branch_id', $order->store_branch_id)
                    ->where('variant', $variant)
                    ->pluck('delivery_schedule_id')
                    ->map(function ($id) { return (int) $id; })
                    ->toArray();

                return [
                    'id' => $order->store_branch->id,
                    'name' => $order->store_branch->name,
                    'branch_code' => $order->store_branch->branch_code,
                    'brand_code' => $order->store_branch->brand_code,
                    'complete_address' => $order->store_branch->complete_address,
                    'delivery_schedule_ids' => $deliveryScheduleIds
                ];
            })->unique('id')->sortBy('name')->values()->toArray();

            // Build orders data: { itemId: { date: { storeId: quantity } } }
            $ordersData = [];
            foreach ($orders as $order) {
                foreach ($order->store_order_items as $orderItem) {
                    if ($orderItem) {
                        $supplierItem = $supplierItems->firstWhere('ItemCode', $orderItem->item_code);
                        if ($supplierItem) {
                            $ordersData[$supplierItem->id][$order->order_date][$order->store_branch_id] = $orderItem->quantity_commited;
                        }
                    }
                }
            }

            $batchData['supplier_items'] = $supplierItems->map(function ($item) {
                return [
                    'id' => $item->id,
                    'item_code' => $item->ItemCode,
                    'item_name' => $item->item_name,
                    'uom' => $item->uom,
                    'price' => $item->cost
                ];
            })->toArray();
            $batchData['stores'] = $stores;
            $batchData['dates'] = $dates;
            $batchData['orders'] = $ordersData;

        } else {
            // ICE CREAM / SALMON export structure
            $firstOrderItem = \App\Models\StoreOrderItem::where('store_order_id', $firstOrder->id)->first();
            $sapItem = null;
            if ($firstOrderItem) {
                $sapItem = SAPMasterfile::where('ItemCode', $firstOrderItem->item_code)
                    ->where('is_active', 1)
                    ->where('AltUOM', 'LIKE', $variant === 'ICE CREAM' ? '%GAL%' : '%')
                    ->first();
            }

            // Prepare dates data
            $datesData = [];
            $start = Carbon::parse($dateFrom);
            $end = Carbon::parse($dateTo);
            $grandTotal = 0;

            while ($start->lte($end)) {
                $dateString = $start->format('Y-m-d');
                $dayName = strtoupper($start->format('l'));
                $display = $dayName . '- ' . strtoupper($start->format('M')) . ' ' . $start->format('j');

                $storesForDate = [];
                $dayTotal = 0;

                foreach ($orders as $order) {
                    if ($order->order_date === $dateString) {
                        $orderItem = \App\Models\StoreOrderItem::where('store_order_id', $order->id)->first();
                        if ($orderItem) {
                            $storesForDate[] = [
                                'name' => $order->store_branch->name,
                                'brand_code' => $order->store_branch->brand_code,
                                'complete_address' => $order->store_branch->complete_address,
                                'quantity' => $orderItem->quantity_commited
                            ];
                            $dayTotal += $orderItem->quantity_commited;
                        }
                    }
                }

                if (!empty($storesForDate)) {
                    $datesData[] = [
                        'display' => $display,
                        'stores' => $storesForDate,
                        'total' => $dayTotal
                    ];
                    $grandTotal += $dayTotal;
                }

                $start->addDay();
            }

            $batchData['sap_item'] = [
                'item_code' => $sapItem->ItemCode ?? '',
                'item_description' => $sapItem->ItemDescription ?? '',
                'alt_uom' => $sapItem->AltUOM ?? ''
            ];
            $batchData['dates'] = $datesData;
            $batchData['grand_total'] = $grandTotal;
        }

        $fileName = "DTS_Mass_Order_{$batchNumber}_" . date('Y-m-d') . ".xlsx";

        return Excel::download(new DTSMassOrderExport($batchData), $fileName);
    }

    public function validateVariant($variant)
    {
        // Only validate ICE CREAM and SALMON
        if (!in_array($variant, ['ICE CREAM', 'SALMON'])) {
            return response()->json(['valid' => true]);
        }

        // Define variant requirements
        $variantConfig = [
            'ICE CREAM' => [
                'item_code' => '359A2A',
                'uom' => 'GAL(3.8)',
                'supplier_code' => 'DROPS'
            ],
            'SALMON' => [
                'item_code' => '269A2A',
                'uom' => 'KG',
                'supplier_code' => 'DROPS'
            ]
        ];

        $config = $variantConfig[$variant];

        // Check if user has access to DROPS supplier
        $user = auth()->user();
        $hasSupplierAccess = $user->suppliers()
            ->where('suppliers.supplier_code', $config['supplier_code'])
            ->exists();

        if (!$hasSupplierAccess) {
            return response()->json([
                'valid' => false,
                'message' => "You do not have access to supplier {$config['supplier_code']} required for {$variant}."
            ]);
        }

        // Check if item exists in supplier_items with exact match
        $supplierItem = \App\Models\SupplierItems::where('ItemCode', $config['item_code'])
            ->where('uom', $config['uom'])
            ->where('SupplierCode', $config['supplier_code'])
            ->where('is_active', 1)
            ->first();

        if (!$supplierItem) {
            // Log for debugging
            \Log::info("Validation failed for {$variant}", [
                'item_code' => $config['item_code'],
                'uom' => $config['uom'],
                'supplier_code' => $config['supplier_code']
            ]);

            return response()->json([
                'valid' => false,
                'message' => "Item Code {$config['item_code']} with UOM {$config['uom']} for variant {$variant} does not exist in Supplier Items."
            ]);
        }

        return response()->json(['valid' => true]);
    }

    public function getAvailableDates($variant)
    {
        \Log::info("--- DTS Mass Order Date Debug (v2) ---");
        \Log::info("Starting getAvailableDates for variant: " . $variant);

        $cutoff = \App\Models\OrdersCutoff::where('ordering_template', $variant)->first();
        $enabledDates = [];

        if ($cutoff) {
            $now = \Carbon\Carbon::now('Asia/Manila');

            $getCutoffDate = function($day, $time) use ($now) {
                if (!$day || !$time) return null;
                $dayIndex = ($day == 7) ? 0 : $day;
                return $now->copy()->startOfWeek(\Carbon\Carbon::SUNDAY)->addDays($dayIndex)->setTimeFromTimeString($time);
            };

            $cutoff1Date = $getCutoffDate($cutoff->cutoff_1_day, $cutoff->cutoff_1_time);
            $cutoff2Date = $getCutoffDate($cutoff->cutoff_2_day, $cutoff->cutoff_2_time);

            $daysToCoverStr = '';
            $weekOffset = 0;

            // Determine which set of days and which week to use
            if ($cutoff1Date && $now->lt($cutoff1Date)) {
                $daysToCoverStr = $cutoff->days_covered_1;
                // If there is no second cutoff (i.e., a single weekly cutoff),
                // ordering before this cutoff is for the *next* week's delivery cycle.
                if (!$cutoff->cutoff_2_day) {
                    $weekOffset = 1; // Next week
                } else {
                    // For variants with multiple cutoffs, being before the first cutoff
                    // means ordering for the current week's first set of delivery days.
                    $weekOffset = 0; // Current week
                }
            } elseif ($cutoff2Date && $now->lt($cutoff2Date)) {
                $daysToCoverStr = $cutoff->days_covered_2;
                $weekOffset = 0; // Current week (ordering for the second set of delivery days)
            } else {
                // After all cutoffs for the current week have passed, ordering is for the next week's first cycle.
                $daysToCoverStr = $cutoff->days_covered_1;
                $weekOffset = 1; // Next week
            }

            $startOfTargetWeek = $now->copy()->startOfWeek(\Carbon\Carbon::SUNDAY)->addWeeks($weekOffset);

            $daysToCover = $daysToCoverStr ? explode(',', $daysToCoverStr) : [];
            $dayMap = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

            foreach ($daysToCover as $day) {
                $day = trim($day);
                if (isset($dayMap[$day])) {
                    $date = $startOfTargetWeek->copy()->addDays($dayMap[$day]);
                    $enabledDates[] = $date->toDateString();
                }
            }
        } else {
            // If no cutoff is defined (e.g., for Fruits and Vegetables), enable a default range.
            $start = \Carbon\Carbon::tomorrow();
            $end = \Carbon\Carbon::tomorrow()->addDays(59);
            while($start->lte($end)) {
                $enabledDates[] = $start->toDateString();
                $start->addDay();
            }
        }
        \Log::info("Initial enabled dates for {$variant}:", $enabledDates);

        // Get all distinct booked dates for this variant with consistent filtering
        $bookedDates = $this->getBaseBatchQuery()
            ->where('remarks', 'LIKE', "Mass DTS Order - {$variant}")
            ->distinct()
            ->pluck('order_date')
            ->map(function ($date) {
                return \Carbon\Carbon::parse($date)->toDateString(); // Ensure format is Y-m-d
            })
            ->toArray();
        \Log::info("Individually booked dates found for {$variant}:", $bookedDates);

        // Filter out dates that are already booked
        $availableDates = array_filter($enabledDates, function($date) use ($bookedDates) {
            return !in_array($date, $bookedDates);
        });

        $finalDates = array_values($availableDates);
        \Log::info("Final available dates for {$variant}:", $finalDates);
        \Log::info("--- End DTS Mass Order Date Debug (v2) ---");

        return response()->json($finalDates);
    }

    public function validateCutoff(string $variant)
    {
        $exists = \App\Models\OrdersCutoff::where('ordering_template', $variant)->exists();
        return response()->json(['exists' => $exists]);
    }

    private function canEditBatch($batch)
    {
        // Check if the current time is past the cutoff for this batch's variant
        $variant = $batch->variant;
        if ($variant === 'N/A') {
            return false;
        }

        $cutoff = \App\Models\OrdersCutoff::where('ordering_template', $variant)->first();
        if (!$cutoff) {
            return true; // If no cutoff defined, allow editing
        }

        $now = Carbon::now('Asia/Manila');

        // Get the earliest order date from the batch
        $dateFrom = Carbon::parse($batch->date_from);

        // Determine which week the batch orders belong to
        $batchWeekStart = $dateFrom->copy()->startOfWeek(Carbon::SUNDAY);
        $currentWeekStart = $now->copy()->startOfWeek(Carbon::SUNDAY);

        // If batch is from a past week, don't allow editing
        if ($batchWeekStart->lt($currentWeekStart)) {
            return false;
        }

        // If batch is from current week, check cutoffs
        if ($batchWeekStart->eq($currentWeekStart)) {
            $getCutoffDateTime = function($day, $time) use ($now) {
                if (!$day || !$time) return null;
                $dayIndex = ($day == 7) ? 0 : $day;
                return $now->copy()->startOfWeek(Carbon::SUNDAY)->addDays($dayIndex)->setTimeFromTimeString($time);
            };

            $cutoff1DateTime = $getCutoffDateTime($cutoff->cutoff_1_day, $cutoff->cutoff_1_time);
            $cutoff2DateTime = $getCutoffDateTime($cutoff->cutoff_2_day, $cutoff->cutoff_2_time);

            // If current time is past cutoff2, don't allow editing
            if ($cutoff2DateTime && $now->gte($cutoff2DateTime)) {
                return false;
            }

            // If current time is past cutoff1 but before cutoff2, check if batch was for days_covered_1
            // For simplicity, we'll check if any order dates match the days_covered_1
            if ($cutoff1DateTime && $now->gte($cutoff1DateTime)) {
                $dayMap = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
                $daysCovered1 = $cutoff->days_covered_1 ? explode(',', $cutoff->days_covered_1) : [];

                foreach ($daysCovered1 as $day) {
                    $day = trim($day);
                    if (isset($dayMap[$day])) {
                        $dayDate = $batchWeekStart->copy()->addDays($dayMap[$day]);
                        if ($dateFrom->eq($dayDate)) {
                            // This batch is for days_covered_1 and cutoff1 has passed
                            return false;
                        }
                    }
                }
            }
        }

        // If batch is from future week, allow editing
        return true;
    }
}
