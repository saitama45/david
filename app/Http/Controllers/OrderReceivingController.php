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
            // The page hides the edit pencil on the same rule the server enforces.
            'receivingEditDeadline' => optional($this->orderReceivingService->receivingEditDeadline($order))
                ->format('Y-m-d H:i:s'),
            // Catalogue for the "item delivered but not ordered" picker. Mapped to a lean
            // shape on purpose: serialising the models would fire SupplierItems' appended
            // sap_master_file accessor once per row.
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

        if ($problem = $this->orderReceivingService->receivingEditWindowProblem($order)
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

        $history = OrderedItemReceiveDate::with('store_order_item.store_order')->findOrFail($validated['id']);

        // Recording a quantity here marks the line received, exactly as Confirm Receive does.
        // Without this check a user could work down the list with the edit button and receive
        // the whole order without ever attaching a delivery receipt or an image.
        $order = $history->store_order_item?->store_order;

        if ($order && $problem = $this->orderReceivingService->receivingEditWindowProblem($order)
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


    public function confirmReceive($id)
    {
        $order = StoreOrder::findOrFail($id);

        if ($problem = $this->orderReceivingService->deliveryEvidenceProblem($order)) {
            return back()->withErrors(['error' => $problem]);
        }

        DB::beginTransaction();
        try {
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
                // Even if there are no pending items, re-evaluate the order status to ensure it's correct
                // (e.g., if previous attempts left it as 'incomplete')
                $this->orderReceivingService->getOrderStatus($id);
                DB::commit(); // Commit the remarks fix if any
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

            // 4. Recalculate and update order status
            $this->orderReceivingService->getOrderStatus($id);
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
}
