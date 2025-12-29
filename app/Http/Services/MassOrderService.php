<?php

namespace App\Http\Services;

use App\Enum\OrderStatus;
use App\Enum\OrderRequestStatus;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\SupplierItems;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MassOrderService
{
    protected $storeOrderService;

    public function __construct(StoreOrderService $storeOrderService)
    {
        $this->storeOrderService = $storeOrderService;
    }

    public function processMassOrderUpload(Collection $rows, $supplierCode, $orderDate, $initialOrderStatus = 'approved')
    {
        if ($rows->isEmpty()) {
            throw new Exception('The uploaded file is empty or invalid.');
        }

        // Get all possible brand codes from the database.
        $allBrandCodes = StoreBranch::pluck('brand_code')->all();

        // Get the headers from the first row of the import.
        $uploadedHeaders = collect($rows->first())->keys();

        // Find which of the uploaded headers match our brand codes (case-insensitive and slug-insensitive)
        $brandCodeHeaderMap = [];
        foreach ($allBrandCodes as $dbBrandCode) {
            foreach ($uploadedHeaders as $header) {
                // Compare the slugged DB brand code with the header key from the import
                if (Str::slug($dbBrandCode, '_') === $header) {
                    $brandCodeHeaderMap[$header] = $dbBrandCode; // Map the header key to the real brand_code
                }
            }
        }

        if (empty($brandCodeHeaderMap)) {
            throw new Exception('No store brand columns found that match existing stores in the database.');
        }

        $ordersToCreate = [];
        foreach ($rows as $row) {
            foreach ($brandCodeHeaderMap as $headerKey => $realBrandCode) {
                $quantity = (float) $row[$headerKey];
                if ($quantity > 0) {
                    // Get cost from database since cost column is removed from template
                    $supplierItem = SupplierItems::where('ItemCode', $row['item_code'])
                        ->where('SupplierCode', $supplierCode)
                        ->first();

                    $ordersToCreate[$realBrandCode][] = [
                        'item_code' => $row['item_code'],
                        'quantity' => $quantity,
                        'cost' => $supplierItem ? (float) $supplierItem->cost : 0,
                        'uom' => $row['unit'],
                    ];
                }
            }
        }

        if (empty($ordersToCreate)) {
            throw new Exception('The uploaded file contains no order quantities. Please enter a quantity for at least one item.');
        }

        $skippedStores = [];
        $createdCount = 0;

        DB::beginTransaction();
        try {
            foreach ($ordersToCreate as $brandCode => $items) {
                $storeBranch = StoreBranch::where('brand_code', $brandCode)->first();

                if (!$storeBranch) {
                    // This check is now redundant if the mapping logic is correct, but kept as a safeguard.
                    $skippedStores[] = ['brand_code' => $brandCode, 'reason' => 'Store branch not found.'];
                    continue;
                }

                $supplier = Supplier::where('supplier_code', $supplierCode)->firstOrFail();

                // Validation: Check for existing order for the same store, date, supplier, AND variant
                $existingOrder = StoreOrder::where('store_branch_id', $storeBranch->id)
                    ->whereDate('order_date', Carbon::parse($orderDate)->toDateString())
                    ->where('supplier_id', $supplier->id)
                    ->where('variant', 'mass regular')
                    ->exists();

                if ($existingOrder) {
                    $skippedStores[] = ['brand_code' => $brandCode, 'reason' => 'An order for this delivery date and supplier already exists.'];
                    continue;
                }

                // Set order_status to 'committed' for FRUITS AND VEGETABLES (DROPS)
                $finalOrderStatus = $supplierCode === 'DROPS' ? 'committed' : $initialOrderStatus;
                
                $order = StoreOrder::create([
                    'encoder_id' => Auth::id(),
                    'supplier_id' => $supplier->id,
                    'store_branch_id' => $storeBranch->id,
                    'order_number' => $this->storeOrderService->getOrderNumber($storeBranch->id),
                    'order_date' => Carbon::parse($orderDate)->toDateString(),
                    'order_status' => $finalOrderStatus,
                    'order_request_status' => OrderRequestStatus::PENDING->value,
                    'variant' => 'mass regular',
                ]);

                foreach ($items as $itemData) {
                    $storeOrderItem = $order->store_order_items()->create([
                        'item_code' => $itemData['item_code'],
                        'quantity_ordered' => $itemData['quantity'],
                        'quantity_approved' => $itemData['quantity'],
                        'quantity_commited' => $itemData['quantity'],
                        'cost_per_quantity' => $itemData['cost'],
                        'total_cost' => $itemData['quantity'] * $itemData['cost'],
                        'uom' => $itemData['uom'],
                        'committed_by' => $finalOrderStatus === 'committed' ? Auth::id() : null,
                        'committed_date' => $finalOrderStatus === 'committed' ? now() : null,
                    ]);

                    // Automatically create a pending receive record for DROPS (FRUITS AND VEGETABLES) orders
                    // This ensures they appear in the receiving list as awaiting inbound receiving
                    if ($supplierCode === 'DROPS') {
                        $storeOrderItem->ordered_item_receive_dates()->create([
                            'received_by_user_id' => Auth::id(),
                            'quantity_received' => $itemData['quantity'],
                            'received_date' => null,
                            'status' => 'pending',
                            'remarks' => null,
                        ]);
                    }
                }
                $createdCount++;
            }

            DB::commit();

            $message = "Successfully created {$createdCount} store order(s).";
            if (count($skippedStores) > 0) {
                $message .= " " . count($skippedStores) . " store(s) were skipped.";
            }

            return [
                'success' => true,
                'message' => $message,
                'skipped_stores' => $skippedStores,
            ];

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
