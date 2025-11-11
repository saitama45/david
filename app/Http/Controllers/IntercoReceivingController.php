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

        // Base query for interco orders only - include more statuses for receiving
        $baseQuery = StoreOrder::whereNotNull('interco_number')
            ->whereNotNull('sending_store_branch_id')
            ->whereIn('interco_status', [
                IntercoStatus::IN_TRANSIT->value,
                IntercoStatus::RECEIVED->value,
            ]);

        // Apply user-specific branch filtering
        $user = Auth::user();
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');

        if ($assignedStoreIds->isNotEmpty()) {
            $baseQuery->whereIn('store_branch_id', $assignedStoreIds);
        } else {
            // If no stores are assigned, return no results.
            $baseQuery->whereRaw('1 = 0');
        }

        // Apply search filter
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

        // Apply status filter
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
                // No additional filtering needed for 'all'
                break;
        }

        // Get orders with relationships including store_order_items
        $orders = $query->with(['store_branch', 'sendingStore', 'encoder', 'store_order_items.supplierItem.sapMasterfile'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        // Calculate counts
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

        // Check user permissions based on assigned store branches
        $user = Auth::user();
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');

        if (!$assignedStoreIds->contains($order->store_branch_id)) {
            abort(403, 'Unauthorized access to this interco order.');
        }

        // Get ordered items with SAP master file and calculate SOH stock
        $orderedItems = StoreOrderItem::where('store_order_id', $order->id)
            ->with('sapMasterfile')
            ->get();

        $orderedItems->each(function ($item) use ($order) {
            if ($item->sapMasterfile) {
                $stock = ProductInventoryStock::where('product_inventory_id', $item->sapMasterfile->id)
                    ->where('store_branch_id', $order->store_branch_id)
                    ->first();
                $item->soh_stock = $stock ? $stock->quantity : 0;
            } else {
                $item->soh_stock = 0;
            }
        });

        // Get receiving history
        $receiveDatesHistory = OrderedItemReceiveDate::with([
            'store_order_item.sapMasterfile',
            'received_by_user',
            'approval_action_by_user'
        ])->whereHas('store_order_item', function ($query) use ($order) {
            $query->where('store_order_id', $order->id);
        })->get();

        // Get image attachments
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

        // Validate that this is an interco order
        if (!$order->isInterco()) {
            return back()->with('error', 'This is not an interco order.');
        }

        // Validate user permissions
        $user = Auth::user();
        $user->load('store_branches');
        if (!$user->store_branches->pluck('id')->contains($order->store_branch_id)) {
            abort(403, 'Unauthorized to receive items for this order.');
        }

        // Validate quantity doesn't exceed committed quantity
        if ($request->quantity_received > $storeOrderItem->quantity_commited) {
            return back()->with('error', 'Received quantity cannot exceed committed quantity.');
        }

        DB::beginTransaction();
        try {
            // Create receiving history record
            OrderedItemReceiveDate::create([
                'store_order_item_id' => $storeOrderItem->id,
                'quantity_received' => $request->quantity_received,
                'received_date' => $request->received_date,
                'expiry_date' => $request->expiry_date,
                'remarks' => $request->remarks,
                'received_by_user_id' => Auth::user()->id,
                'status' => 'pending' // Pending approval
            ]);

            // Update received quantity
            $storeOrderItem->quantity_received += $request->quantity_received;
            $storeOrderItem->save();

            // Update order status
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

        // Validate permissions
        $user = Auth::user();
        $user->load('store_branches');
        if (!$user->store_branches->pluck('id')->contains($order->store_branch_id)) {
            abort(403, 'Unauthorized to confirm receive for this order.');
        }

        // Get pending receive history items
        $pendingItems = OrderedItemReceiveDate::with('store_order_item.sapMasterfile')
            ->whereHas('store_order_item', function ($query) use ($order) {
            $query->where('store_order_id', $order->id);
        })->where('status', 'pending')->get();

        if ($pendingItems->isEmpty()) {
            return back()->with('error', 'No pending items to confirm.');
        }

        DB::beginTransaction();
        try {
            foreach ($pendingItems as $item) {
                try {
                    $this->processReceivedItem($item);
                } catch (\Exception $itemException) {
                    // Log individual item failure but continue with other items
                    Log::error("Failed to process item {$item->id}: " . $itemException->getMessage());
                    // Continue to next item instead of breaking entire transaction
                }
            }

            // Update final order status
            $this->updateFinalOrderStatus($order->id);

            DB::commit();

            return back()->with('success', 'Interco receive confirmed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to confirm receive: ' . $e->getMessage());
        }
    }

    /**
     * Attach image to interco order.
     */
    public function attachImage(Request $request, $id)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        $order = StoreOrder::findOrFail($id);

        // Validate permissions
        $user = Auth::user();
        $user->load('store_branches');
        if (!$user->store_branches->pluck('id')->contains($order->store_branch_id)) {
            abort(403, 'Unauthorized to attach images to this order.');
        }

        $file = $request->file('image');
        $path = Storage::disk('public')->putFile('order_attachments', $file);

        // Create a record in the database using the relationship
        $order->image_attachments()->create([
            'file_path' => $path, // This will be 'order_attachments/filename.jpg' relative to public/uploads
            'mime_type' => $file->getMimeType(),
            'is_approved' => true, // Defaulting to true
            'uploaded_by_user_id' => Auth::id(),
        ]);

        return back()->with('success', 'Image attached successfully.');
    }

    /**
     * Export interco receiving data.
     */
    public function export(Request $request)
    {
        // This would typically use Laravel Excel
        // For now, return a simple response
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

        // Authorization
        $user = Auth::user();
        $user->load('store_branches');
        if (!$user->store_branches->pluck('id')->contains($history->store_order_item->store_order->store_branch_id)) {
            abort(403, 'Unauthorized action.');
        }

        $history->update([
            'quantity_received' => $validated['quantity_received']
        ]);

        return redirect()->back();
    }

    /**
     * Calculate counts for each status.
     */
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

    /**
     * Update order status based on received quantities.
     */
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

    private function processInventoryOutForInterco($storeOrder, $data, $sapMasterfile): void
    {
        try {
            Log::info("IntercoReceivingController: Processing inventory OUT for interco order {$storeOrder->interco_number}, item {$sapMasterfile->item_code}, quantity {$data->quantity_received}");

            // Get sending store stock
            $sendingStock = ProductInventoryStock::where('product_inventory_id', $sapMasterfile->id)
                ->where('store_branch_id', $storeOrder->sending_store_branch_id)
                ->first();

            if (!$sendingStock) {
                Log::error("IntercoReceivingController: No stock record found in sending store for item {$sapMasterfile->item_code}");
                throw new \Exception("No stock record found in sending store for item: {$sapMasterfile->item_code}");
            }

            // Check if sending store has sufficient stock
            if ($sendingStock->quantity < $data->quantity_received) {
                $available = $sendingStock->quantity;
                $requested = $data->quantity_received;
                Log::error("IntercoReceivingController: Insufficient stock in sending store. Available: {$available}, Requested: {$requested}");
                throw new \Exception("Insufficient stock in sending store for item {$sapMasterfile->item_code}. Available: {$available}, Requested: {$requested}");
            }

            // Create inventory OUT record for sending store
            ProductInventoryStockManager::create([
                'product_inventory_id' => $sapMasterfile->id,
                'store_branch_id' => $storeOrder->sending_store_branch_id,
                'quantity' => $data->quantity_received,
                'action' => 'out',
                'transaction_date' => Carbon::today()->format('Y-m-d'),
                'remarks' => "Interco transfer to {$storeOrder->store_branch->name} (Interco: {$storeOrder->interco_number})",
                'unit_cost' => $data->store_order_item->cost_per_quantity,
                'total_cost' => $data->quantity_received * $data->store_order_item->cost_per_quantity,
            ]);

            // ALWAYS INSERT new record for sending store - no existence checks
            ProductInventoryStock::create([
                'product_inventory_id' => $sapMasterfile->id,
                'store_branch_id' => $storeOrder->sending_store_branch_id,
                'quantity' => -$data->quantity_received, // Negative for OUT
                'recently_added' => -$data->quantity_received, // Negative for OUT
                'used' => 0,
                'created_at' => Carbon::now('Asia/Manila'),
                'updated_at' => Carbon::now('Asia/Manila')
            ]);

            // Update PurchaseItemBatch for sending store
            $sendingBatch = PurchaseItemBatch::where('product_inventory_id', $sapMasterfile->id)
                ->where('store_branch_id', $storeOrder->sending_store_branch_id)
                ->where('remaining_quantity', '>', 0)
                ->orderBy('purchase_date', 'asc')
                ->first();

            if ($sendingBatch) {
                $quantityToDeduct = min($data->quantity_received, $sendingBatch->remaining_quantity);
                $sendingBatch->remaining_quantity -= $quantityToDeduct;
                $sendingBatch->save();
            }

        } catch (\Exception $e) {
            Log::error("IntercoReceivingController: Error processing inventory OUT for interco: " . $e->getMessage());
            throw $e;
        }
    }

    public function updateFinalOrderStatus($id)
    {
        $storeOrder = StoreOrder::find($id);
        $storeOrder->interco_status = IntercoStatus::RECEIVED->value;
        $storeOrder->save();
    }

    /**
     * Process a received item (update inventory).
     */
    private function processReceivedItem($receiveDate)
    {
        $updateData = [
            'status' => 'approved',
            'approval_action_by' => Auth::user()->id,
        ];

        if (is_null($receiveDate->received_date)) {
            $updateData['received_date'] = Carbon::now('Asia/Manila');
        }

        $receiveDate->update($updateData);

        // Use sap_masterfile_id directly from store_order_items
        $sapMasterfileId = $receiveDate->store_order_item->sap_masterfile_id;
        if (!$sapMasterfileId) {
            Log::error("IntercoReceivingController: Missing sap_masterfile_id for StoreOrderItem ID: {$receiveDate->store_order_item->id}");
            throw new \Exception("Missing sap_masterfile_id for store order item: {$receiveDate->store_order_item->id}");
        }

        $sapMasterfile = SAPMasterfile::findOrFail($sapMasterfileId);

        // Optional: Validate item_code matches for debugging
        if ($sapMasterfile->ItemCode !== $receiveDate->store_order_item->item_code) {
            Log::warning("IntercoReceivingController: Item code mismatch - store_order_item.item_code = {$receiveDate->store_order_item->item_code}, sap_masterfile.ItemCode = {$sapMasterfile->ItemCode}, sap_masterfile_id = {$sapMasterfileId}");
        }

        $storeOrder = $receiveDate->store_order_item->store_order;

        // Process inventory OUT for sending store
        $this->processInventoryOutForInterco($storeOrder, $receiveDate, $sapMasterfile);

        Log::info("IntercoReceivingController: Processing StoreOrderItem ID: {$receiveDate->store_order_item->id}, SAPMasterfile ID: {$sapMasterfile->id}, Quantity Received: {$receiveDate->quantity_received}");

        // ALWAYS INSERT new record for receiving store - no existence checks
        ProductInventoryStock::create([
            'product_inventory_id' => $sapMasterfile->id,
            'store_branch_id' => $storeOrder->store_branch_id,
            'quantity' => $receiveDate->quantity_received,
            'recently_added' => $receiveDate->quantity_received,
            'used' => 0,
            'created_at' => Carbon::now('Asia/Manila'),
            'updated_at' => Carbon::now('Asia/Manila')
        ]);

        $batch = PurchaseItemBatch::create([
            'store_order_item_id' => $receiveDate->store_order_item->id,
            'product_inventory_id' => $sapMasterfile->id,
            'store_branch_id' => $storeOrder->store_branch_id,
            'purchase_date' => Carbon::today()->format('Y-m-d'),
            'quantity' => $receiveDate->quantity_received,
            'unit_cost' => $receiveDate->store_order_item->cost_per_quantity,
            'remaining_quantity' => $receiveDate->quantity_received
        ]);

        $batch->product_inventory_stock_managers()->create([
            'product_inventory_id' => $sapMasterfile->id,
            'store_branch_id' => $storeOrder->store_branch_id,
            'quantity' => $receiveDate->quantity_received,
            'action' => 'add_quantity',
            'transaction_date' => Carbon::today()->format('Y-m-d'),
            'unit_cost' =>  $receiveDate->store_order_item->cost_per_quantity,
            'total_cost' => $receiveDate->quantity_received * $receiveDate->store_order_item->cost_per_quantity,
            'remarks' => 'From newly received interco items. (Interco Number: ' . $storeOrder->interco_number . ')'
        ]);

        $receiveDate->store_order_item->quantity_received += $receiveDate->quantity_received;
        $receiveDate->store_order_item->save();
    }
}