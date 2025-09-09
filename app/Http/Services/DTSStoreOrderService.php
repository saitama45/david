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

                // Use the date and variant from the FIRST item as the representative for the main order.
                $firstItemInGroup = $itemsForBranch->first();
                $representativeDate = $firstItemInGroup['order_date'];
                $representativeVariant = $firstItemInGroup['variant'];

                // Create the single Store Order record for this branch
                $storeOrder = StoreOrder::create([
                    'encoder_id' => $encoderId,
                    'supplier_id' => $dtsSupplier->id,
                    'store_branch_id' => $branchId,
                    'order_number' => $orderNumber,
                    'order_date' => $representativeDate,
                    'order_status' => 'pending',
                    'variant' => $representativeVariant,
                    'remarks' => 'DTS Order for ' . $representativeVariant,
                ]);

                // Create Store Order Items for this order
                foreach ($itemsForBranch as $itemData) {
                    $sapMasterfile = SAPMasterfile::find($itemData['item_id']);
                    if (!$sapMasterfile) continue;

                    // Store the item-specific date and variant in the remarks field.
                    $itemRemarks = 'Variant: ' . $itemData['variant'] . '; Delivery Date: ' . $itemData['order_date'];

                    StoreOrderItem::create([
                        'store_order_id' => $storeOrder->id,
                        'item_code' => $sapMasterfile->ItemCode,
                        'quantity_ordered' => $itemData['quantity'],
                        'cost_per_quantity' => $itemData['cost'],
                        'total_cost' => $itemData['quantity'] * $itemData['cost'],
                        'uom' => $sapMasterfile->AltUOM,
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
            // Delete the old order and its items
            $store_order->store_order_items()->delete();
            $store_order->delete();

            // Re-create the new order(s) using the existing creation logic
            $newOrders = $this->createDTSOrder($data);

            DB::commit();
            return $newOrders;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
