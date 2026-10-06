<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Exports\ApprovedOrdersExport;
use App\Http\Requests\OrderReceiving\AddDeliveryReceiptNumberRequest;
use App\Http\Requests\OrderReceiving\AddUnlistedReceivedItemRequest;
use App\Http\Requests\OrderReceiving\ReceiveOrderRequest;
use App\Http\Requests\OrderReceiving\UpdateDeliveryReceiptNumberRequest;
use App\Http\Requests\OrderReceiving\UpdateReceiveDateHistoryRequest;
use App\Http\Services\OrderReceivingService;
use App\Models\DeliveryReceipt;
use App\Models\OrderedItemReceiveDate;
use App\Models\ProductInventoryStock;
use App\Models\ProductInventoryStockManager;
use App\Models\PurchaseItemBatch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Models\SAPMasterfile;
use App\Support\ItemStockUnit;
use App\Models\ImageAttachment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage; // Ensure Storage facade is used
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrderReceivingController extends Controller
{
    protected $orderReceivingService;

    public function __construct(OrderReceivingService $orderReceivingService)
    {
        $this->orderReceivingService = $orderReceivingService;
    }
    public function index()
    {
        $currentFilter = request('currentFilter') ?? 'all';
        $data = $this->orderReceivingService->getOrdersList($currentFilter);

        return Inertia::render('OrderReceiving/Index', [
            'orders' => $data['orders'],
            'filters' => request()->only([
                'search',
                'currentFilter',
                'order_number',
                'delivery_date_from',
                'delivery_date_to',
                'placed_date_from',
                'placed_date_to',
                'store_ids',
                'supplier_ids',
                'variants',
                'aging',
            ]),
            'counts' => $data['counts'],
            'filterOptions' => $this->orderReceivingService->getFilterOptions(),
        ]);
    }

    public function show($id)
    {
        set_time_limit(0);
        $order = $this->orderReceivingService->getOrderDetails($id);

        // Orders that reach receiving without passing through a commit (approved, partially
        // committed) have no worksheet rows yet, which left this page showing "No receiving
        // history available" with no way to receive anything. Idempotent.
        $this->orderReceivingService->ensureReceivingPlaceholders($order);

        // Fetch images directly from the relationship to ensure the accessor is called
        $images = $order->image_attachments()->get();

        $orderedItems = $this->orderReceivingService->getOrderItems($order);

        $orderedItemIds = $orderedItems->pluck('id');

        $receiveDatesHistory = OrderedItemReceiveDate::with([
            'store_order_item.supplierItem',
            'received_by_user',
            'approval_action_by_user'
        ])->whereIn('store_order_item_id', $orderedItemIds)
        ->get()->values();

        return Inertia::render('OrderReceiving/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'receiveDatesHistory' => $receiveDatesHistory,
            'images' => $images,
            // Set once Final Receive All locked the item list; the page then hides every
            // receiving action, on the same rule the server enforces.
            'receivingFinalized' => $order->receiving_finalized_at ? [
                'at' => $order->receiving_finalized_at->format('M j, Y g:i A'),
                'by' => $order->receivingFinalizedBy?->full_name,
            ] : null,
            // Last date the store closed with a final approved month end count; a receipt
            // cannot be dated on or before it.
            'closedThrough' => app(\App\Http\Services\MonthEndClosedPeriodService::class)->closedThroughFor((int) $order->store_branch_id),
            // The "item delivered but not ordered" picker: the order's own supplier list only.
            // Mapped to a lean shape on purpose: serialising the models would fire
            // SupplierItems' appended sap_master_file accessor once per row.
            'unlistedItemOptions' => \App\Models\SupplierItems::forSupplierCode((string) ($order->supplier?->supplier_code ?? ''))
                ->reject(fn ($item) => $orderedItems->contains('item_code', $item->ItemCode))
                // Cost is deliberately not exposed here: the picker does not show a price,
                // and the line's cost is read from the catalogue server-side when the item
                // is added (OrderReceivingService::addUnlistedItem).
                ->map(fn ($item) => [
                    'item_code' => $item->ItemCode,
                    'item_name' => $item->item_name,
                    'uom' => $item->uom,
                ])
                ->values(),
        ]);
    }

    public function export()
    {
        $currentFilter = request('currentFilter') ?? 'all';

        $filters = request()->only([
            'search',
            'order_number',
            'delivery_date_from',
            'delivery_date_to',
            'placed_date_from',
            'placed_date_to',
            'store_ids',
            'supplier_ids',
            'variants',
            'aging',
        ]);

        return Excel::download(
            new ApprovedOrdersExport($filters, $currentFilter, $this->orderReceivingService),
            'approved-orders-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function addUnlistedItem(AddUnlistedReceivedItemRequest $request, StoreOrder $order)
    {
        try {
            $this->orderReceivingService->addUnlistedItem($order, $request->validated());
        } catch (\Exception $e) {
            Log::warning("OrderReceivingController: Unlisted item rejected for order {$order->order_number}: ".$e->getMessage());

            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Item added to the receiving history.');
    }

    public function receive(ReceiveOrderRequest $request, $id)
    {
        $order = StoreOrderItem::findOrFail($id)->store_order;

        if ($problem = $this->orderReceivingService->receivingLockedProblem($order)
            ?? $this->orderReceivingService->deliveryEvidenceProblem($order)) {
            return back()->withErrors(['error' => $problem]);
        }

        $this->orderReceivingService->receiveOrder($id, $request->validated());

        return redirect()->back();
    }

    public function addDeliveryReceiptNumber(AddDeliveryReceiptNumberRequest $request)
    {
        $validated = $request->validated();
        DeliveryReceipt::create([
            'delivery_receipt_number' => $validated['delivery_receipt_number'],
            'sap_so_number' => $validated['sap_so_number'],
            'store_order_id' => $validated['store_order_id'],
            'remarks' => $validated['remarks'],
        ]);
        return redirect()->back();
    }

    public function updateDeliveryReceiptNumber(UpdateDeliveryReceiptNumberRequest $request, $id)
    {
        $validated = $request->validated();
        $id = $validated['id'];
        unset($validated['id']);
        $receipt = DeliveryReceipt::findOrFail($id);
        $receipt->update($validated);
        return redirect()->back();
    }

    public function destroyDeliveryReceiptNumber($id)
    {
        $receipt = DeliveryReceipt::findOrFail($id);
        $receipt->delete();
        return redirect()->back();
    }

    public function deleteReceiveDateHistory($id)
    {
        $history = OrderedItemReceiveDate::with('store_order_item.store_order')->findOrFail($id);

        if ($order = $history->store_order_item?->store_order) {
            if ($problem = $this->orderReceivingService->receivingLockedProblem($order)) {
                return back()->withErrors(['error' => $problem]);
            }
        }

        DB::beginTransaction();
        $history->delete();
        DB::commit();
        return redirect()->back();
    }

    public function updateReceiveDateHistory(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:ordered_item_receive_dates,id',
            'quantity_received' => 'required|numeric|min:0',
            'remarks' => 'nullable|string',
        ]);

        $history = OrderedItemReceiveDate::with('store_order_item.store_order')->findOrFail($validated['id']);

        // Recording a quantity here marks the line received, exactly as Confirm Receive does.
        // Without this check a user could work down the list with the edit button and receive
        // the whole order without ever attaching a delivery receipt or an image.
        $order = $history->store_order_item?->store_order;

        if ($order && $problem = $this->orderReceivingService->receivingLockedProblem($order)
            ?? $this->orderReceivingService->deliveryEvidenceProblem($order)) {
            return back()->withErrors(['error' => $problem]);
        }

        $history->update([
            'quantity_received' => $validated['quantity_received'],
            'remarks' => $validated['remarks'],
            'received_date' => now('Asia/Manila'),
            'status' => 'received',
            'received_by_user_id' => Auth::id(),
        ]);

        return redirect()->back();
    }

    public function attachImage(Request $request, StoreOrder $order)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg|max:2048', // 2MB Max
        ]);

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            
            // MODIFIED: Store the file directly to the 'public' disk, which now points to public/uploads
            $path = Storage::disk('public')->putFile('order_attachments', $file);

            // Create a record in the database
            $order->image_attachments()->create([
                'file_path' => $path, // This will be 'order_attachments/filename.jpg' relative to public/uploads
                'mime_type' => $file->getMimeType(),
                'is_approved' => true, // Defaulting to true
                'uploaded_by_user_id' => Auth::id(),
            ]);
        }

        return redirect()->back()->with('success', 'Image uploaded successfully.');
    }


    /**
     * Zero All: record every receipt not posted yet as unserved (quantity 0). For a delivery
     * that never arrived, so it asks for no delivery receipt and no image. Nothing is posted:
     * Final Receive All still follows.
     */
    public function zeroAll($id)
    {
        $order = StoreOrder::findOrFail($id);

        try {
            $zeroed = $this->orderReceivingService->zeroUnconfirmedReceipts($order);
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return $zeroed === 0
            ? back()->with('info', 'No items left to set to 0.')
            : back()->with('success', "{$zeroed} item(s) set to 0 and marked Unserved.");
    }

    /**
     * Confirm Receive All: accept every receipt not in stock yet as received (an item nobody
     * filled in, at its committed quantity) and mark the order received. It posts nothing:
     * stock on hand moves only on Final Receive All. The item list stays open afterwards, so
     * an item found later can still be added and every quantity corrected.
     */
    public function confirmReceive($id)
    {
        $order = StoreOrder::findOrFail($id);

        if ($problem = $this->orderReceivingService->receivingLockedProblem($order)
            ?? $this->orderReceivingService->postingEvidenceProblem($order)) {
            return back()->withErrors(['error' => $problem]);
        }

        DB::beginTransaction();
        try {
            $confirmed = $this->orderReceivingService->confirmUnpostedReceipts($order);

            // Re-evaluated even when nothing was confirmed, in case a previous attempt left
            // the order 'incomplete'.
            $this->orderReceivingService->getOrderStatus($id);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("OrderReceivingController: Error confirming receive for order ID {$id}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return back()->with('error', 'Failed to confirm receive. Check logs for details.');
        }

        return $confirmed === 0 ? back()->with('info', 'No pending items to confirm.') : back();
    }

    /**
     * Final Receive All: the one action that posts to stock. It posts every receipt not in
     * stock yet, at the quantity on its row, then locks the order's item list for good.
     * Nothing can be added, edited or received on it afterwards
     * (OrderReceivingService::receivingLockedProblem).
     */
    public function finalReceive($id)
    {
        $order = StoreOrder::findOrFail($id);

        if ($problem = $this->orderReceivingService->receivingLockedProblem($order)
            ?? $this->orderReceivingService->postingEvidenceProblem($order)) {
            return back()->withErrors(['error' => $problem]);
        }

        DB::beginTransaction();
        try {
            // Stamped first and only while still open, so two receivers clicking at once
            // cannot both post the same receipts.
            $stamped = StoreOrder::whereKey($id)
                ->whereNull('receiving_finalized_at')
                ->update([
                    'receiving_finalized_at' => Carbon::now('Asia/Manila')->format('Y-m-d H:i:s'),
                    'receiving_finalized_by' => Auth::id(),
                ]);

            if ($stamped === 0) {
                DB::rollBack();

                return back()->withErrors(['error' => 'This delivery was already finalized.']);
            }

            $this->postReceiptsToStock($id);
            $this->orderReceivingService->getOrderStatus($id);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("OrderReceivingController: Error finalizing receive for order ID {$id}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return back()->withErrors(['error' => 'Failed to finalize receiving. Nothing was changed. Check logs for details.']);
        }

        return back()->with('success', 'Receiving finalized. This delivery is now locked.');
    }

    /**
     * Post every pending or received (not yet approved) receipt of the order to stock and
     * mark it approved. Only Final Receive All calls it: nothing else on this page moves
     * stock on hand. Runs inside the caller's transaction.
     *
     * @return int how many receipts were posted
     */
    private function postReceiptsToStock($id): int
    {
        // 1. Update remarks for any ALREADY APPROVED items that have no remarks (Fix for data consistency)
        OrderedItemReceiveDate::whereHas('store_order_item.store_order', fn ($q) => $q->where('id', $id))
            ->where('status', 'approved')
            ->where(function ($q) {
                $q->whereNull('remarks')->orWhere('remarks', '');
            })
            ->update(['remarks' => 'Received']);

        $historyItems = OrderedItemReceiveDate::with([
            'store_order_item.store_order',
            'store_order_item.supplierItem'
        ])
        ->whereHas('store_order_item.store_order', fn ($q) => $q->where('id', $id))
        ->whereIn('status', ['pending', 'received'])
        ->get();

        if ($historyItems->isEmpty()) {
            return 0;
        }

        $aggregatedData = [];

        // 1. Aggregate quantities in BASE UOM
        foreach ($historyItems as $history) {
            // The line's own item code, not its supplier item's: a line added from the SAP
            // Masterlist has no supplier item and must still reach stock.
            $itemCode = optional($history->store_order_item)->item_code;
            $uom = optional($history->store_order_item)->uom;

            if (!$itemCode || !$uom) {
                Log::warning("OrderReceivingController: Skipping history item ID {$history->id} due to incomplete data (ItemCode or UOM missing).");
                continue;
            }

            // Stock lives on the item's SAP base-unit row; the received unit converts into it.
            $stockUnit = ItemStockUnit::forItem($itemCode);
            $targetSapMasterfile = $stockUnit->stockRowFor($uom);
            $conversionFactor = $stockUnit->factor($uom);

            if (!$targetSapMasterfile || !$conversionFactor) {
                Log::warning("OrderReceivingController: No SAP conversion from '{$uom}' to the stock unit of '{$itemCode}'. Skipping history item ID {$history->id}.");
                continue;
            }

            $quantityInBaseUom = $history->quantity_received * $conversionFactor;
            $costInBaseUom = $history->store_order_item->cost_per_quantity / $conversionFactor;

            // Aggregate data by the target SOH item's ID
            $targetId = $targetSapMasterfile->id;
            if (!isset($aggregatedData[$targetId])) {
                $aggregatedData[$targetId] = [
                    'total_base_qty' => 0,
                    'total_cost' => 0,
                    'unit_cost' => $costInBaseUom, // Base cost per base UOM
                    'target_masterfile' => $targetSapMasterfile,
                    'store_order' => $history->store_order_item->store_order,
                    'store_order_item' => $history->store_order_item, // Pass for context
                ];
            }
            $aggregatedData[$targetId]['total_base_qty'] += $quantityInBaseUom;
            $aggregatedData[$targetId]['total_cost'] += $history->quantity_received * $history->store_order_item->cost_per_quantity;
        }

        // 2. Process aggregated data
        foreach ($aggregatedData as $data) {
            $finalSOHToAdd = $data['total_base_qty'];
            $storeOrder = $data['store_order'];
            $targetSapMasterfile = $data['target_masterfile'];

            if ($storeOrder->isInterco()) {
                $this->processInventoryOutForInterco($storeOrder, $finalSOHToAdd, $targetSapMasterfile);
            }

            $stock = ProductInventoryStock::firstOrNew([
                'product_inventory_id' => $targetSapMasterfile->id,
                'store_branch_id' => $storeOrder->store_branch_id
            ]);
            $stock->quantity += $finalSOHToAdd;
            $stock->recently_added = ($stock->recently_added ?? 0) + $finalSOHToAdd;
            $stock->save();

            $batch = PurchaseItemBatch::create([
                'store_order_item_id' => $data['store_order_item']->id,
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
                'unit_cost' => $data['unit_cost'],
                'total_cost' => $data['total_cost'],
                'remarks' => 'From newly received items. (Order Number: ' . $storeOrder->order_number . ')'
            ]);
        }

        // 3. Update individual history and order item records
        foreach ($historyItems as $history) {
            $updateData = [
                'status' => 'approved',
                'approval_action_by' => Auth::id(),
                'received_date' => $history->received_date ?? Carbon::now('Asia/Manila'),
                'received_by_user_id' => Auth::id(),
            ];

            if (is_null($history->remarks) || trim($history->remarks) === '') {
                $updateData['remarks'] = 'Received';
            }

            $history->update($updateData);
            $history->store_order_item->quantity_received += $history->quantity_received;
            $history->store_order_item->save();
        }

        return $historyItems->count();
    }
}
