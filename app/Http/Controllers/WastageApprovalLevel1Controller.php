<?php

namespace App\Http\Controllers;

use App\Enums\WastageStatus;
use App\Models\Wastage;
use App\Models\StoreBranch;
use App\Models\UserAssignedStoreBranch;
use App\Http\Services\WastageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Illuminate\Http\RedirectResponse;

class WastageApprovalLevel1Controller extends Controller
{
    protected $wastageService;

    public function __construct(WastageService $wastageService)
    {
        $this->wastageService = $wastageService;
    }

    /**
     * Display a listing of wastage records for level 1 approval
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $currentFilter = $request->get('currentFilter', 'pending');
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

        // Get grouped wastage records for approval
        $wastages = $this->wastageService->getGroupedWastageRecordsForUser($user, $filters);

        // Get counts for status tabs
        $statistics = $this->wastageService->getWastageStatistics($assignedStoreIds);

        $counts = [
            'all' => $statistics['total'] ?? 0,
            'pending' => $statistics['pending'] ?? 0,
            'cancelled' => $statistics['cancelled'] ?? 0,
        ];

        return Inertia::render('WastageApprovalLevel1/Index', [
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
     * Display the specified wastage record for approval
     */
    public function show(Wastage $wastage)
    {
        $user = Auth::user();

        // Debug logging
        \Log::info('WastageApprovalLevel1 show method called', [
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

        return Inertia::render('WastageApprovalLevel1/Show', [
            'wastage' => $wastageData,
            'permissions' => [
                'can_approve' => $user->hasPermissionTo('approve wastage level 1'),
                'can_edit' => $user->hasPermissionTo('edit wastage approval level 1'),
                'can_delete' => $user->hasPermissionTo('delete wastage approval level 1'),
            ],
        ]);
    }

    /**
     * Approve wastage record at level 1
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

            // Check if user can approve this wastage record
            $assignedStoreIds = UserAssignedStoreBranch::where('user_id', $user->id)
                ->pluck('store_branch_id')
                ->toArray();

            if (!in_array($wastage->store_branch_id, $assignedStoreIds)) {
                abort(403, 'You do not have permission to approve this wastage record');
            }

            // Update all records with the same wastage_no
            $relatedWastages = Wastage::where('wastage_no', $wastage->wastage_no)->get();

            foreach ($relatedWastages as $relatedWastage) {
                if (is_null($relatedWastage->approverlvl1_qty)) {
                    $relatedWastage->approverlvl1_qty = $relatedWastage->wastage_qty;
                    $relatedWastage->save();
                }
            }

            Wastage::where('wastage_no', $wastage->wastage_no)
                ->update([
                    'wastage_status' => WastageStatus::APPROVED_LVL1->value,
                    'approved_level1_by' => $user->id,
                    'approved_level1_date' => now(),
                ]);

            return redirect()
                ->route('wastage-approval-lvl1.show', $wastage->id)
                ->with('success', 'Wastage record approved at level 1 successfully.');

        } catch (\Exception $e) {
            \Log::error('Failed to approve wastage record at level 1: ' . $e->getMessage(), [
                'wastage_id' => $validated['order_id'] ?? null,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return back()
                ->with('error', 'Failed to approve wastage record: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Cancel wastage record
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
                ->route('wastage-approval-lvl1.show', $wastage->id)
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
     * Update quantity for wastage approval level 1
     */
    public function updateQuantity(Request $request, $itemId): RedirectResponse
    {
        $user = Auth::user();
        $validated = $request->validate([
            'approverlvl1_qty' => 'required|numeric|min:0',
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
                'approverlvl1_qty' => $validated['approverlvl1_qty'],
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

            // Check if the item is in a state that allows deletion
            if ($wastage->wastage_status !== WastageStatus::PENDING) {
                return back()->with('error', 'Only items in PENDING status can be deleted.');
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