<?php

namespace App\Http\Services;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\Supplier; // Import the Supplier model
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Added for logging

class CSCommitService extends OrderApprovalService // Assuming OrderApprovalService exists and provides base functionality
{
    /**
     * Get orders and their counts based on type, assigned suppliers, and the currently selected supplier filter.
     *
     * @param string $page This parameter corresponds to $type (e.g., 'cs').
     * @param mixed $condition This parameter corresponds to $assignedSupplierCodes.
     * @param mixed $variant This parameter corresponds to $selectedSupplierCode.
     * @param string $statusFilter This parameter is now effectively ignored for filtering, but kept for compatibility.
     * @return array Contains 'orders' (paginated) and 'counts'.
     */
    public function getOrdersAndCounts($page = 'manager', $condition = null, $variant = null, $statusFilter = 'approved') // Default statusFilter to 'approved'
    {
        // Map the generic parent parameters to our specific needs
        $type = $page;
        $assignedSupplierCodes = (array) $condition; // Ensure it's an array
        $selectedSupplierCode = $variant ?? 'all'; // Ensure it defaults to 'all' if null

        Log::debug("CSCommitService: getOrdersAndCounts called.");
        Log::debug("CSCommitService: Incoming type: {$type}, assignedSupplierCodes: " . json_encode($assignedSupplierCodes) . ", selectedSupplierCode: {$selectedSupplierCode}, statusFilter (ignored for query): {$statusFilter}");

        // Get the search query from the request, if available
        $search = request('search');
        Log::debug("CSCommitService: Search term: " . ($search ?? 'N/A'));

        // --- Step 1: Get the IDs of the suppliers that match the assigned supplier codes ---
        $assignedSupplierIds = Supplier::whereIn('supplier_code', $assignedSupplierCodes)
                                       ->pluck('id')
                                       ->toArray();

        Log::debug("CSCommitService: Mapped assignedSupplierIds from codes: " . json_encode($assignedSupplierIds));

        // If no suppliers are assigned or found, return empty results
        if (empty($assignedSupplierIds)) {
            Log::warning("CSCommitService: No assigned supplier IDs found or provided. Returning empty results.");
            return [
                'orders' => StoreOrder::whereRaw('1=0')->paginate(10)->withQueryString(), // Return empty paginated collection
                'counts' => ['all_approved' => 0], // Only count for 'all_approved' now
            ];
        }

        // --- Prepare the base query for orders ---
        $ordersQuery = StoreOrder::query()
            ->with(['supplier', 'store_branch', 'encoder', 'approver', 'commiter']);

        // Always filter orders by the user's assigned supplier IDs
        $ordersQuery->whereIn('supplier_id', $assignedSupplierIds);
        Log::debug("CSCommitService: Applied base filter by assigned supplier IDs: " . json_encode($assignedSupplierIds));


        // Apply specific supplier filter if a tab other than 'all' is selected
        if ($selectedSupplierCode !== 'all') {
            // Get the ID for the specifically selected supplier code
            $specificSupplierId = Supplier::where('supplier_code', $selectedSupplierCode)->value('id');
            Log::debug("CSCommitService: Specific selected supplier code '{$selectedSupplierCode}' mapped to ID: " . ($specificSupplierId ?? 'N/A'));

            if ($specificSupplierId) {
                $ordersQuery->where('supplier_id', $specificSupplierId);
                Log::debug("CSCommitService: Applied specific supplier filter by ID: {$specificSupplierId}");
            } else {
                // If a specific supplier code is selected but its ID is not found,
                // return an empty result set for orders.
                $ordersQuery->whereRaw('1=0'); // Force no results
                Log::warning("CSCommitService: Specific supplier ID not found for '{$selectedSupplierCode}'. Forcing empty order results.");
            }
        }

        // Apply search filter if a search term is provided
        if ($search) {
            $ordersQuery->where(function ($query) use ($search) {
                $query->where('order_number', 'like', '%' . $search . '%')
                      ->orWhereHas('supplier', function ($q) use ($search) {
                          $q->where('name', 'like', '%' . $this->search . '%');
                      })
                      ->orWhereHas('store_branch', function ($q) use ($search) {
                          $q->where('name', 'like', '%' . $this->search . '%');
                      });
            });
            Log::debug("CSCommitService: Applied search filter: '{$search}'");
        }

        // Apply static status filter: ALWAYS filter by 'approved'
        $ordersQuery->where('order_status', OrderStatus::APPROVED->value);
        Log::debug("CSCommitService: Applied static order status filter: " . OrderStatus::APPROVED->value);


        // Order the results
        $orders = $ordersQuery->orderBy('created_at', 'desc')->paginate(10)->withQueryString();
        Log::debug("CSCommitService: Orders query executed. Total orders found: " . $orders->total());

        // --- Calculate counts for 'all' and each assigned supplier (only for 'approved' status) ---
        $counts = [];

        // Count for the 'all' suppliers tab, specifically for 'approved' status
        $counts['all_approved'] = StoreOrder::query()
            ->whereIn('supplier_id', $assignedSupplierIds)
            ->where('order_status', OrderStatus::APPROVED->value)
            ->count();
        Log::debug("CSCommitService: Count for 'all_approved' tab: {$counts['all_approved']}");

        // Counts for each individual assigned supplier, specifically for 'approved' status
        foreach ($assignedSupplierCodes as $supplierCode) {
            $supplierIdForCount = Supplier::where('supplier_code', $supplierCode)->value('id');
            if ($supplierIdForCount) {
                $counts["{$supplierCode}_approved"] = StoreOrder::query()
                    ->where('supplier_id', $supplierIdForCount)
                    ->where('order_status', OrderStatus::APPROVED->value)
                    ->count();
                Log::debug("CSCommitService: Count for supplier '{$supplierCode}' (ID: {$supplierIdForCount}) and status 'approved': {$counts["{$supplierCode}_approved"]}");
            } else {
                $counts["{$supplierCode}_approved"] = 0; // Supplier not found or inactive
                Log::debug("CSCommitService: Supplier '{$supplierCode}' not found or inactive. Count for 'approved' set to 0.");
            }
        }

        Log::debug("CSCommitService: Final counts array: " . json_encode($counts));
        Log::debug("CSCommitService: getOrdersAndCounts finished.");

        return [
            'orders' => $orders,
            'counts' => $counts,
        ];
    }

    public function commitOrder(array $data)
    {
        DB::beginTransaction();
        $storeOrder = StoreOrder::findOrFail($data['id']);
        $storeOrder->update([
            'order_status' => OrderStatus::COMMITED->value,
            'commiter_id' => Auth::user()->id,
            'commited_action_date' => Carbon::now()
        ]);

        foreach ($data['updatedOrderedItemDetails'] as $item) {
            $orderedItem = StoreOrderItem::find($item['id']);
            $orderedItem->update([
                'total_cost' => $item['total_cost'],
                'quantity_commited' => $item['quantity_approved'],
            ]);

            // Re-enabled this line as per your new requirement.
            // It now creates a 'pending' receive date entry when committed.
            $orderedItem->ordered_item_receive_dates()->create([
                'received_by_user_id' => Auth::user()->id, // The committer is the one who "virtually receives" at this stage
                'quantity_received' => $item['quantity_approved'], // Quantity committed is the quantity "received" at this stage
                'status' => 'pending', // Mark as pending approval for actual receiving
                'received_date' => Carbon::now(), // Timestamp for when it was "committed received"
            ]);
        }
        $this->addRemarks($storeOrder, $data['remarks']);

        DB::commit();
    }
}
