<?php

namespace App\Http\Services;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus; // Import OrderStatus enum
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Added for logging in extracted method
use App\Models\DeliveryReceipt; // Added missing use statement
use App\Models\OrderedItemReceiveDate; // Added missing use statement
use App\Models\ProductInventoryStock; // Added missing use statement
use App\Models\ProductInventoryStockManager; // Added missing use statement
use App\Models\PurchaseItemBatch; // Added missing use statement

class OrderReceivingService extends StoreOrderService
{
    /**
     * Get a list of orders for receiving, filtered by status and search term.
     *
     * @param string $currentFilter The current status filter ('all', 'received', 'incomplete', 'commited').
     * @return array Contains 'orders' (paginated) and 'counts'.
     */
    public function getOrdersList($currentFilter = 'all')
    {
        Log::debug("OrderReceivingService: getOrdersList called with currentFilter: {$currentFilter}");

        $search = request('search');
        $user = User::rolesAndAssignedBranches();

        // Start with a base query that includes relationships
        $query = StoreOrder::query()->with(['store_branch', 'supplier']);

        // Apply branch filtering based on user roles
        if (!$user['isAdmin']) {
            $query->whereIn('store_branch_id', $user['assignedBranches']);
            Log::debug("OrderReceivingService: Applied branch filter for non-admin user: " . json_encode($user['assignedBranches']));
        }

        // Apply search filter
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', '%' . $search . '%')
                    ->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('store_branch', function ($bq) use ($search) {
                        $bq->where('name', 'like', '%' . $search . '%');
                    });
            });
            Log::debug("OrderReceivingService: Applied search filter: {$search}");
        }

        // Calculate counts for all relevant statuses before applying the specific filter for the list
        $counts = $this->getCounts($query);
        Log::debug("OrderReceivingService: Calculated counts: " . json_encode($counts));

        // Apply status filter for the main orders list
        if ($currentFilter === 'all') {
            // "All" for receiving means orders that are commited, received, or incomplete
            $query->whereIn('order_status', [
                OrderStatus::COMMITED->value,
                OrderStatus::RECEIVED->value,
                OrderStatus::INCOMPLETE->value
            ]);
            Log::debug("OrderReceivingService: Applied 'all' status filter: COMMITED, RECEIVED, INCOMPLETE");
        } else {
            // Determine the canonical lowercase status value from the enum
            $statusToFilter = '';
            switch ($currentFilter) {
                case 'commited':
                    $statusToFilter = strtolower(OrderStatus::COMMITED->value);
                    break;
                case 'received':
                    $statusToFilter = strtolower(OrderStatus::RECEIVED->value);
                    break;
                case 'incomplete':
                    $statusToFilter = strtolower(OrderStatus::INCOMPLETE->value);
                    break;
                // Add other cases if you introduce more specific tabs
            }

            if ($statusToFilter) {
                // Apply specific status filter using a case-insensitive comparison with canonical enum value
                $query->whereRaw('LOWER(order_status) = ?', [$statusToFilter]);
                Log::debug("OrderReceivingService: Applied specific status filter: LOWER(order_status) = " . $statusToFilter);
            } else {
                // Fallback if an unknown filter is passed, prevent showing all orders inadvertently
                $query->whereRaw('1=0'); // Force no results
                Log::warning("OrderReceivingService: Unknown status filter '{$currentFilter}'. Forcing empty order results.");
            }
        }

        $orders = $query
            ->latest()
            ->paginate(10)
            ->withQueryString();

        Log::debug("OrderReceivingService: Orders query executed. Total orders found: " . $orders->total());

        return [
            'orders' => $orders,
            'counts' => $counts
        ];
    }

    /**
     * Calculates counts for different order statuses relevant to receiving.
     *
     * @param \Illuminate\Database\Eloquent\Builder $baseQuery A query builder instance before specific status filters.
     * @return array
     */
    public function getCounts($baseQuery)
    {
        $counts = [
            'received' => (clone $baseQuery)->where('order_status', OrderStatus::RECEIVED->value)->count(),
            'incomplete' => (clone $baseQuery)->where('order_status', OrderStatus::INCOMPLETE->value)->count(),
            'commited' => (clone $baseQuery)->where('order_status', OrderStatus::COMMITED->value)->count(),
        ];
        // The 'all' count is the sum of relevant receiving statuses
        $counts['all'] = $counts['received'] + $counts['incomplete'] + $counts['commited'];

        return $counts;
    }

    /**
     * Get order items for a given store order, eager loading necessary relationships.
     *
     * @param StoreOrder $order The store order model.
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getOrderItems(StoreOrder $order)
    {
        return $order->store_order_items()->with([
            'supplierItem' => function($query) {
                $query->select('id', 'ItemCode', 'item_name', 'uom', 'cost');
                $query->with('sapMasterfiles');
            },
        ])->get();
    }


    public function receiveOrder($id, array $data)
    {
        $orderedItem = StoreOrderItem::with('store_order')->findOrFail($id);

        DB::beginTransaction();
        $orderedItem->ordered_item_receive_dates()->create([
            'received_by_user_id' => Auth::user()->id,
            'quantity_received' => $data['quantity_received'],
            'received_date' => Carbon::parse($data['received_date'])->format('Y-m-d H:i:s'),
            'expiry_date' => $data['expiry_date'] ? Carbon::parse($data['expiry_date'])->format('Y-m-d') : null, // Handle null expiry_date
            'remarks' => $data['remarks'],
        ]);
        $orderedItem->save();
        DB::commit();
    }

    public function addDeliveryReceiptNumber(array $data)
    {
        DeliveryReceipt::create([
            'delivery_receipt_number' => $data['delivery_receipt_number'],
            'sap_so_number' => $data['sap_so_number'],
            'store_order_id' => $data['store_order_id'],
            'remarks' => $data['remarks'],
        ]);
    }

    public function updateDeliveryReceiptNumber(array $data, $id)
    {
        $receipt = DeliveryReceipt::findOrFail($id);
        $receipt->update($data);
    }

    public function destroyDeliveryReceiptNumber($id)
    {
        $receipt = DeliveryReceipt::findOrFail($id);
        $receipt->delete();
    }

    public function deleteReceiveDateHistory($id)
    {
        $history = OrderedItemReceiveDate::with('store_order_item')->findOrFail($id);
        DB::beginTransaction();
        $history->delete();
        DB::commit();
    }

    public function updateReceiveDateHistory(array $data)
    {
        $history = OrderedItemReceiveDate::findOrFail($data['id']);
        $history->update($data);
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
                Log::error("OrderReceivingService: Error confirming receive for order item history ID {$data->id}: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
                throw new \Exception('Failed to confirm receive for some items.');
            }
        }
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

        // Ensure sapMasterfile exists before proceeding with stock updates
        if (!$sapMasterfile) {
            Log::error("OrderReceivingService: SAPMasterfile not found for StoreOrderItem ID: {$data->store_order_item->id} (ItemCode: {$data->store_order_item->item_code}, UOM: {$data->store_order_item->uom})");
            throw new \Exception("SAP Masterfile not found for item: {$data->store_order_item->item_code}");
        }
        
        $storeOrder = $data->store_order_item->store_order;


        Log::info("OrderReceivingService: Processing StoreOrderItem ID: {$data->store_order_item->id}, SAPMasterfile ID: {$sapMasterfile->id}, Quantity Received: {$data->quantity_received}");

        // Use the sapMasterfile->id for product_inventory_id in ProductInventoryStock
        $stock = ProductInventoryStock::firstOrNew([
            'product_inventory_id' => $sapMasterfile->id, // Use SAPMasterfile ID here
            'store_branch_id' => $storeOrder->store_branch_id
        ]);

        // If it's a new stock entry, set initial quantities
        if (!$stock->exists) {
            $stock->quantity = 0;
            $stock->recently_added = 0;
            $stock->used = 0;
            Log::info("OrderReceivingService: New ProductInventoryStock record being initialized for product_inventory_id: {$sapMasterfile->id}.");
        } else {
            Log::info("OrderReceivingService: Existing ProductInventoryStock record found (ID: {$stock->id}) for product_inventory_id: {$sapMasterfile->id}. Current quantity: {$stock->quantity}.");
        }
        
        // Explicitly add the quantity and set recently_added
        $stock->quantity += $data->quantity_received; // Direct addition instead of increment()
        $stock->recently_added = $data->quantity_received; // Set recently_added to the current quantity received
        
        Log::info("OrderReceivingService: ProductInventoryStock BEFORE save (ID: " . (isset($stock->id) ? $stock->id : 'NEW') . "): Calculated Quantity = {$stock->quantity}, Recently Added = {$stock->recently_added}");
        
        $stock->save(); // Save the updated stock record

        Log::info("OrderReceivingService: ProductInventoryStock AFTER save (ID: {$stock->id}): Persisted Quantity = {$stock->quantity}, Persisted Recently Added = {$stock->recently_added}");


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

        Log::info("OrderReceivingService: PurchaseItemBatch created with ID: {$batch->id}, Quantity: {$batch->quantity}");


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

        Log::info("OrderReceivingService: ProductInventoryStockManager entry created for batch ID: {$batch->id}");

        $data->store_order_item->quantity_received += $data->quantity_received;
        // The $data->store_order_item->save() is handled in the confirmReceive loop.
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
}
