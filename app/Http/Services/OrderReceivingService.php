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

        // Start with a base query that includes relationships
        $query = StoreOrder::query()->with(['store_branch', 'supplier', 'delivery_receipts']);

        // Apply all advanced/suggester filters (search, dates, store, supplier, variant, aging, ...)
        // but NOT the receiving-status tab filter, so the tab counts reflect the applied filters.
        $this->applyCommonFilters($query, request()->all());

        // Calculate counts for all relevant statuses before applying the specific filter for the list
        $counts = $this->getCounts($query);
        Log::debug("OrderReceivingService: Calculated counts: " . json_encode($counts));

        // Apply receiving-status filter (driven by the tabs) for the main orders list
        $this->applyStatusFilter($query, $currentFilter);

        $orders = $query
            ->latest()
            ->paginate(10)
            ->withQueryString();

        // Process variant information for each order
        $orders->getCollection()->transform(function ($order) {
            // Debug logging for variant processing
            Log::info("OrderReceivingService: Processing order ID {$order->id}", [
                'supplier_id' => $order->supplier_id,
                'supplier_name' => $order->supplier?->name,
                'variant_field' => $order->variant,
                'remarks' => $order->remarks,
                'is_dropshipping' => ((string)$order->supplier_id === "5") ? 'YES' : 'NO'
            ]);

            // Check if this is a DROPSHIPPING order first (string comparison fix)
            if ((string)$order->supplier_id === "5") {
                Log::info("OrderReceivingService: DROPSHIPPING order found", [
                    'order_id' => $order->id,
                    'current_variant' => $order->variant,
                    'remarks_content' => $order->remarks
                ]);

                // For DROPSHIPPING orders with "mass dts" variant, extract from remarks
                if ($order->variant === 'mass dts' || $order->variant === 'N/A' || $order->variant === 'regular') {
                    // Extract variant from remarks using "Mass DTS Order - {variant}" pattern
                    if ($order->remarks && strpos($order->remarks, 'Mass DTS Order - ') !== false) {
                        $order->variant = str_replace('Mass DTS Order - ', '', $order->remarks);
                        Log::info("OrderReceivingService: Extracted variant from remarks", [
                            'order_id' => $order->id,
                            'extracted_variant' => $order->variant
                        ]);
                    }
                }
            }

            // Only set to N/A if variant is truly empty for non-DROPSHIPPING orders
            if (!$order->variant) {
                $order->variant = 'N/A';
            }

            Log::info("OrderReceivingService: Final variant for order ID {$order->id}", [
                'final_variant' => $order->variant,
                'supplier_id' => $order->supplier_id
            ]);

            return $order;
        });

        Log::debug("OrderReceivingService: Orders query executed. Total orders found: " . $orders->total());

        return [
            'orders' => $orders,
            'counts' => $counts
        ];
    }

    /**
     * Apply the shared "suggester" filters to an orders query. This is used by both the
     * listing (Index) and the Excel export so they always stay consistent. The receiving
     * status tab filter is intentionally NOT applied here (see applyStatusFilter).
     *
     * Supported params:
     *  - search                : free text (order #, supplier, store, SAP SO #)
     *  - order_number          : locate a specific order by number
     *  - delivery_date_from/to : range over order_date (the delivery date)
     *  - placed_date_from/to   : range over created_at (the order placed date)
     *  - store_ids[]           : filter by store branch
     *  - supplier_ids[]        : filter by supplier
     *  - variants[]            : filter by order type / variant
     *  - aging[]               : overdue | due_today | upcoming (for not-yet-received orders)
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $params
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function applyCommonFilters($query, array $params)
    {
        $user = User::rolesAndAssignedBranches();

        // Branch restriction for non-admin users
        if (!$user['isAdmin']) {
            $query->whereIn('store_branch_id', $user['assignedBranches']);
            Log::debug("OrderReceivingService: Applied branch filter for non-admin user: " . json_encode($user['assignedBranches']));
        }

        // Free-text search
        $search = $params['search'] ?? null;
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', '%' . $search . '%')
                    ->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('store_branch', function ($bq) use ($search) {
                        $bq->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('delivery_receipts', function ($drq) use ($search) {
                        $drq->where('sap_so_number', 'like', '%' . $search . '%');
                    });
            });
        }

        // Specific order number
        if (!empty($params['order_number'])) {
            $query->where('order_number', 'like', '%' . $params['order_number'] . '%');
        }

        // Delivery date range (order_date)
        if (!empty($params['delivery_date_from'])) {
            $query->whereDate('order_date', '>=', $params['delivery_date_from']);
        }
        if (!empty($params['delivery_date_to'])) {
            $query->whereDate('order_date', '<=', $params['delivery_date_to']);
        }

        // Order placed date range (created_at)
        if (!empty($params['placed_date_from'])) {
            $query->whereDate('created_at', '>=', $params['placed_date_from']);
        }
        if (!empty($params['placed_date_to'])) {
            $query->whereDate('created_at', '<=', $params['placed_date_to']);
        }

        // Store branch(es)
        $storeIds = $this->normalizeArrayParam($params['store_ids'] ?? null);
        if ($storeIds) {
            $query->whereIn('store_branch_id', $storeIds);
        }

        // Supplier(s)
        $supplierIds = $this->normalizeArrayParam($params['supplier_ids'] ?? null);
        if ($supplierIds) {
            $query->whereIn('supplier_id', $supplierIds);
        }

        // Variant(s) / order type
        $variants = $this->normalizeArrayParam($params['variants'] ?? null);
        if ($variants) {
            $query->whereIn('variant', $variants);
        }

        // Aging / Due status (computed from order_date for not-yet-received orders)
        $aging = $this->normalizeArrayParam($params['aging'] ?? null);
        if ($aging) {
            $this->applyAgingFilter($query, $aging);
        }

        return $query;
    }

    /**
     * Apply the receiving-status tab filter ('all', 'received', 'incomplete', 'commited').
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $currentFilter
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function applyStatusFilter($query, $currentFilter = 'all')
    {
        if ($currentFilter === 'all') {
            $query->whereIn('order_status', [
                OrderStatus::COMMITTED->value,
                OrderStatus::RECEIVED->value,
                OrderStatus::INCOMPLETE->value,
            ]);
            return $query;
        }

        $map = [
            'commited' => OrderStatus::COMMITTED->value,
            'received' => OrderStatus::RECEIVED->value,
            'incomplete' => OrderStatus::INCOMPLETE->value,
        ];

        if (isset($map[$currentFilter])) {
            $query->whereRaw('LOWER(order_status) = ?', [strtolower($map[$currentFilter])]);
        } else {
            // Unknown filter -> force no results rather than leaking everything
            $query->whereRaw('1=0');
            Log::warning("OrderReceivingService: Unknown status filter '{$currentFilter}'. Forcing empty order results.");
        }

        return $query;
    }

    /**
     * Apply the aging/due-status buckets. Only orders that are not fully received are
     * considered "overdue", "due today" or "upcoming"; multiple buckets are OR'd together.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $buckets
     * @return void
     */
    protected function applyAgingFilter($query, array $buckets)
    {
        $today = Carbon::today('Asia/Manila')->toDateString();
        $notReceived = strtolower(OrderStatus::RECEIVED->value);

        $query->where(function ($outer) use ($buckets, $today, $notReceived) {
            foreach ($buckets as $bucket) {
                if (!in_array($bucket, ['overdue', 'due_today', 'upcoming'], true)) {
                    continue;
                }
                $outer->orWhere(function ($sub) use ($bucket, $today, $notReceived) {
                    $sub->whereRaw('LOWER(order_status) <> ?', [$notReceived]);
                    if ($bucket === 'overdue') {
                        $sub->whereDate('order_date', '<', $today);
                    } elseif ($bucket === 'due_today') {
                        $sub->whereDate('order_date', '=', $today);
                    } else { // upcoming
                        $sub->whereDate('order_date', '>', $today);
                    }
                });
            }
        });
    }

    /**
     * Normalize a request parameter that may arrive as an array or a comma-separated
     * string into a clean array of non-empty values.
     *
     * @param mixed $value
     * @return array
     */
    protected function normalizeArrayParam($value): array
    {
        if (is_null($value) || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            $value = explode(',', (string) $value);
        }
        return array_values(array_filter($value, fn($v) => $v !== null && $v !== ''));
    }

    /**
     * Build the option lists used to populate the filter dropdowns. Store options are
     * restricted to the branches a non-admin user is assigned to.
     *
     * @return array
     */
    public function getFilterOptions()
    {
        $user = User::rolesAndAssignedBranches();

        $storesQuery = \App\Models\StoreBranch::query()->orderBy('name');
        if (!$user['isAdmin']) {
            $storesQuery->whereIn('id', $user['assignedBranches']);
        }

        $suppliers = \App\Models\Supplier::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($s) => ['value' => $s->id, 'label' => $s->name])
            ->values();

        // Distinct variants present among relevant receiving statuses
        $variantsQuery = StoreOrder::query()
            ->whereIn('order_status', [
                OrderStatus::COMMITTED->value,
                OrderStatus::RECEIVED->value,
                OrderStatus::INCOMPLETE->value,
            ])
            ->whereNotNull('variant')
            ->where('variant', '<>', '');
        if (!$user['isAdmin']) {
            $variantsQuery->whereIn('store_branch_id', $user['assignedBranches']);
        }
        $variants = $variantsQuery->distinct()
            ->orderBy('variant')
            ->pluck('variant')
            ->map(fn($v) => ['value' => $v, 'label' => $v])
            ->values();

        return [
            'stores' => $storesQuery->get(['id', 'name'])
                ->map(fn($s) => ['value' => $s->id, 'label' => $s->name])
                ->values(),
            'suppliers' => $suppliers,
            'variants' => $variants,
            'agingOptions' => [
                ['value' => 'overdue', 'label' => 'Overdue'],
                ['value' => 'due_today', 'label' => 'Due Today'],
                ['value' => 'upcoming', 'label' => 'Upcoming'],
            ],
            'statusOptions' => [
                ['value' => 'all', 'label' => 'All'],
                ['value' => 'received', 'label' => 'Received'],
                ['value' => 'incomplete', 'label' => 'Partial Received'],
                ['value' => 'commited', 'label' => 'Committed'],
            ],
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
            'commited' => (clone $baseQuery)->where('order_status', OrderStatus::COMMITTED->value)->count(),
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
        ->whereIn('status', ['pending', 'received'])
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

        // NEW: Check if this is an interco order and process inventory OUT for sending store
        if ($storeOrder->isInterco()) {
            $this->processInventoryOutForInterco($storeOrder, $data, $sapMasterfile);
        }


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
        $storeOrder = StoreOrder::find($id);

        if (!$storeOrder) {
            return;
        }

        // Reload relationships to ensure fresh data within transaction
        $storeOrder->load(['store_order_items.ordered_item_receive_dates']);

        $orderedItems = $storeOrder->store_order_items;
        $totalItems = $orderedItems->count();
        $receivedItemsCount = 0;

        foreach ($orderedItems as $itemOrdered) {
            // Check if the item has any approved receive records
            $hasReceived = $itemOrdered->ordered_item_receive_dates
                ->whereIn('status', ['approved', 'received'])
                ->count() > 0;
            
            if ($hasReceived) {
                $receivedItemsCount++;
            }
        }

        // If all items have at least one approved receive record, the order is RECEIVED.
        // Otherwise, it is INCOMPLETE.
        if ($totalItems > 0 && $receivedItemsCount >= $totalItems) {
            $storeOrder->order_status = OrderStatus::RECEIVED->value;
        } else {
            $storeOrder->order_status = OrderStatus::INCOMPLETE->value;
        }

        $storeOrder->save();
    }

    /**
     * Process inventory OUT for interco transfers from sending store
     */
    private function processInventoryOutForInterco($storeOrder, $data, $sapMasterfile): void
    {
        try {
            Log::info("OrderReceivingService: Processing inventory OUT for interco order {$storeOrder->interco_number}, item {$sapMasterfile->item_code}, quantity {$data->quantity_received}");

            // Get sending store stock
            $sendingStock = ProductInventoryStock::where('product_inventory_id', $sapMasterfile->id)
                ->where('store_branch_id', $storeOrder->sending_store_branch_id)
                ->first();

            if (!$sendingStock) {
                Log::error("OrderReceivingService: No stock record found in sending store for item {$sapMasterfile->item_code}");
                throw new \Exception("No stock record found in sending store for item: {$sapMasterfile->item_code}");
            }

            // Check if sending store has sufficient stock
            if ($sendingStock->quantity < $data->quantity_received) {
                $available = $sendingStock->quantity;
                $requested = $data->quantity_received;
                Log::error("OrderReceivingService: Insufficient stock in sending store. Available: {$available}, Requested: {$requested}");
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

            Log::info("OrderReceivingService: Created ProductInventoryStockManager entry for inventory OUT");

            // Update sending store stock (subtract)
            $sendingStock->quantity -= $data->quantity_received;
            $sendingStock->used += $data->quantity_received;
            $sendingStock->save();

            Log::info("OrderReceivingService: Updated sending store stock. New quantity: {$sendingStock->quantity}, Used: {$sendingStock->used}");

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

                Log::info("OrderReceivingService: Updated PurchaseItemBatch {$sendingBatch->id}. Remaining quantity: {$sendingBatch->remaining_quantity}");
            } else {
                Log::warning("OrderReceivingService: No PurchaseItemBatch found for sending store to update remaining quantity");
            }

            Log::info("OrderReceivingService: Successfully processed inventory OUT for interco transfer");

        } catch (\Exception $e) {
            Log::error("OrderReceivingService: Error processing inventory OUT for interco: " . $e->getMessage());
            throw $e;
        }
    }
}
