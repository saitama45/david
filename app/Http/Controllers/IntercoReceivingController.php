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
use App\Http\Services\OrderReceivingService;
use App\Support\ItemStockUnit;
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

        // Load supplierItem relationship, but we'll process it manually
        $orderedItems = StoreOrderItem::where('store_order_id', $order->id)
            ->with('supplierItem')
            ->get();

        // Manually attach details to avoid deep nesting and null issues on the frontend
        $orderedItems->each(function ($item) use ($order) {
            $item->soh_stock = 0;
            // Use supplierItem's ItemCode or fall back to the one on the order item itself
            $itemCode = $item->supplierItem->ItemCode ?? $item->item_code;

            if ($itemCode) {
                // Find the master file based on the determined item code
                $sapMasterfile = ItemStockUnit::forItem($itemCode)->stockRowFor($item->uom) ?? SAPMasterfile::where('ItemCode', $itemCode)->first();

                if ($sapMasterfile) {
                    // Flatten the structure for the frontend
                    $item->ItemCode = $sapMasterfile->ItemCode;
                    $item->item_name = $sapMasterfile->ItemDescription; // Match `item_name` used in OrderReceiving
                    $item->BaseUOM = $sapMasterfile->BaseUOM;
                    
                    // Attach for SOH calculation
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
        
        // Manually attach flattened data for history items too
        $receiveDatesHistory->each(function ($history) {
            if ($history->store_order_item) {
                $itemCode = $history->store_order_item->supplierItem->ItemCode ?? $history->store_order_item->item_code;
                if ($itemCode) {
                    $sapMasterfile = ItemStockUnit::forItem($itemCode)->stockRowFor($history->store_order_item->uom) ?? SAPMasterfile::where('ItemCode', $itemCode)->first();
                    if ($sapMasterfile) {
                        // Attach flattened data directly to the history's store_order_item
                        $history->store_order_item->ItemCode = $sapMasterfile->ItemCode;
                        $history->store_order_item->item_name = $sapMasterfile->ItemDescription;
                    }
                }
            }
        });

        $images = $order->image_attachments()->get();

        return Inertia::render('IntercoReceiving/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'receiveDatesHistory' => $receiveDatesHistory,
            'images' => $images,
            // Set once Final Receive All moved the stock and locked the transfer; the page
            // then hides every receiving action, on the same rule the server enforces.
            'receivingFinalized' => $order->receiving_finalized_at ? [
                'at' => $order->receiving_finalized_at->format('M j, Y g:i A'),
                'by' => $order->receivingFinalizedBy?->full_name,
            ] : null,
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

        if ($problem = app(OrderReceivingService::class)->receivingLockedProblem($order)) {
            return back()->withErrors(['error' => $problem]);
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
     * The transfer a receiving action is for, refused unless it goes to a store the user is
     * assigned to.
     */
    private function transferOfReceivingStore($intercoNumber): StoreOrder
    {
        $order = StoreOrder::where('interco_number', $intercoNumber)->firstOrFail();

        $user = Auth::user();
        $user->load('store_branches');
        if (!$user->store_branches->pluck('id')->contains($order->store_branch_id)) {
            abort(403, 'Unauthorized to receive this interco order.');
        }

        return $order;
    }

    /**
     * Why Confirm Receive All may not accept, and Final Receive All may not post, this
     * transfer yet, or null when they may.
     *
     * The image proves the transfer arrived. A transfer whose every receipt is zero (Zero
     * All, or each line saved with 0) did not arrive, so there is nothing to attach. One
     * receipt above zero brings the requirement back: a row nobody touched still carries its
     * committed quantity.
     */
    private function evidenceProblem(StoreOrder $order): ?string
    {
        $receipts = OrderedItemReceiveDate::whereHas('store_order_item', fn ($q) => $q->where('store_order_id', $order->id));

        $nothingReceived = (clone $receipts)->exists()
            && ! (clone $receipts)->where('quantity_received', '<>', 0)->exists();

        if ($nothingReceived || $order->image_attachments()->count() > 0) {
            return null;
        }

        return 'Attach an image to this transfer before confirming or finalizing its receipt.';
    }

    /**
     * Zero All: record every receipt not posted yet as nothing received (quantity 0, remarks
     * "Unserved"). For a transfer that did not arrive, so it asks for no image. Nothing is
     * posted and the transfer stays open: Final Receive All still follows.
     */
    public function zeroAll($intercoNumber)
    {
        $order = $this->transferOfReceivingStore($intercoNumber);

        try {
            $zeroed = app(OrderReceivingService::class)->zeroUnconfirmedReceipts($order);
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return $zeroed === 0
            ? back()->with('info', 'No items left to set to 0.')
            : back()->with('success', "{$zeroed} item(s) set to 0 and marked Unserved.");
    }

    /**
     * Confirm Receive All: accept every receipt not in stock yet as received, at the quantity
     * on its row. It posts nothing: stock moves between the two stores only on Final Receive
     * All, and the transfer stays in transit, with every quantity still open to correction,
     * until then.
     */
    public function confirmReceive($intercoNumber)
    {
        $order = $this->transferOfReceivingStore($intercoNumber);
        $receiving = app(OrderReceivingService::class);

        if ($problem = $receiving->receivingLockedProblem($order) ?? $this->evidenceProblem($order)) {
            return back()->withErrors(['error' => $problem]);
        }

        $confirmed = DB::transaction(fn () => $receiving->confirmUnpostedReceipts($order));

        return $confirmed === 0
            ? back()->with('info', 'No pending items to confirm.')
            : back()->with('success', 'Received quantities confirmed. Click Final Receive All to move the stock.');
    }

    /**
     * Final Receive All: the one action that moves stock. It posts every receipt not in stock
     * yet - out of the sending store, into the receiving store - marks the transfer received
     * and locks it for good.
     */
    public function finalReceive($intercoNumber)
    {
        $order = $this->transferOfReceivingStore($intercoNumber);
        $receiving = app(OrderReceivingService::class);

        if ($problem = $receiving->receivingLockedProblem($order) ?? $this->evidenceProblem($order)) {
            return back()->withErrors(['error' => $problem]);
        }

        DB::beginTransaction();
        try {
            // Stamped first and only while still open, so two receivers clicking at once
            // cannot both post the same receipts.
            $stamped = StoreOrder::whereKey($order->id)
                ->whereNull('receiving_finalized_at')
                ->update([
                    'receiving_finalized_at' => Carbon::now('Asia/Manila')->format('Y-m-d H:i:s'),
                    'receiving_finalized_by' => Auth::id(),
                ]);

            if ($stamped === 0) {
                DB::rollBack();

                return back()->withErrors(['error' => 'This transfer was already finalized.']);
            }

            $this->postReceiptsToStock($order);
            $this->updateFinalOrderStatus($order->id);

            DB::commit();

            return back()->with('success', 'Receiving finalized. The stock is moved and this transfer is now locked.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("IntercoReceivingController: Error finalizing receive for interco {$intercoNumber}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return back()->withErrors(['error' => 'Failed to finalize receiving. Nothing was changed. ' . $e->getMessage()]);
        }
    }

    /**
     * Post every receipt of the transfer that is not in stock yet (pending or received) and
     * mark it approved: out of the sending store, into the receiving store. Only Final
     * Receive All calls it. Runs inside the caller's transaction.
     *
     * @return int how many receipts were posted
     */
    private function postReceiptsToStock(StoreOrder $order): int
    {
        $pendingItems = OrderedItemReceiveDate::with('store_order_item')
            ->whereHas('store_order_item', function ($query) use ($order) {
            $query->where('store_order_id', $order->id);
        })->whereIn('status', ['pending', 'received'])->get();

        if ($pendingItems->isEmpty()) {
            return 0;
        }

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

            // Stock lives on the item's SAP base-unit row; the received unit converts into it.
            $stockUnit = ItemStockUnit::forItem($itemCode);
            $stockUpdateTarget = $stockUnit->stockRowFor($uom);
            $conversionFactor = $stockUnit->factor($uom);

            if (!$stockUpdateTarget || !$conversionFactor) {
                Log::warning("IntercoReceivingController: No SAP conversion from '{$uom}' to the stock unit of '{$itemCode}'. Skipping history item ID {$history->id}.");
                continue;
            }

            $quantityInBaseUom = $history->quantity_received * $conversionFactor;
            $costInBaseUom = $storeOrderItem->cost_per_quantity / $conversionFactor;

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

        return $pendingItems->count();
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

        // Nothing changes on a transfer locked by Final Receive All, nor on a posted receipt:
        // its quantity is already in stock.
        if ($problem = app(OrderReceivingService::class)->receivingLockedProblem($history->store_order_item->store_order)) {
            return back()->withErrors(['error' => $problem]);
        }

        if ($history->status === 'approved') {
            return back()->withErrors(['error' => 'This receipt is already in stock and can no longer be changed.']);
        }

        // Recording a quantity marks the line received, by the user and at the time it is
        // recorded, as on Inbound Orders. Nothing is posted until Final Receive All.
        $history->update([
            'quantity_received' => $validated['quantity_received'],
            'received_date' => now('Asia/Manila'),
            'status' => 'received',
            'received_by_user_id' => Auth::id(),
        ]);

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