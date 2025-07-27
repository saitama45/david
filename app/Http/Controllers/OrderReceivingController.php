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
use App\Models\ProductInventoryStock; // This model is now linked to SAPMasterfile
use App\Models\ProductInventoryStockManager; // This model is now linked to SAPMasterfile
use App\Models\PurchaseItemBatch;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use App\Models\SAPMasterfile; // Ensure SAPMasterfile is imported
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log; // Import Log facade

class OrderReceivingController extends Controller
{
    protected $orderReceivingService;

    public function __construct(OrderReceivingService $orderReceivingService)
    {
        $this->orderReceivingService = $orderReceivingService;
    }
    public function index()
    {
        $orders = $this->orderReceivingService->getOrdersList();
        return Inertia::render('OrderReceiving/Index', [
            'orders' => $orders,
            'filters' => request()->only(['search'])
        ]);
    }

    public function show($id)
    {
        $order = $this->orderReceivingService->getOrderDetails($id);
        $images = $this->orderReceivingService->getImageAttachments($order);
        $orderedItems = $this->orderReceivingService->getOrderItems($order);
        $receiveDatesHistory = $order->ordered_item_receive_dates;

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

        return Excel::download(
            new ApprovedOrdersExport($search),
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
            'store_order_id' => $validated['store_order_id'],
            'remarks' => $validated['remarks']
        ]);
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

    public function updateReceiveDateHistory(UpdateReceiveDateHistoryRequest $request)
    {
        $validated = $request->validated();
        $history = OrderedItemReceiveDate::findOrFail($validated['id']);
        $history->update($validated);
        return redirect()->back();
    }

    public function confirmReceive($id)
    {
        // Eager load supplierItem and its sapMasterfile relationship
        $historyItems = OrderedItemReceiveDate::with([
            'store_order_item.store_order.store_order_items', // Changed to store_order and store_order_items
            'store_order_item.supplierItem.sapMasterfile'
        ])
        ->whereHas('store_order_item.store_order', function ($query) use ($id) { // Changed to store_order
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
                Log::error("Error confirming receive for order item history ID {$data->id}: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
                // You might want to return an error response here or re-throw
                return back()->with('error', 'Failed to confirm receive for some items. Check logs for details.');
            }
        }

        return back();
    }

    public function extracted($data): void
    {
        // Update received_date only if it's NULL, and set status/approver
        $updateData = [
            'status' => 'approved',
            'approval_action_by' => Auth::user()->id,
            'received_by_user_id' => Auth::user()->id,
        ];

        // Set the received_date to current Philippine time (UTC+8) if it's null
        if (is_null($data->received_date)) {
            $updateData['received_date'] = Carbon::now('Asia/Manila'); // Explicitly set timezone to Asia/Manila
        }

        $data->update($updateData);

        // Get the SAPMasterfile instance via the StoreOrderItem's supplierItem relationship
        $sapMasterfile = $data->store_order_item->supplierItem->sapMasterfile;
        $storeOrder = $data->store_order_item->store_order; // Changed to store_order

        // Ensure sapMasterfile exists before proceeding with stock updates
        if (!$sapMasterfile) {
            Log::error("OrderReceivingController: SAPMasterfile not found for StoreOrderItem ID: {$data->store_order_item->id} (ItemCode: {$data->store_order_item->item_code}, UOM: {$data->store_order_item->uom})");
            throw new \Exception("SAP Masterfile not found for item: {$data->store_order_item->item_code}");
        }

        Log::info("OrderReceivingController: Processing StoreOrderItem ID: {$data->store_order_item->id}, SAPMasterfile ID: {$sapMasterfile->id}, Quantity Received: {$data->quantity_received}");

        // Use the sapMasterfile->id for product_inventory_id in ProductInventoryStock
        $stock = ProductInventoryStock::firstOrNew([
            'product_inventory_id' => $sapMasterfile->id, // Use SAPMasterfile ID here
            'store_branch_id' => $storeOrder->store_branch_id
        ]);

        // Corrected line 189 for PHP 5.x/7.0 compatibility if $stock->id might be null
        Log::info("OrderReceivingController: ProductInventoryStock BEFORE save (ID: " . (isset($stock->id) ? $stock->id : 'NEW') . "): Quantity = {$stock->quantity}, Used = {$stock->used}, Recently Added = {$stock->recently_added}");


        // If it's a new stock entry, set initial quantities
        if (!$stock->exists) {
            $stock->quantity = 0;
            $stock->recently_added = 0;
            $stock->used = 0;
            Log::info("OrderReceivingController: New ProductInventoryStock record being initialized.");
        }
        
        $stock->increment('quantity', $data->quantity_received);
        // The `recently_added` should reflect the current received quantity, not accumulate.
        // It's usually a temporary field for reporting on recent additions, or should be handled carefully.
        // If it's meant to be the *last* quantity added, then direct assignment is fine.
        $stock->recently_added = $data->quantity_received; // Set recently_added to the current quantity received
        $stock->save(); // Save the updated stock record

        Log::info("OrderReceivingController: ProductInventoryStock AFTER save (ID: {$stock->id}): Quantity = {$stock->quantity}, Used = {$stock->used}, Recently Added = {$stock->recently_added}");


        // Create PurchaseItemBatch
        $batch = PurchaseItemBatch::create([
            'store_order_item_id' => $data->store_order_item->id,
            'product_inventory_id' => $sapMasterfile->id, // Use SAPMasterfile ID here
            'store_branch_id' => $storeOrder->store_branch_id,
            'purchase_date' => Carbon::today()->format('Y-m-d'),
            'quantity' => $data->quantity_received,
            'unit_cost' => $data->store_order_item->cost_per_quantity,
            'remaining_quantity' => $data->quantity_received
        ]);

        Log::info("OrderReceivingController: PurchaseItemBatch created with ID: {$batch->id}, Quantity: {$batch->quantity}");


        // Create ProductInventoryStockManager entry
        $batch->product_inventory_stock_managers()->create([
            'product_inventory_id' => $sapMasterfile->id, // Use SAPMasterfile ID here
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
        // The $data->store_order_item->save() is handled in the confirmReceive loop.
    }

    public function getOrderStatus($id)
    {
        $storeOrder = StoreOrder::with('store_order_items')->find($id); // Changed to store_order_items

        $orderedItems = $storeOrder->store_order_items; // Changed to store_order_items

        $storeOrder->order_status = OrderStatus::RECEIVED->value;
        foreach ($orderedItems as $itemOrdered) {
            if ($itemOrdered->quantity_commited > $itemOrdered->quantity_received) {
                $storeOrder->order_status = OrderStatus::INCOMPLETE->value;
            }
        }

        $storeOrder->save();
    }
}
