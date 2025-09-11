<?php

namespace App\Http\Services;

use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreBranch;
use App\Models\Supplier;
use App\Models\SAPMasterfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DTSStoreOrderService
{
    /**
     * Creates new DTS orders, grouped by store branch.
     *
     * @param array $data
     * @return \Illuminate\Support\Collection
     */
    public function createDTSOrder(array $data): Collection
    {
        DB::beginTransaction();
        try {
            $createdOrders = new Collection();
            $encoderId = Auth::id();
            $dtsSupplier = Supplier::where('supplier_code', 'DROPS')->firstOrFail();
            $globalOrderDate = $data['order_date']; // Get the global order date

            // Group items by store_branch_id ONLY, as per business logic.
            $itemsByBranch = collect($data['items'])->groupBy('store_branch_id');

            foreach ($itemsByBranch as $branchId => $itemsForBranch) {
                $storeBranch = StoreBranch::findOrFail($branchId);

                // Generate ONE Order Number for the entire branch transaction.
                $lastOrder = StoreOrder::query()
                    ->where('store_branch_id', $storeBranch->id)
                    ->latest('id')->first();

                $nextOrderNumber = '00001';
                if ($lastOrder) {
                    $lastNumber = intval(substr($lastOrder->order_number, -5));
                    $nextOrderNumber = str_pad($lastNumber + 1, 5, '0', STR_PAD_LEFT);
                }
                $orderNumber = $storeBranch->branch_code . '-' . $nextOrderNumber;

                // Use the variant from the FIRST item as the representative for the main order.
                $firstItemInGroup = $itemsForBranch->first();
                $representativeVariant = $firstItemInGroup['variant'];

                // Create the single Store Order record for this branch
                $storeOrder = StoreOrder::create([
                    'encoder_id' => $encoderId,
                    'supplier_id' => $dtsSupplier->id,
                    'store_branch_id' => $branchId,
                    'order_number' => $orderNumber,
                    'order_date' => $globalOrderDate, // Use the global order date
                    'order_status' => 'pending',
                    'variant' => $representativeVariant,
                    'remarks' => 'DTS Order for ' . $representativeVariant . ' on ' . $globalOrderDate,
                ]);

                // Create Store Order Items for this order
                foreach ($itemsForBranch as $itemData) {
                    // Fetch the SupplierItem using the ItemCode (item_id from frontend)
                    $supplierItem = \App\Models\SupplierItems::where('ItemCode', $itemData['item_id'])
                                                              ->where('SupplierCode', $dtsSupplier->supplier_code)
                                                              ->first();

                    if (!$supplierItem) {
                        Log::warning("DTSStoreOrderService: SupplierItem not found for ItemCode: " . $itemData['item_id'] . " and SupplierCode: " . $dtsSupplier->supplier_code);
                        continue;
                    }

                    // Store the item-specific variant in the remarks field.
                    $itemRemarks = 'Variant: ' . $itemData['variant'];

                    StoreOrderItem::create([
                        'store_order_id' => $storeOrder->id,
                        'item_code' => $supplierItem->ItemCode, // Use ItemCode from SupplierItem
                        'quantity_ordered' => $itemData['quantity'],
                        'cost_per_quantity' => $itemData['cost'], // Use cost from frontend (or supplierItem->cost if preferred)
                        'total_cost' => $itemData['quantity'] * $itemData['cost'],
                        'uom' => $supplierItem->uom, // Use UOM from SupplierItem
                        'remarks' => $itemRemarks,
                    ]);
                }
                $createdOrders->push($storeOrder);
            }

            DB::commit();
            return $createdOrders;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Updates existing DTS orders based on the new data structure.
     * Note: This is a destructive and recreative process.
     *
     * @param array $orderNumbersToUpdate
     * @param array $data
     * @return \Illuminate\Support\Collection
     */
    public function updateDTSOrder(StoreOrder $store_order, array $data)
    {
        DB::beginTransaction();
        try {
            $encoderId = Auth::id();
            $dtsSupplier = Supplier::where('supplier_code', 'DROPS')->firstOrFail();
            $globalOrderDate = $data['order_date'];

            $firstItemInGroup = collect($data['items'])->first();
            $representativeVariant = $firstItemInGroup['variant'];

            $store_order->update([
                'encoder_id' => $encoderId,
                'order_date' => $globalOrderDate,
                'variant' => $representativeVariant,
                'remarks' => 'DTS Order for ' . $representativeVariant . ' on ' . $globalOrderDate,
            ]);

            $incomingItems = collect($data['items']);
            $existingItemIds = $store_order->store_order_items()->pluck('id');
            $incomingItemIds = $incomingItems->pluck('id')->filter();

            $idsToDelete = $existingItemIds->diff($incomingItemIds);
            if ($idsToDelete->isNotEmpty()) {
                StoreOrderItem::destroy($idsToDelete->all());
            }

            foreach ($incomingItems as $itemData) {
                $supplierItem = \App\Models\SupplierItems::where('ItemCode', $itemData['item_id'])
                    ->where('SupplierCode', $dtsSupplier->supplier_code)
                    ->first();

                if (!$supplierItem) {
                    Log::warning("DTSStoreOrderService: SupplierItem not found for ItemCode: " . $itemData['item_id']);
                    continue;
                }

                $payload = [
                    'store_order_id' => $store_order->id,
                    'item_code' => $supplierItem->ItemCode,
                    'quantity_ordered' => $itemData['quantity'],
                    'cost_per_quantity' => $itemData['cost'],
                    'total_cost' => $itemData['quantity'] * $itemData['cost'],
                    'uom' => $supplierItem->uom,
                    'remarks' => 'Variant: ' . $itemData['variant'],
                ];

                if (isset($itemData['id'])) {
                    $item = StoreOrderItem::find($itemData['id']);
                    if ($item) {
                        $item->update($payload);
                    }
                } else {
                    StoreOrderItem::create($payload);
                }
            }

            DB::commit();
            return collect([$store_order]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
