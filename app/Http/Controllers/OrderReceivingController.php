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
        DB::beginTransaction();
        try {
            $historyItems = OrderedItemReceiveDate::with([
                'store_order_item.store_order',
                'store_order_item.supplierItem'
            ])
            ->whereHas('store_order_item.store_order', fn ($q) => $q->where('id', $id))
            ->where('status', 'pending')
            ->get();

            if ($historyItems->isEmpty()) {
                DB::commit(); // Nothing to do
                return back()->with('info', 'No pending items to confirm.');
            }

            $aggregatedData = [];

            // 1. Aggregate quantities in BASE UOM
            foreach ($historyItems as $history) {
                $itemCode = optional($history->store_order_item->supplierItem)->ItemCode;
                $uom = optional($history->store_order_item)->uom;

                if (!$itemCode || !$uom) {
                    Log::warning("OrderReceivingController: Skipping history item ID {$history->id} due to incomplete data (ItemCode or UOM missing).");
                    continue;
                }

                // Find the SAP Masterfile for the specific UOM it was ordered/received in
                $originalSapMasterfile = SAPMasterfile::where('ItemCode', $itemCode)->where('AltUOM', $uom)->first();

                if (!$originalSapMasterfile) {
                    Log::warning("OrderReceivingController: Could not find original SAP Masterfile for ItemCode '{$itemCode}' and UOM '{$uom}'. Skipping history item ID {$history->id}.");
                    continue;
                }

                // Find the target SAP Masterfile for SOH (where BaseUOM = AltUOM)
                $targetSapMasterfile = SAPMasterfile::where('ItemCode', $itemCode)->whereColumn('BaseUOM', 'AltUOM')->first();

                if (!$targetSapMasterfile) {
                    Log::warning("OrderReceivingController: Could not find target SOH SAP Masterfile for ItemCode '{$itemCode}'. Skipping history item ID {$history->id}.");
                    continue;
                }

                // Calculate conversion and quantities
                $conversionFactor = (is_numeric($originalSapMasterfile->BaseQty) && $originalSapMasterfile->BaseQty > 0)
                    ? $originalSapMasterfile->BaseQty
                    : 1;
                $quantityInBaseUom = $history->quantity_received * $conversionFactor;
                $costInBaseUom = ($conversionFactor != 0)
                    ? $history->store_order_item->cost_per_quantity / $conversionFactor
                    : $history->store_order_item->cost_per_quantity;

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
                $history->update([
                    'status' => 'approved',
                    'approval_action_by' => Auth::id(),
                    'received_date' => $history->received_date ?? Carbon::now('Asia/Manila'),
                ]);
                $history->store_order_item->quantity_received += $history->quantity_received;
                $history->store_order_item->save();
            }

            $this->getOrderStatus($id);
            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("OrderReceivingController: Error confirming receive for order ID {$id}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return back()->with('error', 'Failed to confirm receive. Check logs for details.');
        }

        return back();
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
    private function processInventoryOutForInterco($storeOrder, $quantityToDeduct, $targetSapMasterfile): void
    {
        try {
            Log::info("OrderReceivingController: Processing inventory OUT for interco order {$storeOrder->interco_number}, item {$targetSapMasterfile->ItemCode}, quantity {$quantityToDeduct}");

            // Get sending store stock using the target masterfile ID
            $sendingStock = ProductInventoryStock::where('product_inventory_id', $targetSapMasterfile->id)
                ->where('store_branch_id', $storeOrder->sending_store_branch_id)
                ->first();

            if (!$sendingStock) {
                Log::error("OrderReceivingController: No stock record found in sending store for item {$targetSapMasterfile->ItemCode}");
                throw new \Exception("No stock record found in sending store for item: {$targetSapMasterfile->ItemCode}");
            }

            if ($sendingStock->quantity < $quantityToDeduct) {
                $available = $sendingStock->quantity;
                Log::error("OrderReceivingController: Insufficient stock in sending store. Available: {$available}, Requested: {$quantityToDeduct}");
                throw new \Exception("Insufficient stock in sending store for item {$targetSapMasterfile->ItemCode}. Available: {$available}, Requested: {$quantityToDeduct}");
            }

            // Create inventory OUT record for sending store
            ProductInventoryStockManager::create([
                'product_inventory_id' => $targetSapMasterfile->id,
                'store_branch_id' => $storeOrder->sending_store_branch_id,
                'quantity' => $quantityToDeduct,
                'action' => 'out',
                'transaction_date' => Carbon::today()->format('Y-m-d'),
                'remarks' => "Interco transfer to {$storeOrder->store_branch->name} (Interco: {$storeOrder->interco_number})"
            ]);

            // Update sending store stock (subtract)
            $sendingStock->quantity -= $quantityToDeduct;
            $sendingStock->used += $quantityToDeduct;
            $sendingStock->save();

            // Update PurchaseItemBatch for sending store
            $deductedFromBatches = $quantityToDeduct;
            $sendingBatches = PurchaseItemBatch::where('product_inventory_id', $targetSapMasterfile->id)
                ->where('store_branch_id', $storeOrder->sending_store_branch_id)
                ->where('remaining_quantity', '>', 0)
                ->orderBy('purchase_date', 'asc')
                ->get();

            foreach ($sendingBatches as $batch) {
                if ($deductedFromBatches <= 0) {
                    break;
                }
                $deductAmount = min($deductedFromBatches, $batch->remaining_quantity);
                $batch->remaining_quantity -= $deductAmount;
                $batch->save();
                $deductedFromBatches -= $deductAmount;
            }

            if ($deductedFromBatches > 0) {
                 Log::warning("OrderReceivingController: Could not deduct full quantity from purchase batches for sending store.");
            }

            Log::info("OrderReceivingController: Successfully processed inventory OUT for interco transfer");

        } catch (\Exception $e) {
            Log::error("OrderReceivingController: Error processing inventory OUT for interco: " . $e->getMessage());
            throw $e;
        }
    }
}
