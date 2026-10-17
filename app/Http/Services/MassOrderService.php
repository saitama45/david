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

    /**
     * @param  callable|null  $onOrderCreated  fn (StoreBranch $store, StoreOrder $order) run inside the
     *                                         upload transaction after each order is created; throwing
     *                                         rolls the whole upload back
     */
    public function processMassOrderUpload(Collection $rows, $supplierCode, $orderDate, $initialOrderStatus = 'approved', ?callable $onOrderCreated = null)
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

                if ($onOrderCreated) {
                    $onOrderCreated($storeBranch, $order);
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

    /**
     * Everything the order details page (MassOrders/Show) shows: the order, its lines, the
     * receiving history and the receiving images. Shared by Mass Orders and CPO Orders.
     *
     * @return array{order: StoreOrder, orderedItems: \Illuminate\Support\Collection, receiveDatesHistory: \Illuminate\Support\Collection, images: \Illuminate\Support\Collection}
     */
    public function orderDetails(string $orderNumber): array
    {
        // Increase execution time and memory limits for large datasets
        set_time_limit(120); // 2 minutes
        ini_set('memory_limit', '512M');
        
        // Set database timeout for SQL Server
        try {
            DB::statement('SET LOCK_TIMEOUT 120000'); // 2 minutes in milliseconds
        } catch (\Exception $e) {
            // Ignore if command fails
        }
        
        // Single optimized query with all necessary relationships
        $order = \App\Models\StoreOrder::select([
            'id', 'order_number', 'order_date', 'order_status', 'variant',
            'supplier_id', 'store_branch_id', 'encoder_id', 'approver_id', 'approval_action_date',
            'cpo_vendor_id', 'cpo_vendor_name', 'cpo_vendor_tin', 'cpo_vendor_address',
        ])->with([
            'supplier:id,name,supplier_code',
            'store_branch:id,name,brand_code', 
            'encoder:id,first_name,last_name',
            'approver:id,first_name,last_name',
            'delivery_receipts:id,store_order_id,delivery_receipt_number,remarks,created_at',
            'image_attachments:id,store_order_id,file_path',
            'store_order_items' => function($query) {
                $query->select([
                    'id', 'store_order_id', 'item_code', 'quantity_ordered', 
                    'quantity_approved', 'quantity_commited', 'quantity_received',
                    'uom', 'committed_by', 'cost_per_quantity', 'total_cost'
                ])->with([
                    'supplierItem:ItemCode,item_name,category',
                    'supplierItem.sapMasterfiles:id,ItemCode,AltUOM,BaseUOM,BaseQty',
                    'committedBy:id,first_name,last_name'
                ]);
            }
        ])->where('order_number', $orderNumber)->firstOrFail();

        // Statuses in which an order is past commitment and awaiting delivery. Shared with
        // the receiving module so both pages agree on what "committed" means.
        $receivableStatuses = "'".implode("','", \App\Http\Services\OrderReceivingService::RECEIVING_STATUSES)."'";

        // Optimized receiving history query mimicking the provided SQL logic
        $receiveDatesHistory = DB::table('store_order_items as soi')
            ->join('store_orders as so', 'so.id', '=', 'soi.store_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'so.supplier_id')
            // Both catalogs are joined through a one-row-per-key subquery. Joining
            // them directly duplicates the line: neither (ItemCode, AltUOM) nor
            // (ItemCode, SupplierCode, uom) is unique, so an item carrying more
            // than one catalog row was listed once per row — the same ordered
            // quantity appearing as two identical lines.
            ->leftJoinSub(\App\Models\SAPMasterfile::singleRowPerJoinKey(), 'sm', function($join) {
                $join->on('sm.ItemCode', '=', 'soi.item_code')
                     ->on('sm.AltUOM', '=', 'soi.uom');
            })
            ->leftJoinSub(\App\Models\SupplierItems::singleRowPerJoinKey(), 'si', function($join) {
                $join->on('si.ItemCode', '=', 'sm.ItemCode')
                     ->on('si.uom', '=', 'sm.AltUOM')
                     ->on('si.SupplierCode', '=', 's.supplier_code');
            })
            ->leftJoin('ordered_item_receive_dates as receive', 'receive.store_order_item_id', '=', 'soi.id')
            ->leftJoin('users as u', 'u.id', '=', 'receive.received_by_user_id')
            ->where('so.id', $order->id)
            ->orderByRaw("CASE WHEN ISNULL([si].[sort_order], 0) = 0 THEN 1 ELSE 0 END, [si].[sort_order]")
            ->select([
                'receive.id as id',
                'soi.id as store_order_item_id',
                'si.category',
                'soi.item_code',
                'sm.ItemDescription as item_name',
                'sm.BaseUOM',
                'soi.uom',
                'soi.quantity_ordered',
                'soi.quantity_approved',
                'soi.quantity_commited', // Retain original for variance calculation
                'receive.quantity_received', // Retain original for variance calculation
                'receive.received_date',
                'receive.remarks',
                'receive.expiry_date',
                'u.first_name as received_by_first_name',
                'u.last_name as received_by_last_name',
                // Mirrors the receiving page: a row counts as received once it is 'received'
                // (saved by the receiver) or 'approved' (posted to stock by Final Receive All).
                // Commitment is read from the order status, not from committed_by — orders are
                // auto-committed from approval onwards, and a line committed that way carries
                // no committer.
                DB::raw("CASE
                    WHEN [receive].[status] IN ('approved', 'received') THEN 'RECEIVED'
                    WHEN [so].[order_status] IN ({$receivableStatuses}) THEN 'TO RECEIVE'
                    ELSE 'TO COMMIT'
                END as display_status"),
                DB::raw("[soi].[quantity_commited] as committed_display"),
                DB::raw("CASE
                    WHEN [receive].[status] IN ('approved', 'received') THEN [receive].[quantity_received]
                    ELSE 0
                END as received_display"),
            ])
            ->get();

        // Post-processing to ensure unique IDs for null records and calculate variances
        $receiveDatesHistory->transform(function($item) {
             if (is_null($item->id)) {
                 $item->id = 'pending_' . $item->store_order_item_id;
                 $item->received_date = null;
                 $item->remarks = null;
                 $item->expiry_date = null;
                 $item->received_by_first_name = null;
                 $item->received_by_last_name = null;
             }
             
             // Variances calculation based on SQL logic, using the original quantities
             $item->variance_ordered_committed = $item->committed_display - $item->quantity_ordered;
             
             // Variance (Committed vs Received): isnull(receive.quantity_received - soi.quantity_commited, 0)
             // Using the displayed quantities for variance calculation in the frontend, for consistency.
             $item->variance_committed_received = $item->received_display - $item->committed_display;

             return $item;
        });
        

        return [
            'order' => $order,
            'orderedItems' => $order->store_order_items,
            'receiveDatesHistory' => $receiveDatesHistory,
            'images' => $order->image_attachments,
        ];
    }
}
