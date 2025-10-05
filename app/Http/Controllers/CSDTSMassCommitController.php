<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\SAPMasterfile;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class CSDTSMassCommitController extends Controller
{
    public function index(Request $request)
    {
        // Build query for mass order batches
        $query = StoreOrder::select([
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
            ->groupBy('batch_reference', 'encoder_id');

        // Apply filter - Default to 'approved' for commit screen
        $filterQuery = $request->input('filterQuery', 'approved');
        if ($filterQuery !== 'all') {
            $query->havingRaw('MIN(order_status) = ?', [$filterQuery]);
        }

        $batches = $query->orderBy('created_at', 'desc')->paginate(20);

        // Calculate counts for each status
        $counts = [
            'all' => StoreOrder::whereNotNull('batch_reference')
                ->where('variant', 'mass dts')
                ->distinct('batch_reference')
                ->count('batch_reference'),
            'approved' => StoreOrder::whereNotNull('batch_reference')
                ->where('variant', 'mass dts')
                ->where('order_status', 'approved')
                ->distinct('batch_reference')
                ->count('batch_reference'),
            'committed' => StoreOrder::whereNotNull('batch_reference')
                ->where('variant', 'mass dts')
                ->where('order_status', 'committed')
                ->distinct('batch_reference')
                ->count('batch_reference'),
        ];

        // Calculate total quantity for each batch and extract variant
        $batches->getCollection()->transform(function ($batch) {
            $totalQuantity = \DB::table('store_order_items')
                ->join('store_orders', 'store_order_items.store_order_id', '=', 'store_orders.id')
                ->where('store_orders.batch_reference', $batch->batch_number)
                ->sum('store_order_items.quantity_ordered');

            $batch->total_quantity = $totalQuantity;

            $batch->variant = 'N/A';
            if ($batch->remarks && strpos($batch->remarks, 'Mass DTS Order - ') !== false) {
                $batch->variant = str_replace('Mass DTS Order - ', '', $batch->remarks);
            }

            $batch->can_edit = auth()->user()->can('edit cs dts mass commit');

            return $batch;
        });

        return Inertia::render('CSDTSMassCommits/Index', [
            'batches' => $batches,
            'filters' => [
                'filterQuery' => $filterQuery,
            ],
            'counts' => $counts
        ]);
    }

    public function edit($batchNumber)
    {
        // Get all orders for this batch
        $orders = StoreOrder::where('batch_reference', $batchNumber)
            ->with(['store_branch', 'store_order_items'])
            ->get();

        if ($orders->isEmpty()) {
            return redirect()->route('cs-dts-mass-commits.index')->withErrors(['error' => 'Batch not found']);
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
                            $existingOrders[$supplierItem['id']][$order->order_date][$order->store_branch_id] = $orderItem->quantity_commited;
                        }
                    }
                }
            }
        } else {
            // For ICE CREAM/SALMON: { date: { storeId: quantity } }
            foreach ($orders as $order) {
                $orderItem = $order->store_order_items->first();
                if ($orderItem) {
                    $existingOrders[$order->order_date][$order->store_branch_id] = $orderItem->quantity_commited;
                }
            }
        }

        return Inertia::render('CSDTSMassCommits/Edit', [
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
            'status' => $firstOrder->order_status,
            'supplier_items' => $supplierItems
        ]);
    }

    public function update(Request $request, $batchNumber)
    {
        try {
            \DB::beginTransaction();
            $user = auth()->user();

            $formOrders = $request->input('orders', []);
            $variant = $request->input('variant');

            // Find all StoreOrder items belonging to this batch
            $batchOrders = StoreOrder::where('batch_reference', $batchNumber)->with('store_order_items')->get();

            if ($variant === 'FRUITS AND VEGETABLES') {
                $supplierItems = $request->input('supplier_items', []);
                foreach ($formOrders as $itemId => $dateOrders) {
                    $supplierItem = collect($supplierItems)->firstWhere('id', (int)$itemId);
                    if (!$supplierItem) continue;

                    foreach ($dateOrders as $date => $storeOrders) {
                        foreach ($storeOrders as $storeBranchId => $quantity) {
                            $orderToUpdate = $batchOrders->first(function ($order) use ($date, $storeBranchId) {
                                return $order->order_date == $date && $order->store_branch_id == $storeBranchId;
                            });

                            if ($orderToUpdate) {
                                $orderItemToUpdate = $orderToUpdate->store_order_items->firstWhere('item_code', $supplierItem['item_code']);
                                if ($orderItemToUpdate) {
                                    $newQty = empty($quantity) ? 0 : $quantity;
                                    $orderItemToUpdate->quantity_commited = $newQty;
                                    $orderItemToUpdate->total_cost = $newQty * $orderItemToUpdate->cost_per_quantity;
                                    $orderItemToUpdate->save();
                                }
                            }
                        }
                    }
                }
            } else { // ICE CREAM / SALMON
                foreach ($formOrders as $date => $storeOrders) {
                    foreach ($storeOrders as $storeBranchId => $quantity) {
                        $orderToUpdate = $batchOrders->first(function ($order) use ($date, $storeBranchId) {
                            return $order->order_date == $date && $order->store_branch_id == $storeBranchId;
                        });

                        if ($orderToUpdate) {
                            $orderItemToUpdate = $orderToUpdate->store_order_items->first();
                            if ($orderItemToUpdate) {
                                $newQty = empty($quantity) ? 0 : $quantity;
                                $orderItemToUpdate->quantity_commited = $newQty;
                                $orderItemToUpdate->total_cost = $newQty * $orderItemToUpdate->cost_per_quantity;
                                $orderItemToUpdate->save();
                            }
                        }
                    }
                }
            }

            // Update the status and commit info of all orders in the batch
            StoreOrder::where('batch_reference', $batchNumber)->update([
                'order_status' => 'committed',
                'commiter_id' => $user->id,
                'commited_action_date' => Carbon::now(),
            ]);

            // Now, create the placeholder receive date records
            foreach ($batchOrders as $order) {
                foreach ($order->store_order_items as $item) {
                    if ($item->quantity_commited > 0 && $item->ordered_item_receive_dates()->doesntExist()) {
                        $item->ordered_item_receive_dates()->create([
                            'quantity_received' => $item->quantity_commited,
                            'status' => 'pending',
                            'received_by_user_id' => $user->id,
                        ]);
                    }
                }
            }

            \DB::commit();

            return redirect()->route('cs-dts-mass-commits.index')->with('success', "Batch {$batchNumber} committed successfully!");
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Error committing mass DTS orders: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to commit orders: ' . $e->getMessage()]);
        }
    }

    private function generateOrderNumber($storeBranchId, $lastOrder)
    {
        $branch = StoreBranch::find($storeBranchId);
        if (!$branch) {
            throw new \Exception("Store branch not found");
        }

        $branchCode = $branch->branch_code;

        if ($lastOrder) {
            preg_match('/(\d+)$/', $lastOrder->order_number, $matches);
            $lastNumber = isset($matches[1]) ? (int)$matches[1] : 0;
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $branchCode . '-' . str_pad($newNumber, 5, '0', STR_PAD_LEFT);
    }
}
