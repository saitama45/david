<?php

namespace App\Http\Controllers;

use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\ImageAttachment;
use App\Models\OrderedItemReceiveDate;
use App\Enums\OrderStatus;
use App\Enums\IntercoStatus;
use App\Models\ProductInventoryStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\ProductInventoryStockManager;
use App\Models\PurchaseItemBatch;
use App\Models\SAPMasterfile;
use Inertia\Inertia;
use Carbon\Carbon;

class IntercoReceivingController extends Controller
{
    /**
     * Display a listing of interco orders for receiving.
     */
    public function index(Request $request)
    {
        $currentFilter = $request->get('currentFilter', 'in_transit');
        $search = $request->get('search', '');

        $baseQuery = StoreOrder::whereNotNull('interco_number')
            ->whereNotNull('sending_store_branch_id')
            ->whereIn('interco_status', [
                IntercoStatus::IN_TRANSIT->value,
                IntercoStatus::RECEIVED->value,
            ]);

        $user = Auth::user();
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');

        if ($assignedStoreIds->isNotEmpty()) {
            $baseQuery->whereIn('store_branch_id', $assignedStoreIds);
        } else {
            $baseQuery->whereRaw('1 = 0');
        }

        if (!empty($search)) {
            $baseQuery->where(function ($query) use ($search) {
                $query->where('interco_number', 'like', "%{$search}%")
                    ->orWhereHas('store_branch', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('sendingStore', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $query = clone $baseQuery;
        switch ($currentFilter) {
            case 'received':
                $query->where('interco_status', IntercoStatus::RECEIVED->value);
                break;
            case 'in_transit':
                $query->where('interco_status', IntercoStatus::IN_TRANSIT->value);
                break;
            case 'all':
            default:
                break;
        }

        $orders = $query->with(['store_branch', 'sendingStore', 'encoder', 'store_order_items.supplierItem.sapMasterfiles'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        $counts = $this->getCounts($baseQuery);

        return Inertia::render('IntercoReceiving/Index', [
            'orders' => $orders,
            'counts' => $counts,
            'filters' => ['search' => $search, 'currentFilter' => $currentFilter],
        ]);
    }

    /**
     * Display the specified interco order details.
     */
    public function show($intercoNumber)
    {
        $order = StoreOrder::where('interco_number', $intercoNumber)
            ->with(['store_branch', 'sendingStore', 'encoder', 'approver'])
            ->firstOrFail();

        $user = Auth::user();
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');

        if (!$assignedStoreIds->contains($order->store_branch_id)) {
            abort(403, 'Unauthorized access to this interco order.');
        }

        // Load supplierItem relationship
        $orderedItems = StoreOrderItem::where('store_order_id', $order->id)
            ->with('supplierItem')
            ->get();

        // Manually attach sap_master_file and calculate soh_stock
        $orderedItems->each(function ($item) use ($order) {
            $item->soh_stock = 0;
            if ($item->supplierItem) {
                // Find the master file based on the supplier item's code
                $sapMasterfile = SAPMasterfile::where('ItemCode', $item->supplierItem->ItemCode)
                                     ->whereColumn('BaseUOM', 'AltUOM')
                                     ->first();

                // Attach it for the frontend, following the structure from OrderReceiving
                $item->supplierItem->sap_master_file = $sapMasterfile;

                if ($sapMasterfile) {
                    $stock = ProductInventoryStock::where('product_inventory_id', $sapMasterfile->id)
                        ->where('store_branch_id', $order->store_branch_id)
                        ->sum('quantity');
                    $item->soh_stock = $stock ?? 0;
                }
            }
        });

        $receiveDatesHistory = OrderedItemReceiveDate::with([
            'store_order_item.supplierItem', // Load up to supplierItem
            'received_by_user',
            'approval_action_by_user'
        ])->whereHas('store_order_item', function ($query) use ($order) {
            $query->where('store_order_id', $order->id);
        })->get();
        
        // Manually attach sap_master_file for history items too
        $receiveDatesHistory->each(function ($history) {
            if ($history->store_order_item && $history->store_order_item->supplierItem) {
                 $history->store_order_item->supplierItem->sap_master_file = SAPMasterfile::where('ItemCode', $history->store_order_item->supplierItem->ItemCode)
                                    ->whereColumn('BaseUOM', 'AltUOM')
                                    ->first();
            }
        });

        $images = $order->image_attachments()->get();

        return Inertia::render('IntercoReceiving/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'receiveDatesHistory' => $receiveDatesHistory,
            'images' => $images,
        ]);
    }

    /**
     * Receive items for an interco order.
     */
    public function receive(Request $request, $itemId)
    {
        $request->validate([
            'quantity_received' => 'required|numeric|min:0',
            'received_date' => 'required|date',
            'expiry_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:255'
        ]);

        $storeOrderItem = StoreOrderItem::findOrFail($itemId);
        $order = $storeOrderItem->store_order;

        if (!$order->isInterco()) {
            return back()->with('error', 'This is not an interco order.');
        }

        $user = Auth::user();
        $user->load('store_branches');
        if (!$user->store_branches->pluck('id')->contains($order->store_branch_id)) {
            abort(403, 'Unauthorized to receive items for this order.');
        }

        if ($request->quantity_received > $storeOrderItem->quantity_commited) {
            return back()->with('error', 'Received quantity cannot exceed committed quantity.');
        }

        DB::beginTransaction();
        try {
            OrderedItemReceiveDate::create([
                'store_order_item_id' => $storeOrderItem->id,
                'quantity_received' => $request->quantity_received,
                'received_date' => $request->received_date,
                'expiry_date' => $request->expiry_date,
                'remarks' => $request->remarks,
                'received_by_user_id' => Auth::user()->id,
                'status' => 'pending'
            ]);

            $storeOrderItem->quantity_received += $request->quantity_received;
            $storeOrderItem->save();

            $this->updateOrderStatus($order);

            DB::commit();

            return back()->with('success', 'Items received successfully and pending approval.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to receive items: ' . $e->getMessage());
        }
    }

    /**
     * Confirm receive for pending items.
     */
    public function confirmReceive($intercoNumber)
    {
        $order = StoreOrder::where('interco_number', $intercoNumber)->firstOrFail();

        $user = Auth::user();
        $user->load('store_branches');
        if (!$user->store_branches->pluck('id')->contains($order->store_branch_id)) {
            abort(403, 'Unauthorized to confirm receive for this order.');
        }

        $pendingItems = OrderedItemReceiveDate::with('store_order_item')
            ->whereHas('store_order_item', function ($query) use ($order) {
            $query->where('store_order_id', $order->id);
        })->where('status', 'pending')->get();

        if ($pendingItems->isEmpty()) {
            return back()->with('info', 'No pending items to confirm.');
        }

        DB::beginTransaction();
        try {
            $aggregatedData = [];

            // 1. Aggregate quantities in BASE UOM
            foreach ($pendingItems as $history) {
                $storeOrderItem = $history->store_order_item;
                $itemCode = $storeOrderItem->item_code;
                $uom = $storeOrderItem->uom;

                if (!$itemCode || !$uom) {
                    Log::warning("IntercoReceivingController: Skipping history item ID {$history->id} due to incomplete data (ItemCode or UOM missing on StoreOrderItem).");
                    continue;
                }

                // Find the SAP Masterfile for the specific UOM it was ordered/received in
                $originalSapMasterfile = SAPMasterfile::where('ItemCode', $itemCode)->where('AltUOM', $uom)->first();

                if (!$originalSapMasterfile) {
                    Log::warning("IntercoReceivingController: Could not find original SAP Masterfile for ItemCode '{$itemCode}' and UOM '{$uom}'. Skipping history item ID {$history->id}.");
                    continue;
                }

                // Find the target SAP Masterfile for SOH (where BaseUOM = AltUOM)
                $stockUpdateTarget = SAPMasterfile::where('ItemCode', $originalSapMasterfile->ItemCode)->whereColumn('BaseUOM', 'AltUOM')->first();
                if (!$stockUpdateTarget) {
                    $stockUpdateTarget = $originalSapMasterfile;
                     Log::warning("IntercoReceivingController: No target SAP Masterfile (BaseUOM=AltUOM) found for ItemCode: {$originalSapMasterfile->ItemCode}. Falling back to original SAPMasterfile ID: {$originalSapMasterfile->id}.");
                }

                // Calculate conversion and quantities
                $conversionFactor = (is_numeric($originalSapMasterfile->BaseQty) && $originalSapMasterfile->BaseQty > 0)
                    ? $originalSapMasterfile->BaseQty
                    : 1;
                $quantityInBaseUom = $history->quantity_received * $conversionFactor;
                $costInBaseUom = ($conversionFactor != 0)
                    ? $storeOrderItem->cost_per_quantity / $conversionFactor
                    : $storeOrderItem->cost_per_quantity;

                // Aggregate data by the target SOH item's ID
                $targetId = $stockUpdateTarget->id;
                if (!isset($aggregatedData[$targetId])) {
                    $aggregatedData[$targetId] = [
                        'total_base_qty' => 0,
                        'total_cost' => 0,
                        'unit_cost' => $costInBaseUom, // Base cost per base UOM
                        'target_masterfile' => $stockUpdateTarget,
                        'store_order' => $storeOrderItem->store_order,
                        'store_order_item_ids' => [], // To create batches
                    ];
                }
                $aggregatedData[$targetId]['total_base_qty'] += $quantityInBaseUom;
                $aggregatedData[$targetId]['total_cost'] += $history->quantity_received * $storeOrderItem->cost_per_quantity;
                $aggregatedData[$targetId]['store_order_item_ids'][] = $storeOrderItem->id;
            }

            // 2. Process aggregated data
            foreach ($aggregatedData as $targetId => $data) {
                $finalSOHToAdd = $data['total_base_qty'];
                $storeOrder = $data['store_order'];
                $targetSapMasterfile = $data['target_masterfile'];

                if ($storeOrder->isInterco()) {
                    $this->processInventoryOutForInterco($storeOrder, $finalSOHToAdd, $targetSapMasterfile, $data['unit_cost'], $data['total_cost']);
                }

                ProductInventoryStock::create([
                    'product_inventory_id' => $targetSapMasterfile->id,
                    'store_branch_id' => $storeOrder->store_branch_id,
                    'quantity' => $finalSOHToAdd,
                    'recently_added' => $finalSOHToAdd,
                    'used' => 0,
                ]);

                $firstStoreOrderItemId = $data['store_order_item_ids'][0] ?? null;

                if ($firstStoreOrderItemId) {
                     $batch = PurchaseItemBatch::create([
                        'store_order_item_id' => $firstStoreOrderItemId,
                        'product_inventory_id' => $targetSapMasterfile->id,
                        'store_branch_id' => $storeOrder->store_branch_id,
                        'purchase_date' => Carbon::today()->format('Y-m-d'),
                        'quantity' => $finalSOHToAdd,
                        'unit_cost' => $data['unit_cost'],
                        'remaining_quantity' => $finalSOHToAdd
                    ]);

                    $batch->product_inventory_stock_managers()->create([
                        'product_inventory_id' => $targetSapMasterfile->id,
                        'store_branch_id' => $storeOrder->store_branch_id,
                        'quantity' => $finalSOHToAdd,
                        'action' => 'add_quantity',
                        'transaction_date' => Carbon::today()->format('Y-m-d'),
                        'unit_cost' =>  $data['unit_cost'],
                        'total_cost' => $data['total_cost'],
                        'remarks' => 'From newly received interco items. (Interco Number: ' . $storeOrder->interco_number . ')'
                    ]);
                }
            }

            // 3. Update individual history and order item records
            foreach ($pendingItems as $item) {
                $item->update([
                    'status' => 'approved',
                    'approval_action_by' => Auth::user()->id,
                    'received_date' => $item->received_date ?? Carbon::now('Asia/Manila'),
                ]);
                $item->store_order_item->quantity_received += $item->quantity_received;
                $item->store_order_item->save();
            }

            $this->updateFinalOrderStatus($order->id);

            DB::commit();

            return back()->with('success', 'Interco receive confirmed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
             Log::error("IntercoReceivingController: Error confirming receive for interco {$intercoNumber}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return back()->with('error', 'Failed to confirm receive: ' . $e->getMessage());
        }
    }

    public function attachImage(Request $request, $id)
    {
        $request->validate(['image' => 'required|image|mimes:jpeg,png,jpg|max:2048']);
        $order = StoreOrder::findOrFail($id);
        $user = Auth::user();
        $user->load('store_branches');
        if (!$user->store_branches->pluck('id')->contains($order->store_branch_id)) {
            abort(403, 'Unauthorized to attach images to this order.');
        }
        $file = $request->file('image');
        $path = Storage::disk('public')->putFile('order_attachments', $file);
        $order->image_attachments()->create([
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'is_approved' => true,
            'uploaded_by_user_id' => Auth::id(),
        ]);
        return back()->with('success', 'Image attached successfully.');
    }

    public function export(Request $request)
    {
        return response()->json([
            'message' => 'Export functionality to be implemented',
            'filters' => $request->only(['search', 'currentFilter'])
        ]);
    }

    public function updateReceiveDateHistory(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:ordered_item_receive_dates,id',
            'quantity_received' => 'required|numeric|min:0',
        ]);
        $history = OrderedItemReceiveDate::with('store_order_item.store_order')->findOrFail($validated['id']);
        $user = Auth::user();
        $user->load('store_branches');
        if (!$user->store_branches->pluck('id')->contains($history->store_order_item->store_order->store_branch_id)) {
            abort(403, 'Unauthorized action.');
        }
        $history->update(['quantity_received' => $validated['quantity_received']]);
        return redirect()->back();
    }

    private function getCounts($baseQuery)
    {
        $counts = [
            'received' => (clone $baseQuery)->where('interco_status', IntercoStatus::RECEIVED->value)->count(),
            'commited' => (clone $baseQuery)->where('interco_status', IntercoStatus::COMMITTED->value)->count(),
            'in_transit' => (clone $baseQuery)->where('interco_status', IntercoStatus::IN_TRANSIT->value)->count(),
        ];
        $counts['all'] = $counts['received'] + $counts['in_transit'];
        return $counts;
    }

    private function updateOrderStatus($order)
    {
        $items = $order->store_order_items;
        $totalCommited = $items->sum('quantity_commited');
        $totalReceived = $items->sum('quantity_received');
        if ($totalReceived >= $totalCommited) {
            $order->interco_status = IntercoStatus::RECEIVED->value;
        } else {
            $order->interco_status = IntercoStatus::IN_TRANSIT->value;
        }
        $order->save();
    }

    public function updateFinalOrderStatus($id)
    {
        $storeOrder = StoreOrder::find($id);
        $storeOrder->interco_status = IntercoStatus::RECEIVED->value;
        $storeOrder->save();
    }

    private function processReceivedItem($receiveDate)
    {
        // This method is now obsolete and replaced by the logic in confirmReceive.
    }

    private function processInventoryOutForInterco($storeOrder, $quantityToDeduct, $stockUpdateTarget, $unitCost, $totalCost): void
    {
        try {
            ProductInventoryStockManager::create([
                'product_inventory_id' => $stockUpdateTarget->id,
                'store_branch_id' => $storeOrder->sending_store_branch_id,
                'quantity' => $quantityToDeduct,
                'action' => 'out',
                'transaction_date' => Carbon::today()->format('Y-m-d'),
                'remarks' => "Interco transfer to {$storeOrder->store_branch->name} (Interco: {$storeOrder->interco_number})",
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost
            ]);

            ProductInventoryStock::create([
                'product_inventory_id' => $stockUpdateTarget->id,
                'store_branch_id' => $storeOrder->sending_store_branch_id,
                'quantity' => -$quantityToDeduct,
                'recently_added' => 0,
                'used' => $quantityToDeduct,
            ]);

            $deductedFromBatches = $quantityToDeduct;
            $sendingBatches = PurchaseItemBatch::where('product_inventory_id', $stockUpdateTarget->id)
                ->where('store_branch_id', $storeOrder->sending_store_branch_id)
                ->where('remaining_quantity', '>', 0)
                ->orderBy('purchase_date', 'asc')
                ->get();

            foreach ($sendingBatches as $batch) {
                if ($deductedFromBatches <= 0) break;
                $deductAmount = min($deductedFromBatches, $batch->remaining_quantity);
                $batch->remaining_quantity -= $deductAmount;
                $batch->save();
                $deductedFromBatches -= $deductAmount;
            }
        } catch (\Exception $e) {
            Log::error("IntercoReceivingController: Error processing inventory OUT for interco: " . $e->getMessage());
            throw $e;
        }
    }
}