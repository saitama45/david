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
use App\Models\SAPMasterfile;
use App\Http\Services\IntercoReceivingService;
use App\Http\Services\OrderReceivingService;
use App\Support\ItemStockUnit;
use Inertia\Inertia;

class IntercoReceivingController extends Controller
{
    /** Still to receive: an approved transfer needs no commit from the sending store first. */
    private const TO_RECEIVE = ['approved', 'committed', 'in_transit'];

    public function __construct(private IntercoReceivingService $intercoReceiving)
    {
    }

    /**
     * Display a listing of interco orders for receiving.
     */
    public function index(Request $request)
    {
        $currentFilter = $request->get('currentFilter', 'in_transit');
        $search = $request->get('search', '');

        $baseQuery = StoreOrder::whereNotNull('interco_number')
            ->whereNotNull('sending_store_branch_id')
            ->whereIn('interco_status', [...self::TO_RECEIVE, IntercoStatus::RECEIVED->value]);

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
                $query->whereIn('interco_status', self::TO_RECEIVE);
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

        // Without a commit nothing else gives an approved transfer its lines to receive.
        $this->intercoReceiving->ensureLines($order);

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
            'approval_action_by_user',
            'sender_decided_by_user',
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
            // Set once every line is in stock (by Final Receive All, or the sending store's
            // confirmation of the last line) and the transfer is locked; the page then hides
            // every receiving action, on the same rule the server enforces.
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

        if ($problem = $this->intercoReceiving->receivingProblem($order)) {
            return back()->withErrors(['error' => $problem]);
        }

        if ($request->quantity_received > $storeOrderItem->quantity_commited) {
            return back()->with('error', 'Received quantity cannot exceed committed quantity.');
        }

        DB::beginTransaction();
        try {
            // Recorded only: Final Receive All posts it (and adds it to the line's received
            // quantity), or sends it to the sending store when the line differs from what was sent.
            OrderedItemReceiveDate::create([
                'store_order_item_id' => $storeOrderItem->id,
                'quantity_received' => $request->quantity_received,
                'received_date' => $request->received_date,
                'expiry_date' => $request->expiry_date,
                'remarks' => $request->remarks,
                'received_by_user_id' => Auth::user()->id,
                'status' => 'received'
            ]);

            $this->intercoReceiving->startReceiving($order);

            DB::commit();

            return back()->with('success', 'Items received. Click Final Receive All to move the stock.');
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
     * Zero All: record every receipt the store still holds as nothing received (quantity 0,
     * remarks "Unserved"). For a transfer that did not arrive, so it asks for no image. Nothing
     * is posted: Final Receive All still follows, and sends the lines to the sending store to
     * confirm, as every line that differs from what was sent.
     */
    public function zeroAll($intercoNumber)
    {
        $order = $this->transferOfReceivingStore($intercoNumber);

        if ($problem = $this->intercoReceiving->receivingProblem($order)) {
            return back()->withErrors(['error' => $problem]);
        }

        try {
            $zeroed = DB::transaction(function () use ($order) {
                $this->intercoReceiving->ensureLines($order);
                $zeroed = app(OrderReceivingService::class)->zeroUnconfirmedReceipts($order);
                $this->intercoReceiving->startReceiving($order);

                return $zeroed;
            });
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return $zeroed === 0
            ? back()->with('info', 'No items left to set to 0.')
            : back()->with('success', "{$zeroed} item(s) set to 0 and marked Unserved.");
    }

    /**
     * Confirm Receive All: accept every receipt the store still holds as received, at the
     * quantity on its row. It posts nothing: stock moves between the two stores only on Final
     * Receive All, and every quantity stays open to correction until then.
     */
    public function confirmReceive($intercoNumber)
    {
        $order = $this->transferOfReceivingStore($intercoNumber);

        if ($problem = $this->intercoReceiving->receivingProblem($order) ?? $this->evidenceProblem($order)) {
            return back()->withErrors(['error' => $problem]);
        }

        $confirmed = DB::transaction(function () use ($order) {
            $this->intercoReceiving->ensureLines($order);
            $confirmed = app(OrderReceivingService::class)->confirmUnpostedReceipts($order);
            $this->intercoReceiving->startReceiving($order);

            return $confirmed;
        });

        return $confirmed === 0
            ? back()->with('info', 'No pending items to confirm.')
            : back()->with('success', 'Received quantities confirmed. Click Final Receive All to move the stock.');
    }

    /**
     * Final Receive All: the receiving store's one action that moves stock. Every line received
     * as sent is posted - out of the sending store, into this one. A line received short or
     * over is not: it waits for the sending store to confirm it, and the button stays until
     * every line is in stock. The transfer is then received and locked for good.
     */
    public function finalReceive($intercoNumber)
    {
        $order = $this->transferOfReceivingStore($intercoNumber);

        if ($problem = $this->evidenceProblem($order)) {
            return back()->withErrors(['error' => $problem]);
        }

        try {
            $result = $this->intercoReceiving->finalReceive($order);
        } catch (\Exception $e) {
            Log::error("IntercoReceivingController: Error finalizing receive for interco {$intercoNumber}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return back()->withErrors(['error' => 'Failed to finalize receiving. Nothing was changed. ' . $e->getMessage()]);
        }

        $sender = $order->sendingStore?->name ?? 'the sending store';

        if ($result['posted'] === 0 && $result['for_confirmation'] === 0) {
            return back()->with('info', "Nothing to finalize: every item not in stock yet is waiting for {$sender} to confirm it.");
        }

        if ($result['finalized']) {
            return back()->with('success', 'Receiving finalized. The stock is moved and this transfer is now locked.');
        }

        $message = $result['posted'] > 0 ? "{$result['posted']} item(s) received as sent are now in stock. " : '';
        if ($result['for_confirmation'] > 0) {
            $message .= "{$result['for_confirmation']} item(s) differ from what was sent and wait for {$sender} to confirm the received quantity.";
        }

        return back()->with('success', trim($message));
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

        $order = $history->store_order_item->store_order;

        // Nothing changes on a locked transfer, nor on a posted receipt: its quantity is
        // already in stock. A receipt with the sending store waits for its answer.
        if ($problem = $this->intercoReceiving->receivingProblem($order)) {
            return back()->withErrors(['error' => $problem]);
        }

        if ($history->status === 'approved') {
            return back()->withErrors(['error' => 'This receipt is already in stock and can no longer be changed.']);
        }

        if ($history->status === IntercoReceivingService::FOR_CONFIRMATION) {
            $sender = $order->sendingStore?->name ?? 'the sending store';

            return back()->withErrors(['error' => "This item is waiting for {$sender} to confirm the received quantity. It can be changed again if {$sender} re-sends it."]);
        }

        $this->intercoReceiving->startReceiving($order);

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
            'in_transit' => (clone $baseQuery)->whereIn('interco_status', self::TO_RECEIVE)->count(),
        ];
        $counts['all'] = $counts['received'] + $counts['in_transit'];
        return $counts;
    }

    private function processReceivedItem($receiveDate)
    {
        // This method is now obsolete and replaced by the logic in confirmReceive.
    }
}
