<?php

namespace App\Http\Controllers;

use App\Enums\WastageStatus;
use App\Models\Wastage;
use App\Models\StoreBranch;
use App\Models\UserAssignedStoreBranch;
use App\Http\Services\WastageService;
use App\Models\ProductInventoryStockManager;
use App\Models\SupplierItems;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Illuminate\Http\RedirectResponse;

class WastageApprovalLevel2Controller extends Controller
{
    protected $wastageService;

    public function __construct(WastageService $wastageService)
    {
        $this->wastageService = $wastageService;
    }

    /**
     * Display a listing of wastage records for level 2 approval
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $currentFilter = $request->get('currentFilter', 'approved_lvl1');
        $search = $request->get('search');

        // Get user's assigned stores
        $assignedStoreIds = UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();

        $filters = [
            'status' => $currentFilter,
            'store_branch_id' => $request->get('store_branch_id'),
            'date_range' => $request->get('date_range'),
            'search' => $search,
        ];

        // Get grouped wastage records for level 2 approval
        // Only show records that are approved at level 1
        $wastages = $this->wastageService->getGroupedWastageRecordsForUser($user, $filters);

        // Get counts for status tabs - Level 2 specific
        $statistics = $this->wastageService->getWastageStatistics($assignedStoreIds);

        $counts = [
            'all' => $statistics['total'] ?? 0,
            'approved_lvl1' => $statistics['approved_lvl1'] ?? 0,
            'cancelled' => $statistics['cancelled'] ?? 0,
        ];

        return Inertia::render('WastageApprovalLevel2/Index', [
            'wastages' => $wastages,
            'counts' => $counts,
            'filters' => $request->only(['currentFilter', 'search']),
            'stores' => StoreBranch::whereIn('id', $assignedStoreIds)->get()
                ->map(fn($store) => [
                    'value' => $store->id,
                    'label' => $store->name . ' (' . $store->branch_code . ')',
                ]),
        ]);
    }

    /**
     * Display the specified wastage record for level 2 approval
     */
    public function show(Wastage $wastage)
    {
        $user = Auth::user();

        // Debug logging
        \Log::info('WastageApprovalLevel2 show method called', [
            'wastage_id' => $wastage->id,
            'wastage_no' => $wastage->wastage_no,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'wastage_store_branch_id' => $wastage->store_branch_id,
        ]);

        // Check if user has access to this wastage record
        $assignedStoreIds = UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();

        \Log::info('User assigned stores', [
            'assigned_store_ids' => $assignedStoreIds,
            'assigned_store_count' => count($assignedStoreIds),
        ]);

        // Check if user has access to ANY record in this wastage group (wastage_no)
        $hasAccessToGroup = Wastage::where('wastage_no', $wastage->wastage_no)
            ->whereIn('store_branch_id', $assignedStoreIds)
            ->exists();

        \Log::info('Wastage group access check', [
            'wastage_no' => $wastage->wastage_no,
            'has_access_to_group' => $hasAccessToGroup,
            'query_sql' => Wastage::where('wastage_no', $wastage->wastage_no)
                ->whereIn('store_branch_id', $assignedStoreIds)
                ->toSql(),
        ]);

        if (!$hasAccessToGroup) {
            \Log::warning('Access denied for wastage record', [
                'wastage_id' => $wastage->id,
                'wastage_no' => $wastage->wastage_no,
                'wastage_store_id' => $wastage->store_branch_id,
                'user_assigned_stores' => $assignedStoreIds,
            ]);
            abort(403, 'You do not have permission to view this wastage record');
            }

        // Validate wastage record exists and has valid data
        if (!$wastage->wastage_no) {
            \Log::error('Invalid wastage record - missing wastage_no', [
                'wastage_id' => $wastage->id,
                'wastage_data' => $wastage->toArray(),
            ]);
            abort(404, 'Wastage record not found or invalid');
        }

        if (empty($assignedStoreIds)) {
            \Log::warning('User has no assigned stores', [
                'user_id' => $user->id,
                'user_email' => $user->email,
            ]);
            abort(403, 'You do not have any assigned stores. Please contact administrator.');
        }

        // Load the primary wastage record with relationships
        try {
            $wastage->load([
                'storeBranch',
                'encoder',
                'approver1',
                'approver2',
                'canceller'
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to load wastage relationships', [
                'wastage_id' => $wastage->id,
                'error' => $e->getMessage(),
            ]);
            abort(500, 'Failed to load wastage record details');
        }

        // Fetch all wastage records with the same wastage_no (grouped transaction)
        $relatedWastageRecords = Wastage::where('wastage_no', $wastage->wastage_no)
            ->with(['sapMasterfile'])
            ->get();

        // Structure the data to match what Vue component expects
        $wastageData = [
            'id' => $wastage->id,
            'wastage_no' => $wastage->wastage_no,
            'store_branch_id' => $wastage->store_branch_id,
            'wastage_reason' => $wastage->reason,
            'wastage_status' => $wastage->wastage_status,
            'created_by' => $wastage->created_by,
            'created_at' => $wastage->created_at,
            'updated_at' => $wastage->updated_at,
            'storeBranch' => $wastage->storeBranch,
            'encoder' => $wastage->encoder,
            'approver1' => $wastage->approver1,
            'approver2' => $wastage->approver2,
            'canceller' => $wastage->canceller,
            'approved_level1_date' => $wastage->approved_level1_date,
            'approved_level2_date' => $wastage->approved_level2_date,
            'cancelled_date' => $wastage->cancelled_date,
            'image_urls' => json_decode($wastage->image_url, true) ?? [],
            'items' => $relatedWastageRecords->map(function ($record) {
                return [
                    'id' => $record->id,
                    'sap_masterfile_id' => $record->sap_masterfile_id,
                    'wastage_qty' => $record->wastage_qty,
                    'approverlvl1_qty' => $record->approverlvl1_qty,
                    'approverlvl2_qty' => $record->approverlvl2_qty,
                    'cost' => $record->cost,
                    'reason' => $record->reason,
                    'sap_masterfile' => $record->sapMasterfile ? [
                        'id' => $record->sapMasterfile->id,
                        'ItemCode' => $record->sapMasterfile->ItemCode,
                        'ItemDescription' => $record->sapMasterfile->ItemDescription,
                        'BaseUOM' => $record->sapMasterfile->BaseUOM,
                        'AltUOM' => $record->sapMasterfile->AltUOM,
                    ] : null,
                ];
            })->toArray(),
        ];

        return Inertia::render('WastageApprovalLevel2/Show', [
            'wastage' => $wastageData,
            'permissions' => [
                'can_approve' => $user->hasPermissionTo('approve wastage level 2'),
                'can_edit' => $user->hasPermissionTo('edit wastage approval level 2'),
                'can_delete' => $user->hasPermissionTo('delete wastage approval level 2'),
            ],
        ]);
    }

