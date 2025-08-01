<?php

namespace App\Http\Services;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\Supplier;
use App\Models\StoreOrderItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Exception;
use Illuminate\Support\Facades\Log;

class StoreOrderService
{
    public function getOrderNumber($id)
    {
        $branchId = $id;
        $branchCode = StoreBranch::select('branch_code')->findOrFail($branchId)->branch_code;
        $orderCount = StoreOrder::where('store_branch_id', $branchId)->count() + 1;
        while (true) {
            $orderNumber = str_pad($orderCount, 5, '0', STR_PAD_LEFT);
            $store_order_number = "$branchCode-$orderNumber";
            $result = StoreOrder::where('order_number', $store_order_number)->first();
            $orderCount++;
            if (!$result) break;
        }
        return $store_order_number;
    }

    public function getOrdersList($variant = 'regular')
    {
        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : '1999-01-01';
        $to = request('to') ? Carbon::parse(request('to'))->addDay()->format('Y-m-d') : Carbon::today()->addMonth();
        $branchId = request('branchId');
        $search = request('search');
        $filterQuery = request('filterQuery');

        $query = StoreOrder::query()->with(['store_branch', 'supplier', 'encoder']);

        $user = User::rolesAndAssignedBranches();

        if (!$user['isAdmin']) {
            $query->whereIn('store_branch_id', $user['assignedBranches']);
        }

        if ($from && $to) {
            $query->whereBetween('order_date', [$from, $to]);
        }

        if ($filterQuery && $filterQuery !== 'all') {
            $query->where('order_status', $filterQuery);
        }


        if ($branchId) {
            $query->where('store_branch_id', $branchId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', '%' . $search . '%')
                    ->orWhereHas('store_branch', function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('supplier', function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', '%' . $search . '%')
                                 ->orWhere('supplier_code', 'like', '%' . $search . '%');
                    });
            });
        }

