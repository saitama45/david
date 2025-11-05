<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Exports\ApprovedOrdersExport;
use App\Http\Requests\OrderReceiving\AddDeliveryReceiptNumberRequest;
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
            'filters' => request()->only(['search', 'currentFilter']),
            'counts' => $data['counts']
        ]);
    }

    public function show($id)
    {
        $order = $this->orderReceivingService->getOrderDetails($id);
        
        // Fetch images directly from the relationship to ensure the accessor is called
        $images = $order->image_attachments()->get();

        $orderedItems = $this->orderReceivingService->getOrderItems($order);

        $receiveDatesHistory = OrderedItemReceiveDate::with([
            'store_order_item.supplierItem',
            'received_by_user',
            'approval_action_by_user'
        ])->whereHas('store_order_item.store_order', function ($query) use ($id) {
            $query->where('order_number', $id);
        })->get();

        return Inertia::render('OrderReceiving/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'receiveDatesHistory' => $receiveDatesHistory,
            'images' => $images
        ]);
    }

    public function export()
    {
        $search = request('search');
        $currentFilter = request('currentFilter') ?? 'all';

        return Excel::download(
            new ApprovedOrdersExport($search, $currentFilter),
            'approved-orders-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function receive(ReceiveOrderRequest $request, $id)
    {
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
        $history = OrderedItemReceiveDate::with('store_order_item')->findOrFail($id);
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

        $history = OrderedItemReceiveDate::findOrFail($validated['id']);
        $history->update($validated);

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


    public function confirmReceive($id)
    {
        $historyItems = OrderedItemReceiveDate::with([
            'store_order_item.store_order.store_order_items',
            'store_order_item.supplierItem.sapMasterfiles'
        ])
        ->whereHas('store_order_item.store_order', function ($query) use ($id) {
            $query->where('id', $id);
        })
        ->where('status', 'pending')
        ->get();

        foreach ($historyItems as $data) {
            DB::beginTransaction();
            try {
                $this->extracted($data);
                $data->store_order_item->save();
                $data->save();
                $this->getOrderStatus($id);
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("OrderReceivingController: Error confirming receive for order item history ID {$data->id}: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
                return back()->with('error', 'Failed to confirm receive for some items. Check logs for details.');
            }
        }

        return back();
    }

    public function extracted($data): void
    {
        $updateData = [
            'status' => 'approved',
            'approval_action_by' => Auth::user()->id,
            'received_by_user_id' => Auth::user()->id,
        ];

        if (is_null($data->received_date)) {
            $updateData['received_date'] = Carbon::now('Asia/Manila');
        }

        $data->update($updateData);

        $sapMasterfile = $data->store_order_item->supplierItem->sapMasterfile;

        if (!$sapMasterfile) {
            Log::error("OrderReceivingController: SAPMasterfile not found for StoreOrderItem ID: {$data->store_order_item->id} (ItemCode: {$data->store_order_item->item_code}, UOM: {$data->store_order_item->uom})");
            throw new \Exception("SAP Masterfile not found for item: {$data->store_order_item->item_code}");
        }
        
        $storeOrder = $data->store_order_item->store_order;

        // NEW: Check if this is an interco order and process inventory OUT for sending store
        if ($storeOrder->isInterco()) {
            $this->processInventoryOutForInterco($storeOrder, $data, $sapMasterfile);
        }

        Log::info("OrderReceivingController: Processing StoreOrderItem ID: {$data->store_order_item->id}, SAPMasterfile ID: {$sapMasterfile->id}, Quantity Received: {$data->quantity_received}");

        $stock = ProductInventoryStock::firstOrNew([
            'product_inventory_id' => $sapMasterfile->id,
            'store_branch_id' => $storeOrder->store_branch_id
        ]);

        if (!$stock->exists) {
            $stock->quantity = 0;
            $stock->recently_added = 0;
            $stock->used = 0;
            Log::info("OrderReceivingController: New ProductInventoryStock record being initialized for product_inventory_id: {$sapMasterfile->id}.");
        } else {
            Log::info("OrderReceivingController: Existing ProductInventoryStock record found (ID: {$stock->id}) for product_inventory_id: {$sapMasterfile->id}. Current quantity: {$stock->quantity}.");
        }
        
        $stock->quantity += $data->quantity_received;
        $stock->recently_added = $data->quantity_received;
        
        Log::info("OrderReceivingController: ProductInventoryStock BEFORE save (ID: " . (isset($stock->id) ? $stock->id : 'NEW') . "): Calculated Quantity = {$stock->quantity}, Recently Added = {$stock->recently_added}");
        
        $stock->save();

        Log::info("OrderReceivingController: ProductInventoryStock AFTER save (ID: {$stock->id}): Persisted Quantity = {$stock->quantity}, Persisted Recently Added = {$stock->recently_added}");

        $batch = PurchaseItemBatch::create([
            'store_order_item_id' => $data->store_order_item->id,
            'product_inventory_id' => $sapMasterfile->id,
            'store_branch_id' => $storeOrder->store_branch_id,
            'purchase_date' => Carbon::today()->format('Y-m-d'),
            'quantity' => $data->quantity_received,
            'unit_cost' => $data->store_order_item->cost_per_quantity,
            'remaining_quantity' => $data->quantity_received
        ]);

        Log::info("OrderReceivingController: PurchaseItemBatch created with ID: {$batch->id}, Quantity: {$batch->quantity}");

        $batch->product_inventory_stock_managers()->create([
            'product_inventory_id' => $sapMasterfile->id,
            'store_branch_id' => $storeOrder->store_branch_id,
            'quantity' => $data->quantity_received,
            'action' => 'add_quantity',
            'transaction_date' => Carbon::today()->format('Y-m-d'),
            'unit_cost' =>  $data->store_order_item->cost_per_quantity,
            'total_cost' => $data->quantity_received * $data->store_order_item->cost_per_quantity,
            'remarks' => 'From newly received items. (Order Number: ' . $storeOrder->order_number . ')'
        ]);

        Log::info("OrderReceivingController: ProductInventoryStockManager entry created for batch ID: {$batch->id}");

        $data->store_order_item->quantity_received += $data->quantity_received;
    }

    public function getOrderStatus($id)
    {
        $storeOrder = StoreOrder::with('store_order_items')->find($id);
        $orderedItems = $storeOrder->store_order_items;
        $storeOrder->order_status = OrderStatus::RECEIVED->value;
        foreach ($orderedItems as $itemOrdered) {
            if ($itemOrdered->quantity_commited > $itemOrdered->quantity_received) {
                $storeOrder->order_status = OrderStatus::INCOMPLETE->value;
            }
        }
        $storeOrder->save();
    }

    /**
     * Process inventory OUT for interco transfers from sending store
     */
    private function processInventoryOutForInterco($storeOrder, $data, $sapMasterfile): void
    {
        try {
            Log::info("OrderReceivingController: Processing inventory OUT for interco order {$storeOrder->interco_number}, item {$sapMasterfile->item_code}, quantity {$data->quantity_received}");

            // Get sending store stock
            $sendingStock = ProductInventoryStock::where('product_inventory_id', $sapMasterfile->id)
                ->where('store_branch_id', $storeOrder->sending_store_branch_id)
                ->first();

            if (!$sendingStock) {
                Log::error("OrderReceivingController: No stock record found in sending store for item {$sapMasterfile->item_code}");
                throw new \Exception("No stock record found in sending store for item: {$sapMasterfile->item_code}");
            }

            // Check if sending store has sufficient stock
            if ($sendingStock->quantity < $data->quantity_received) {
                $available = $sendingStock->quantity;
                $requested = $data->quantity_received;
                Log::error("OrderReceivingController: Insufficient stock in sending store. Available: {$available}, Requested: {$requested}");
                throw new \Exception("Insufficient stock in sending store for item {$sapMasterfile->item_code}. Available: {$available}, Requested: {$requested}");
            }

            // Create inventory OUT record for sending store
            ProductInventoryStockManager::create([
                'product_inventory_id' => $sapMasterfile->id,
                'store_branch_id' => $storeOrder->sending_store_branch_id,
                'quantity' => $data->quantity_received,
                'action' => 'out',
                'transaction_date' => Carbon::today()->format('Y-m-d'),
                'remarks' => "Interco transfer to {$storeOrder->store_branch->name} (Interco: {$storeOrder->interco_number})"
            ]);

            Log::info("OrderReceivingController: Created ProductInventoryStockManager entry for inventory OUT");

            // Update sending store stock (subtract)
            $sendingStock->quantity -= $data->quantity_received;
            $sendingStock->used += $data->quantity_received;
            $sendingStock->save();

            Log::info("OrderReceivingController: Updated sending store stock. New quantity: {$sendingStock->quantity}, Used: {$sendingStock->used}");

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

                Log::info("OrderReceivingController: Updated PurchaseItemBatch {$sendingBatch->id}. Remaining quantity: {$sendingBatch->remaining_quantity}");
            } else {
                Log::warning("OrderReceivingController: No PurchaseItemBatch found for sending store to update remaining quantity");
            }

            Log::info("OrderReceivingController: Successfully processed inventory OUT for interco transfer");

        } catch (\Exception $e) {
            Log::error("OrderReceivingController: Error processing inventory OUT for interco: " . $e->getMessage());
            throw $e;
        }
    }
}