    /**
     * Approve wastage record at level 2
     */
    public function approve(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'remarks' => 'nullable|string|max:1000',
        ]);

        try {
            $wastage = Wastage::findOrFail($validated['order_id']);
            $storeBranchId = $wastage->store_branch_id;

            // Authorization Check
            $assignedStoreIds = UserAssignedStoreBranch::where('user_id', $user->id)
                ->pluck('store_branch_id')
                ->toArray();
            if (!in_array($storeBranchId, $assignedStoreIds)) {
                abort(403, 'You do not have permission to approve this wastage record');
            }

            // Get all related wastage items that are ready for level 2 approval
            $relatedWastages = Wastage::where('wastage_no', $wastage->wastage_no)
                ->where('wastage_status', WastageStatus::APPROVED_LVL1)
                ->with('sapMasterfile')
                ->get();

            if ($relatedWastages->isEmpty()) {
                return back()->with('info', 'No items are awaiting Level 2 approval.');
            }

            \DB::beginTransaction();

            // Group items by their base ItemCode
            $groupedItems = $relatedWastages->filter(fn($item) => $item->sapMasterfile !== null)
                                            ->groupBy('sapMasterfile.ItemCode');

            foreach ($groupedItems as $itemCode => $items) {
                $totalQtyToDeductInBaseUom = 0;
                $totalCostOfWastage = 0;
                $targetSapMasterfile = null;

                // 1. Aggregate quantities for this group into BaseUOM
                foreach ($items as $item) {
                    // Skip 'Scrap' items from SOH deduction
                    if ($item->reason === 'Scrap') {
                        continue;
                    }

                    $originalSapMasterfile = $item->sapMasterfile;
                    $conversionFactor = $originalSapMasterfile->BaseQty > 0 ? $originalSapMasterfile->BaseQty : 1;
                    $approvedQty = $item->approverlvl2_qty ?? $item->approverlvl1_qty ?? $item->wastage_qty;

                    $totalQtyToDeductInBaseUom += $approvedQty * $conversionFactor;
                    $totalCostOfWastage += $approvedQty * $item->cost;
                }
                
                // If all items in group were 'Scrap', skip to next group
                if ($totalQtyToDeductInBaseUom <= 0) {
                    continue;
                }

                // 2. Find the single target masterfile for SOH update (where BaseUOM = AltUOM)
                $targetSapMasterfile = \App\Models\SAPMasterfile::where('ItemCode', $itemCode)
                    ->whereColumn('BaseUOM', 'AltUOM')
                    ->first();

                if (!$targetSapMasterfile) {
                    \Log::warning('SOH Update Skipped: No target SAP Masterfile (BaseUOM=AltUOM) found for ItemCode.', ['item_code' => $itemCode]);
                    continue;
                }
                
                // 3. Find and update the ProductInventoryStock record
                $productStock = \App\Models\ProductInventoryStock::where('product_inventory_id', $targetSapMasterfile->id)
                    ->where('store_branch_id', $storeBranchId)
                    ->first();

                if (!$productStock || $productStock->quantity < $totalQtyToDeductInBaseUom) {
                     throw new \Exception("Insufficient stock for item {$targetSapMasterfile->ItemDescription}. Available: " . ($productStock->quantity ?? 0) . ", Required: {$totalQtyToDeductInBaseUom}");
                }

                // Decrement stock and update 'used'
                $productStock->quantity -= $totalQtyToDeductInBaseUom;
                $productStock->used += $totalQtyToDeductInBaseUom;
                $productStock->save();
                
                // 4. Create a single stock manager entry for the aggregated deduction
                ProductInventoryStockManager::create([
                    'product_inventory_id' => $targetSapMasterfile->id,
                    'store_branch_id' => $storeBranchId,
                    'quantity' => $totalQtyToDeductInBaseUom,
                    'action' => 'out',
                    'unit_cost' => $totalCostOfWastage / ($totalQtyToDeductInBaseUom ?: 1), // Avoid division by zero
                    'total_cost' => $totalCostOfWastage,
                    'transaction_date' => now(),
                    'remarks' => 'Wastage Approval Level 2: ' . $wastage->wastage_no,
                ]);
            }
            
            // 5. Update status for all processed items
            Wastage::where('wastage_no', $wastage->wastage_no)
                ->where('wastage_status', WastageStatus::APPROVED_LVL1)
                ->update([
                    'wastage_status' => WastageStatus::APPROVED_LVL2->value,
                    'approved_level2_by' => $user->id,
                    'approved_level2_date' => now(),
                ]);
                
            // Set approverlvl2_qty for items that didn't have it set
            foreach ($relatedWastages as $item) {
                 if (is_null($item->approverlvl2_qty)) {
                    $item->approverlvl2_qty = $item->approverlvl1_qty ?? $item->wastage_qty;
                    $item->save();
                }
            }

            \DB::commit();

            return redirect()
                ->route('wastage-approval-lvl2.show', $wastage->id)
                ->with('success', 'Wastage record approved at level 2 successfully. Inventory stock updated.');

        } catch (\Exception $e) {
            \DB::rollBack();

            \Log::error('Failed to approve wastage record at level 2: ' . $e->getMessage(), [
                'wastage_id' => $validated['order_id'] ?? null,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()
                ->with('error', 'Failed to approve wastage record: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Cancel wastage record from level 2
     */
    public function cancel(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'remarks' => 'nullable|string|max:1000',
        ]);

        try {
            $wastage = Wastage::findOrFail($validated['order_id']);

            // Check if user can cancel this wastage record
            $assignedStoreIds = UserAssignedStoreBranch::where('user_id', $user->id)
                ->pluck('store_branch_id')
                ->toArray();

            if (!in_array($wastage->store_branch_id, $assignedStoreIds)) {
                abort(403, 'You do not have permission to cancel this wastage record');
            }

            // Update all records with the same wastage_no
            Wastage::where('wastage_no', $wastage->wastage_no)
                ->update([
                    'wastage_status' => WastageStatus::CANCELLED,
                    'cancelled_by' => $user->id,
                    'cancelled_date' => now(),
                ]);

            return redirect()
                ->route('wastage-approval-lvl2.show', $wastage->id)
                ->with('success', 'Wastage record cancelled successfully.');

        } catch (\Exception $e) {
            \Log::error('Failed to cancel wastage record: ' . $e->getMessage(), [
                'wastage_id' => $validated['order_id'] ?? null,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return back()
                ->with('error', 'Failed to cancel wastage record: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Update quantity for wastage approval level 2
     */
    public function updateQuantity(Request $request, $itemId): RedirectResponse
    {
        $user = Auth::user();
        $validated = $request->validate([
            'approverlvl2_qty' => 'required|numeric|min:0',
        ]);

        try {
            $wastage = Wastage::findOrFail($itemId);

            // Check if user can edit this wastage record
            $assignedStoreIds = UserAssignedStoreBranch::where('user_id', $user->id)
                ->pluck('store_branch_id')
                ->toArray();

            if (!in_array($wastage->store_branch_id, $assignedStoreIds)) {
                abort(403, 'You do not have permission to edit this wastage record');
            }

            $wastage->update([
                'approverlvl2_qty' => $validated['approverlvl2_qty'],
            ]);

            return back()
                ->with('success', 'Quantity updated successfully.');

        } catch (\Exception $e) {
            \Log::error('Failed to update wastage quantity: ' . $e->getMessage(), [
                'wastage_id' => $itemId,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return back()
                ->with('error', 'Failed to update quantity: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Delete a single wastage item.
     */
    public function destroyItem($itemId): RedirectResponse
    {
        $user = Auth::user();

        try {
            $wastage = Wastage::findOrFail($itemId);

            // Check if user can delete this wastage record based on store assignment
            $assignedStoreIds = UserAssignedStoreBranch::where('user_id', $user->id)
                ->pluck('store_branch_id')
                ->toArray();

            if (!in_array($wastage->store_branch_id, $assignedStoreIds)) {
                abort(403, 'You do not have permission to delete this item.');
            }

            // Check if the item is in a state that allows deletion (approved level 1)
            if ($wastage->wastage_status !== WastageStatus::APPROVED_LVL1) {
                return back()->with('error', 'Only items in APPROVED LEVEL 1 status can be deleted.');
            }

            $wastage->delete();

            return back()->with('success', 'Item deleted successfully.');

        } catch (\Exception $e) {
            \Log::error('Failed to delete wastage item: ' . $e->getMessage(), [
                'wastage_id' => $itemId,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return back()->with('error', 'Failed to delete item: ' . $e->getMessage());
        }
    }
}