<?php

namespace App\Http\Services;

use App\Models\StoreOrder;
use App\Models\StoreBranch;
use App\Models\ProductInventoryStock;
use App\Models\SAPMasterfile;
use App\Enums\IntercoStatus;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class IntercoService extends StoreOrderService
{
    /**
     * Generate Interco number format: {receiving_store_code}-{sending_store_code}-00001
     */
    public function generateIntercoNumber($receivingStore, $sendingStore): string
    {
        $receivingCode = $receivingStore->branch_code;
        $sendingCode = $sendingStore->branch_code;

        // Get the latest interco number for this store pair
        $latestInterco = StoreOrder::where('interco_number', 'like', "{$receivingCode}-{$sendingCode}-%")
            ->orderBy('interco_number', 'desc')
            ->first();

        if ($latestInterco) {
            // Extract the last sequence number and increment
            $lastSequence = (int) substr($latestInterco->interco_number, -5);
            $newSequence = $lastSequence + 1;
        } else {
            $newSequence = 1;
        }

        return sprintf("%s-%s-%05d", $receivingCode, $sendingCode, $newSequence);
    }

    /**
     * Validate that sending store has sufficient stock for all items
     */
    public function validateSendingStoreStock(StoreOrder $order): array
    {
        $errors = [];
        $items = $order->store_order_items;

        foreach ($items as $item) {
            // Get the SAP masterfile to map item code to numeric ID
            $sapMasterfile = SAPMasterfile::where('ItemCode', $item->item_code)
                ->where('is_active', true)
                ->first();

            if (!$sapMasterfile) {
                $itemCode = $item->sapMasterfile ? $item->sapMasterfile->item_code : $item->item_code;
                $errors[] = "Item {$itemCode} not found in product masterfile";
                continue;
            }

            // Use the correct numeric ID for stock lookup
            $sendingStock = ProductInventoryStock::where('product_inventory_id', $sapMasterfile->id)
                ->where('store_branch_id', $order->sending_store_branch_id)
                ->first();

            if (!$sendingStock) {
                $itemCode = $item->sapMasterfile ? $item->sapMasterfile->item_code : $item->item_code;
                $errors[] = "Item {$itemCode} has no stock record in sending store";
                continue;
            }

            if ($sendingStock->quantity < $item->quantity_approved) {
                $available = $sendingStock->quantity;
                $requested = $item->quantity_approved;
                $itemName = $item->sapMasterfile ? $item->sapMasterfile->description : 'Unknown Item';
                $errors[] = "Insufficient stock for {$itemName}: Available: {$available}, Requested: {$requested}";
            }
        }

        return $errors;
    }

    /**
     * Update interco status with validation
     */
    public function updateIntercoStatus(StoreOrder $order, IntercoStatus $newStatus, ?int $userId = null): StoreOrder
    {
        $currentStatus = $order->interco_status;

        // Validate status transitions
        if (!$this->isValidStatusTransition($currentStatus, $newStatus)) {
            throw new Exception("Invalid status transition from {$currentStatus->getLabel()} to {$newStatus->getLabel()}");
        }

        DB::beginTransaction();
        try {
            $order->interco_status = $newStatus;

            // Set appropriate action dates
            if ($newStatus === IntercoStatus::APPROVED) {
                $order->approval_action_date = Carbon::now();
            } elseif ($newStatus === IntercoStatus::COMMITTED) {
                $order->commited_action_date = Carbon::now();
            }

            $order->save();

            DB::commit();
            return $order;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception("Failed to update interco status: " . $e->getMessage());
        }
    }

    /**
     * Check if status transition is valid
     */
    private function isValidStatusTransition(?IntercoStatus $current, IntercoStatus $new): bool
    {
        if (!$current) {
            return $new === IntercoStatus::OPEN;
        }

        return match([$current, $new]) {
            // OPEN can go to APPROVED or DISAPPROVED
            [IntercoStatus::OPEN, IntercoStatus::APPROVED] => true,
            [IntercoStatus::OPEN, IntercoStatus::DISAPPROVED] => true,

            // APPROVED can go to COMMITTED or DISAPPROVED
            [IntercoStatus::APPROVED, IntercoStatus::COMMITTED] => true,
            [IntercoStatus::APPROVED, IntercoStatus::DISAPPROVED] => true,

            // COMMITTED can go to IN_TRANSIT or RECEIVED
            [IntercoStatus::COMMITTED, IntercoStatus::IN_TRANSIT] => true,
            [IntercoStatus::COMMITTED, IntercoStatus::RECEIVED] => true,

            // IN_TRANSIT can go to RECEIVED
            [IntercoStatus::IN_TRANSIT, IntercoStatus::RECEIVED] => true,

            default => false
        };
    }

    /**
     * Get interco orders for a store (both as sender and receiver)
     */
    public function getIntercoOrdersForStore(int $storeId, ?string $status = null)
    {
        $query = StoreOrder::whereNotNull('interco_number')
            ->whereNotNull('sending_store_branch_id')
            ->with(['store_branch', 'sendingStore', 'encoder', 'store_order_items.sapMasterfile'])
            ->where(function($q) use ($storeId) {
                $q->where('store_branch_id', $storeId)
                  ->orWhere('sending_store_branch_id', $storeId);
            });

        if ($status) {
            $query->where('interco_status', $status);
        }

        return $query->latest()->paginate(10);
    }

    /**
     * Get interco orders that need approval for current user
     */
    public function getIntercoOrdersForApproval()
    {
        return StoreOrder::whereNotNull('interco_number')
            ->whereNotNull('sending_store_branch_id')
            ->where('interco_status', IntercoStatus::OPEN)
            ->with(['store_branch', 'sendingStore', 'encoder', 'store_order_items.sapMasterfile'])
            ->latest()
            ->paginate(10);
    }

    /**
     * Get interco orders ready for commitment
     */
    public function getIntercoOrdersForCommitment()
    {
        return StoreOrder::whereNotNull('interco_number')
            ->whereNotNull('sending_store_branch_id')
            ->where('interco_status', IntercoStatus::APPROVED)
            ->with(['store_branch', 'sendingStore', 'encoder', 'store_order_items.sapMasterfile'])
            ->latest()
            ->paginate(10);
    }

    /**
     * Get interco orders ready for receiving
     */
    public function getIntercoOrdersForReceiving(int $storeId)
    {
        return StoreOrder::whereNotNull('interco_number')
            ->whereNotNull('sending_store_branch_id')
            ->where('store_branch_id', $storeId)
            ->whereIn('interco_status', [IntercoStatus::COMMITTED, IntercoStatus::IN_TRANSIT])
            ->with(['store_branch', 'sendingStore', 'encoder', 'store_order_items.sapMasterfile'])
            ->latest()
            ->paginate(10);
    }

    /**
     * Check if user can perform action on interco order
     */
    public function canUserPerformAction(StoreOrder $order, string $action, $user): bool
    {
        switch ($action) {
            case 'edit':
                return $order->canBeEditedByUser($user);
            case 'approve':
                return $order->canBeApprovedByUser($user);
            case 'commit':
                return $order->canBeCommittedByUser($user);
            case 'receive':
                return $order->canBeReceivedByUser($user);
            default:
                return false;
        }
    }

    /**
     * Get summary statistics for interco transfers
     */
    public function getIntercoStatistics(?array $storeIds = null): array
    {
        $query = StoreOrder::whereNotNull('interco_number')
            ->whereNotNull('sending_store_branch_id');

        if ($storeIds) {
            $query->where(function($q) use ($storeIds) {
                $q->whereIn('store_branch_id', $storeIds)
                  ->orWhereIn('sending_store_branch_id', $storeIds);
            });
        }

        return [
            'total' => $query->count(),
            'open' => $query->clone()->where('interco_status', IntercoStatus::OPEN)->count(),
            'approved' => $query->clone()->where('interco_status', IntercoStatus::APPROVED)->count(),
            'committed' => $query->clone()->where('interco_status', IntercoStatus::COMMITTED)->count(),
            'in_transit' => $query->clone()->where('interco_status', IntercoStatus::IN_TRANSIT)->count(),
            'received' => $query->clone()->where('interco_status', IntercoStatus::RECEIVED)->count(),
            'disapproved' => $query->clone()->where('interco_status', IntercoStatus::DISAPPROVED)->count(),
        ];
    }
}