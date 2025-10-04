<?php

namespace App\Http\Controllers;

use App\Models\DTSDeliverySchedule;
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
    public function index(Request $request)
    {
        $allowedVariants = ['ICE CREAM', 'SALMON', 'FRUITS AND VEGETABLES'];

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

        // Fetch mass order batches grouped from store_orders
        $batches = StoreOrder::select([
                'batch_reference as batch_number',
                'encoder_id',
                \DB::raw('MIN(order_date) as date_from'),
                \DB::raw('MAX(order_date) as date_to'),
                \DB::raw('COUNT(*) as total_orders'),
                \DB::raw('MIN(order_status) as status'),
                \DB::raw('MIN(remarks) as remarks'),
                \DB::raw('MIN(created_at) as created_at'),
                \DB::raw('MAX(updated_at) as updated_at')
            ])
            ->whereNotNull('batch_reference')
            ->where('variant', 'mass dts')
            ->with('encoder')
            ->groupBy('batch_reference', 'encoder_id')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // Calculate total quantity for each batch and extract variant
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

            return $batch;
        });

        return Inertia::render('DTSMassOrders/Index', [
            'variants' => $variants,
            'batches' => $batches
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
            ] : null
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'variant' => 'required|string',
            'orders' => 'required|array',
            'orders.*' => 'array',
            'sap_item' => 'required|array',
        ]);

        $variant = $request->input('variant');
        $orders = $request->input('orders');
        $sapItem = $request->input('sap_item');

        // Get date range from orders
        $dates = array_keys($orders);
        $dateFrom = min($dates);
        $dateTo = max($dates);

        try {
            \DB::beginTransaction();

            // Generate batch number: MDTS-YYYYMMDD-XXX
            $batchNumber = $this->generateBatchNumber();

            $createdOrders = [];
            $totalQuantity = 0;

            // Group orders by store_branch_id and date
            foreach ($orders as $date => $stores) {
                foreach ($stores as $storeBranchId => $quantity) {
                    // Skip if quantity is 0 or empty
                    if (empty($quantity) || $quantity <= 0) {
                        continue;
                    }

                    $totalQuantity += $quantity;

                    // Get the latest order number for this store branch
                    $lastOrder = StoreOrder::where('store_branch_id', $storeBranchId)
                        ->orderBy('id', 'desc')
                        ->first();

                    $orderNumber = $this->generateOrderNumber($storeBranchId, $lastOrder);

                    // Get supplier item cost
                    $supplierItem = \App\Models\SupplierItems::where('ItemCode', $sapItem['item_code'])
                        ->where('is_active', true)
                        ->first();

                    $costPerQuantity = $supplierItem ? $supplierItem->cost : 0;
                    $totalCost = $costPerQuantity * $quantity;

                    // Create StoreOrder
                    $storeOrder = StoreOrder::create([
                        'encoder_id' => auth()->id(),
                        'supplier_id' => 5, // DROPSHIPPING supplier
                        'store_branch_id' => $storeBranchId,
                        'order_number' => $orderNumber,
                        'order_date' => $date,
                        'order_status' => 'approved',
                        'variant' => 'mass dts',
                        'batch_reference' => $batchNumber,
                        'remarks' => "Mass DTS Order - {$variant}",
                    ]);

                    // Create StoreOrderItem
                    \App\Models\StoreOrderItem::create([
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

                    $createdOrders[] = $storeOrder->order_number;
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

    private function generateOrderNumber($storeBranchId, $lastOrder)
    {
        $branch = StoreBranch::find($storeBranchId);
        if (!$branch) {
            throw new \Exception("Store branch not found");
        }

        $branchCode = $branch->branch_code;

        if ($lastOrder) {
            // Extract the numeric part from the last order number
            // Format example: NNSSR-00001
            preg_match('/(\d+)$/', $lastOrder->order_number, $matches);
            $lastNumber = isset($matches[1]) ? (int)$matches[1] : 0;
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        // Format: BRANCHCODE-XXXXX (5 digits)
        return $branchCode . '-' . str_pad($newNumber, 5, '0', STR_PAD_LEFT);
    }

    public function show($batchNumber)
    {
        // Get all orders for this batch
        $orders = StoreOrder::where('batch_reference', $batchNumber)
            ->with(['store_branch', 'encoder'])
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

        // Get SAP item info
        $itemCode = null;
        $sapItem = null;

        $firstOrderItem = \App\Models\StoreOrderItem::where('store_order_id', $firstOrder->id)->first();
        if ($firstOrderItem) {
            $itemCode = $firstOrderItem->item_code;
            $sapItem = SAPMasterfile::where('ItemCode', $itemCode)
                ->where('is_active', 1)
                ->where('AltUOM', 'LIKE', $variant === 'ICE CREAM' ? '%GAL%' : '%')
                ->first();
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

        // Get unique stores
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
        })->unique('id')->values();

        // Build orders data structure
        $ordersData = [];
        foreach ($orders as $order) {
            $orderItem = \App\Models\StoreOrderItem::where('store_order_id', $order->id)->first();
            if ($orderItem) {
                $ordersData[$order->order_date][$order->store_branch_id] = $orderItem->quantity_ordered;
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
        $start = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);

        while ($start->lte($end)) {
            $dayOfWeek = $start->dayOfWeek; // 0 = Sunday, 1 = Monday, etc.

            // Get delivery schedule ID for this day
            $deliverySchedule = DTSDeliverySchedule::where('variant', $variant)
                ->where('day_of_week', $dayOfWeek)
                ->first();

            if ($deliverySchedule) {
                $dates[] = [
                    'date' => $start->format('Y-m-d'),
                    'display' => strtoupper($start->format('l')) . '- ' . strtoupper($start->format('M')) . ' ' . $start->format('j'),
                    'delivery_schedule_id' => $deliverySchedule->id
                ];
            }

            $start->addDay();
        }

        // Get all stores with delivery schedules for this variant
        $stores = StoreBranch::where('is_active', 1)
            ->with(['delivery_schedules' => function ($query) use ($variant) {
                $query->where('variant', $variant);
            }])
            ->get()
            ->map(function ($store) {
                $deliveryScheduleIds = $store->delivery_schedules->pluck('id')->toArray();
                $deliveryScheduleIds = array_map(function ($id) {
                    return (int) $id;
                }, $deliveryScheduleIds);

                return [
                    'id' => $store->id,
                    'name' => $store->name,
                    'brand_code' => $store->brand_code,
                    'complete_address' => $store->complete_address,
                    'delivery_schedule_ids' => $deliveryScheduleIds
                ];
            })
            ->filter(function ($store) {
                return count($store['delivery_schedule_ids']) > 0;
            })
            ->values()
            ->toArray();

        // Build existing orders array
        $existingOrders = [];
        foreach ($orders as $order) {
            $orderItem = $order->store_order_items->first();
            if ($orderItem) {
                $existingOrders[$order->order_date][$order->store_branch_id] = $orderItem->quantity_ordered;
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
            'status' => $firstOrder->order_status
        ]);
    }

    public function update(Request $request, $batchNumber)
    {
        try {
            \DB::beginTransaction();

            $orders = $request->input('orders', []);
            $sapItem = $request->input('sap_item');
            $variant = $request->input('variant');

            // Delete all existing orders for this batch
            StoreOrder::where('batch_reference', $batchNumber)->delete();

            // Re-create orders with updated quantities
            foreach ($orders as $date => $storeOrders) {
                foreach ($storeOrders as $storeBranchId => $quantity) {
                    if (empty($quantity) || $quantity <= 0) {
                        continue;
                    }

                    $costPerQuantity = $sapItem['cost_per_quantity'] ?? 0;
                    $totalCost = $quantity * $costPerQuantity;

                    // Get last order number for this store
                    $lastOrder = StoreOrder::where('store_branch_id', $storeBranchId)
                        ->orderBy('id', 'desc')
                        ->first();

                    $orderNumber = $this->generateOrderNumber($storeBranchId, $lastOrder);

                    // Create StoreOrder
                    $storeOrder = StoreOrder::create([
                        'encoder_id' => auth()->id(),
                        'supplier_id' => 5,
                        'store_branch_id' => $storeBranchId,
                        'order_number' => $orderNumber,
                        'order_date' => $date,
                        'order_status' => 'approved',
                        'variant' => 'mass dts',
                        'batch_reference' => $batchNumber,
                        'remarks' => "Mass DTS Order - {$variant}",
                    ]);

                    // Create StoreOrderItem
                    \App\Models\StoreOrderItem::create([
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
            ->with(['store_branch', 'encoder'])
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

        // Get SAP item
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
                            'quantity' => $orderItem->quantity_ordered
                        ];
                        $dayTotal += $orderItem->quantity_ordered;
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

        // Prepare batch data
        $batchData = [
            'batch_number' => $batchNumber,
            'variant' => $variant,
            'status' => $firstOrder->order_status,
            'date_range' => Carbon::parse($dateFrom)->format('M d, Y') . ' - ' . Carbon::parse($dateTo)->format('M d, Y'),
            'encoder' => $firstOrder->encoder->first_name . ' ' . $firstOrder->encoder->last_name,
            'created_at' => Carbon::parse($firstOrder->created_at)->format('m/d/Y h:i A'),
            'sap_item' => [
                'item_code' => $sapItem->ItemCode ?? '',
                'item_description' => $sapItem->ItemDescription ?? '',
                'alt_uom' => $sapItem->AltUOM ?? ''
            ],
            'dates' => $datesData,
            'grand_total' => $grandTotal
        ];

        $fileName = "DTS_Mass_Order_{$batchNumber}_" . date('Y-m-d') . ".xlsx";

        return Excel::download(new DTSMassOrderExport($batchData), $fileName);
    }

    public function getAvailableDates($variant)
    {
        $cutoff = \App\Models\OrdersCutoff::where('ordering_template', $variant)->first();
        if (!$cutoff) {
            return response()->json([]);
        }

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
            $weekOffset = 0; // Current week (next available days this week)
        } elseif ($cutoff2Date && $now->lt($cutoff2Date)) {
            $daysToCoverStr = $cutoff->days_covered_2;
            $weekOffset = 0; // Current week (next available days this week)
        } else {
            // After all cutoffs, next week
            $daysToCoverStr = $cutoff->days_covered_1;
            $weekOffset = 1; // Next week
        }

        $startOfTargetWeek = $now->copy()->startOfWeek(\Carbon\Carbon::SUNDAY)->addWeeks($weekOffset);

        $daysToCover = $daysToCoverStr ? explode(',', $daysToCoverStr) : [];
        $dayMap = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

        $enabledDates = [];
        foreach ($daysToCover as $day) {
            $day = trim($day);
            if (isset($dayMap[$day])) {
                $date = $startOfTargetWeek->copy()->addDays($dayMap[$day]);
                $enabledDates[] = $date->toDateString();
            }
        }

        return response()->json($enabledDates);
    }
}