        return $query
            ->where('variant', $variant)
            ->latest()
            ->paginate(10)
            ->withQueryString();
    }

    public function createStoreOrder(array $data)
    {
        DB::beginTransaction();
        try {
            $supplier = Supplier::where('supplier_code', $data['supplier_id'])->first();

            if (!$supplier) {
                throw new Exception("Supplier with code '{$data['supplier_id']}' not found.");
            }

            $order = StoreOrder::create([
                'encoder_id' => Auth::user()->id,
                'supplier_id' => $supplier->id,
                'store_branch_id' => $data['branch_id'],
                'order_number' => $this->getOrderNumber($data['branch_id']),
                'order_date' => Carbon::parse($data['order_date'])->format('Y-m-d'),
                'order_status' => OrderStatus::PENDING->value,
                'order_request_status' => OrderRequestStatus::PENDING->value,
                'variant' => $data['variant'] ?? 'regular',
            ]);

            foreach ($data['orders'] as $itemData) {
                $order->store_order_items()->create([
                    'item_code' => $itemData['inventory_code'], // Uses inventory_code (SupplierItems.ItemCode string)
                    'quantity_ordered' => $itemData['quantity'],
                    'total_cost' => $itemData['total_cost'],
                    'cost_per_quantity' => $itemData['cost'],
                    'uom' => $itemData['uom'] ?? null
                ]);
            }

            DB::commit();
            return $order;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error in StoreOrderService@createStoreOrder: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    public function getPreviousOrderReference()
    {
        if (request()->has('orderId')) {
            // CORRECTED: Eager load 'sapMasterfiles' (plural)
            return StoreOrder::with(['store_order_items.supplierItem.sapMasterfiles'])->find(request()->input('orderId'));
        }

        return null;
    }

    public function getOrderDetails($id)
    {
        return StoreOrder::with([
            'encoder',
            'approver',
            'commiter',
            'delivery_receipts',
            'store_branch',
            'supplier',
            // CORRECTED: Eager load 'sapMasterfiles' (plural) here
            'store_order_items.supplierItem.sapMasterfiles',
            'store_order_remarks',
            'store_order_remarks.user',
            // CORRECTED: Eager load 'sapMasterfiles' (plural) here as well
            'ordered_item_receive_dates.store_order_item.supplierItem.sapMasterfiles',
            'ordered_item_receive_dates.receiver',
            'image_attachments',
        ])->where('order_number', $id)->firstOrFail();
    }

    public function getOrderItems(StoreOrder $order)
    {
        // CORRECTED: Eager load 'sapMasterfiles' (plural)
        return $order->store_order_items()->with('supplierItem.sapMasterfiles')->get();
    }

    public function getImageAttachments(StoreOrder $order)
    {
        return $order->image_attachments->map(function ($image) {
            return [
                'id' => $image->id,
                'image_url' => Storage::url($image->image_path)
            ];
        });
    }

    public function getOrder($id, $page = null)
    {
        // CORRECTED: Eager load 'sapMasterfiles' (plural) for store_order_items
        $order = StoreOrder::with(['store_branch', 'supplier', 'store_order_items.supplierItem.sapMasterfiles'])
            ->where('order_number', $id)->firstOrFail();

        if ($page && $page !== 'cs') {
            if ($order->order_status !== OrderRequestStatus::PENDING->value)
                abort(401, 'Order can no longer be updated');
        }

        return $order;
    }

    public function updateOrder(StoreOrder $order, array $data)
    {
        DB::beginTransaction();
        try {
            $supplier = Supplier::where('supplier_code', $data['supplier_id'])->first();

            if (!$supplier) {
                throw new Exception("Supplier with code '{$data['supplier_id']}' not found for update.");
            }

            if ($order->store_branch_id != $data['branch_id']) {
                $order->order_number = $this->getOrderNumber($data['branch_id']);
            }

            $order->update([
                'supplier_id' => $supplier->id,
                'store_branch_id' => $data['branch_id'],
                'order_date' => Carbon::parse($data['order_date'])->format('Y-m-d'),
                'variant' => $data['variant'] ?? 'regular',
            ]);

            // Get the ItemCodes (strings) of the items currently in the frontend's order list
            // The frontend sends 'inventory_code' which holds the SupplierItems.ItemCode string
            $updatedItemCodesFromFrontend = collect($data['orders'])->pluck('inventory_code')->toArray();
            
            // --- Diagnostic Logging ---
            Log::debug('StoreOrderService@updateOrder: $updatedItemCodesFromFrontend values:', $updatedItemCodesFromFrontend);
            // --- End Diagnostic Logging ---

            // Delete existing StoreOrderItems that are NOT in the updated list (based on ItemCode)
            // This now correctly compares the 'item_code' (VARCHAR) in the database with the 'ItemCode' strings from the frontend.
            $order->store_order_items()
                ->whereNotIn('item_code', $updatedItemCodesFromFrontend)
                ->delete();

            foreach ($data['orders'] as $itemData) {
                // Determine if it's an existing StoreOrderItem or a new one
                // An existing item will have a numeric 'id' (StoreOrderItem's primary key)
                // A new item will have 'id' as null (from frontend)
                
                // If itemData contains a numeric 'id' and it's not null, it's an existing StoreOrderItem record
                if (isset($itemData['id']) && is_numeric($itemData['id']) && $itemData['id'] !== null) {
                    // Update existing item by its primary key (StoreOrderItem ID)
                    $orderItem = StoreOrderItem::find($itemData['id']);
                    if ($orderItem) {
                        $orderItem->update([
                            'quantity_ordered' => $itemData['quantity'],
                            'total_cost' => $itemData['total_cost'],
                            'cost_per_quantity' => $itemData['cost'],
                            'uom' => $itemData['uom'] ?? null
                        ]);
                    } else {
                        // This case should ideally not happen if 'id' is always for existing items,
                        // but if an item with a given ID is somehow missing, we log it and create it.
                        Log::warning("StoreOrderItem with ID {$itemData['id']} not found for update in updateOrder. Creating new entry.");
                        $order->store_order_items()->create([
                            'item_code' => $itemData['inventory_code'], // Use ItemCode string
                            'quantity_ordered' => $itemData['quantity'],
                            'total_cost' => $itemData['total_cost'],
                            'cost_per_quantity' => $itemData['cost'],
                            'uom' => $itemData['uom'] ?? null
                        ]);
                    }
                } else {
                    // This is a new item (or an item that was removed and re-added, which will be treated as new)
                    // Use updateOrCreate to create new ones or update if an item with the same item_code
                    // already exists for this order but was not passed with its original StoreOrderItem ID.
                    $order->store_order_items()->updateOrCreate(
                        [
                            'store_order_id' => $order->id,
                            'item_code' => $itemData['inventory_code'], // Use ItemCode string for matching
                        ],
                        [
                            'quantity_ordered' => $itemData['quantity'],
                            'total_cost' => $itemData['total_cost'],
                            'cost_per_quantity' => $itemData['cost'],
                            'uom' => $itemData['uom'] ?? null
                        ]
                    );
                }
            }

            $order->save(); // Save any changes to the order itself (like order_number)
            DB::commit();
            return $order;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error in StoreOrderService@updateOrder: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }
}
