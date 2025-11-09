<?php

namespace App\Http\Services;

use App\Models\Wastage;
use App\Models\StoreBranch;
use App\Models\ProductInventoryStock;
use App\Models\SAPMasterfile;
use App\Enums\WastageStatus;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class WastageService
{
    /**
     * Generate Wastage number format: WASTE-{branch_code}-00001
     */
    public function generateWastageNumber(StoreBranch $store): string
    {
        $branchCode = $store->branch_code;

        // Get the latest wastage number for this store
        $latestWastage = Wastage::where('wastage_no', 'like', "WASTE-{$branchCode}-%")
            ->orderBy('wastage_no', 'desc')
            ->first();

        if ($latestWastage) {
            // Extract the last sequence number and increment
            $lastSequence = (int) substr($latestWastage->wastage_no, -5);
            $newSequence = $lastSequence + 1;
        } else {
            $newSequence = 1;
        }

        return sprintf("WASTE-%s-%05d", $branchCode, $newSequence);
    }

    /**
     * Create a new wastage record
     */
    public function createWastage(array $data, int $encoderId): Wastage
    {
        DB::beginTransaction();
        try {
            // Get store branch
            $storeBranch = StoreBranch::findOrFail($data['store_branch_id']);

            // Get product
            $product = SAPMasterfile::findOrFail($data['sap_masterfile_id']);

            // Generate wastage number
            $wastageNo = $this->generateWastageNumber($storeBranch);

            $wastage = Wastage::create([
                'wastage_no' => $wastageNo,
                'store_branch_id' => $data['store_branch_id'],
                'sap_masterfile_id' => $data['sap_masterfile_id'],
                'wastage_qty' => $data['wastage_qty'],
                'cost' => $data['cost'],
                'reason' => $data['wastage_reason'],
                'wastage_status' => WastageStatus::PENDING,
                'created_by' => $encoderId,
            ]);

            DB::commit();
            return $wastage;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception("Failed to create wastage record: " . $e->getMessage());
        }
    }

    /**
     * Create multiple wastage records from cart items
     */
    public function createMultipleWastageRecords(array $data, int $encoderId): \Illuminate\Support\Collection
    {
        DB::beginTransaction();
        try {
            $createdRecords = [];
            $storeBranch = StoreBranch::findOrFail($data['store_branch_id']);

            // Generate ONE wastage number for the entire transaction
            $wastageNo = $this->generateWastageNumber($storeBranch);

            foreach ($data['cartItems'] as $item) {
                $wastage = Wastage::create([
                    'wastage_no' => $wastageNo, // SAME for all items in transaction
                    'store_branch_id' => $data['store_branch_id'],
                    'sap_masterfile_id' => $item['sap_masterfile_id'],
                    'wastage_qty' => $item['quantity'],
                    'cost' => $item['cost'],
                    'reason' => $data['wastage_reason'],
                    'wastage_status' => WastageStatus::PENDING,
                    'created_by' => $encoderId,
                ]);

                $createdRecords[] = $wastage;
            }

            DB::commit();
            return collect($createdRecords);
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception("Failed to create wastage records: " . $e->getMessage());
        }
    }

    /**
     * Update an existing wastage record
     */
    public function updateWastage(Wastage $wastage, array $data, ?int $userId = null): Wastage
    {
        DB::beginTransaction();
        try {
            // Check if wastage can be edited
            if (!$wastage->wastage_status->canBeEdited()) {
                throw new Exception("Wastage record cannot be edited in current status: {$wastage->wastage_status->getLabel()}");
            }

            // Update fields with audit trail
            $updateData = [
                'sap_masterfile_id' => $data['sap_masterfile_id'],
                'wastage_qty' => $data['wastage_qty'],
                'cost' => $data['cost'],
                'reason' => $data['wastage_reason'],
            ];

            // Add updated_by if user ID is provided
            if ($userId) {
                $updateData['updated_by'] = $userId;
            }

            \Log::info('Updating single wastage record', [
                'wastage_id' => $wastage->id,
                'wastage_no' => $wastage->wastage_no,
                'update_data' => $updateData,
                'user_id' => $userId
            ]);

            $wastage->update($updateData);

            DB::commit();
            return $wastage;
        } catch (Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update single wastage record', [
                'error' => $e->getMessage(),
                'wastage_id' => $wastage->id,
                'wastage_no' => $wastage->wastage_no,
                'data' => $data,
                'user_id' => $userId
            ]);
            throw new Exception("Failed to update wastage record: " . $e->getMessage());
        }
    }

    /**
     * Update multiple wastage records for the same wastage_no
     */
    public function updateMultipleWastageRecords(Wastage $wastage, array $data, ?int $userId = null): array
    {
        // Pre-validate all items before transaction
        $wastageNo = $wastage->wastage_no;
        $allWastageRecords = Wastage::where('wastage_no', $wastageNo)->get();

        \Log::info('Starting multi-item wastage update with deletion support', [
            'wastage_no' => $wastageNo,
            'submitted_items_count' => count($data['items'] ?? []),
            'existing_records_count' => $allWastageRecords->count(),
            'user_id' => $userId,
            'available_records' => $allWastageRecords->pluck('id')->toArray()
        ]);

        // Validate each item before transaction - support mixed scenarios (existing + new items)
        $validationErrors = [];
        foreach ($data['items'] as $index => $itemData) {
            // Only validate items with database-style IDs (positive integers) as existing records
            if (isset($itemData['id']) && is_numeric($itemData['id']) && $itemData['id'] > 0) {
                $wastageRecord = $allWastageRecords->find($itemData['id']);

                if (!$wastageRecord) {
                    $validationErrors[] = "Existing item at index {$index}: Database record with ID {$itemData['id']} not found";
                    \Log::error('Existing wastage record not found during validation', [
                        'index' => $index,
                        'item_id' => $itemData['id'],
                        'wastage_no' => $wastageNo,
                        'available_ids' => $allWastageRecords->pluck('id')->toArray()
                    ]);
                } else {
                    \Log::info('Existing wastage record validation passed', [
                        'index' => $index,
                        'item_id' => $itemData['id'],
                        'wastage_no' => $wastageNo
                    ]);
                }
            } else {
                // New items with client-side IDs are allowed to pass validation
                \Log::info('New item validation passed (client-side ID)', [
                    'index' => $index,
                    'client_id' => $itemData['id'] ?? 'not_set',
                    'wastage_no' => $wastageNo,
                    'sap_masterfile_id' => $itemData['sap_masterfile_id'] ?? 'not_set'
                ]);
            }
        }

        if (!empty($validationErrors)) {
            $errorMessage = "Validation failed: " . implode('; ', $validationErrors);
            \Log::error('Multi-item wastage update validation failed', [
                'errors' => $validationErrors,
                'wastage_no' => $wastageNo,
                'items_data' => $data['items'],
                'total_submitted' => count($data['items'])
            ]);
            throw new Exception($errorMessage);
        }

        \Log::info('Multi-item wastage update validation passed', [
            'wastage_no' => $wastageNo,
            'total_items' => count($data['items']),
            'validation_passed' => true
        ]);

        DB::beginTransaction();
        try {
            // Check if wastage can be edited
            if (!$wastage->wastage_status->canBeEdited()) {
                throw new Exception("Wastage record cannot be edited in current status: {$wastage->wastage_status->getLabel()}");
            }

            $updatedRecords = [];
            $deletedRecords = [];
            $updateErrors = [];
            $deletionErrors = [];

            // Separate existing items from new items
            $existingItems = [];
            $newItems = [];
            $existingItemIds = [];

            foreach ($data['items'] as $index => $itemData) {
                // Check if item has a valid database ID (positive integer)
                if (isset($itemData['id']) && is_numeric($itemData['id']) && $itemData['id'] > 0) {
                    $existingItems[$index] = $itemData;
                    $existingItemIds[] = $itemData['id'];
                } else {
                    // New item with client-side ID
                    $newItems[$index] = $itemData;
                }
            }

            // Get IDs of existing items that should remain (items in cart)
            $submittedItemIds = $existingItemIds;

            // Identify items to delete (existing records not in submitted items)
            $itemsToDelete = $allWastageRecords->whereNotIn('id', $submittedItemIds);

            // Base update data
            $baseUpdateData = [
                'reason' => $data['wastage_reason'],
            ];

            // Add updated_by if user ID is provided
            if ($userId) {
                $baseUpdateData['updated_by'] = $userId;
            }

            // First, handle updates for existing items that remain in cart
            foreach ($existingItems as $index => $itemData) {
                try {
                    $wastageRecord = $allWastageRecords->find($itemData['id']);

                    if ($wastageRecord) {
                        $updateData = array_merge($baseUpdateData, [
                            'sap_masterfile_id' => $itemData['sap_masterfile_id'],
                            'wastage_qty' => $itemData['wastage_qty'],
                            'cost' => $itemData['cost'],
                        ]);

                        \Log::info('Updating wastage record', [
                            'index' => $index,
                            'wastage_id' => $wastageRecord->id,
                            'wastage_no' => $wastageNo,
                            'update_data' => $updateData
                        ]);

                        $wastageRecord->update($updateData);
                        $updatedRecords[] = $wastageRecord;

                        \Log::info('Successfully updated wastage record', [
                            'index' => $index,
                            'wastage_id' => $wastageRecord->id,
                            'wastage_no' => $wastageNo
                        ]);
                    }
                } catch (Exception $itemError) {
                    $errorKey = "Existing item at index {$index} (ID: {$itemData['id']})";
                    $updateErrors[$errorKey] = $itemError->getMessage();

                    \Log::error('Failed to update individual wastage record', [
                        'index' => $index,
                        'item_id' => $itemData['id'],
                        'error' => $itemError->getMessage(),
                        'wastage_no' => $wastageNo
                    ]);
                }
            }

            // Then, handle creation of new items added in Edit mode
            foreach ($newItems as $index => $itemData) {
                try {
                    \Log::info('Creating new wastage record in Edit mode', [
                        'index' => $index,
                        'client_id' => $itemData['id'],
                        'wastage_no' => $wastageNo,
                        'sap_masterfile_id' => $itemData['sap_masterfile_id'],
                        'wastage_qty' => $itemData['wastage_qty'],
                        'cost' => $itemData['cost'],
                        'user_id' => $userId
                    ]);

                    $newWastage = Wastage::create([
                        'wastage_no' => $wastageNo, // Use same wastage number for the group
                        'store_branch_id' => $allWastageRecords->first()->store_branch_id,
                        'sap_masterfile_id' => $itemData['sap_masterfile_id'],
                        'wastage_qty' => $itemData['wastage_qty'],
                        'cost' => $itemData['cost'],
                        'reason' => $data['wastage_reason'],
                        'wastage_status' => $allWastageRecords->first()->wastage_status,
                        'created_by' => $userId,
                    ]);

                    $updatedRecords[] = $newWastage;

                    \Log::info('Successfully created new wastage record in Edit mode', [
                        'index' => $index,
                        'client_id' => $itemData['id'],
                        'new_wastage_id' => $newWastage->id,
                        'wastage_no' => $wastageNo
                    ]);

                } catch (Exception $creationError) {
                    $errorKey = "New item at index {$index} (Client ID: {$itemData['id']})";
                    $updateErrors[$errorKey] = $creationError->getMessage();

                    \Log::error('Failed to create new wastage record in Edit mode', [
                        'index' => $index,
                        'client_id' => $itemData['id'],
                        'error' => $creationError->getMessage(),
                        'wastage_no' => $wastageNo
                    ]);
                }
            }

            // Then, handle deletions for items removed from cart
            foreach ($itemsToDelete as $recordToDelete) {
                try {
                    \Log::info('Deleting wastage record', [
                        'wastage_id' => $recordToDelete->id,
                        'wastage_no' => $wastageNo,
                        'sap_masterfile_id' => $recordToDelete->sap_masterfile_id,
                        'wastage_qty' => $recordToDelete->wastage_qty,
                        'cost' => $recordToDelete->cost,
                        'user_id' => $userId
                    ]);

                    // Store deletion info for audit trail before deleting
                    $deletionInfo = [
                        'id' => $recordToDelete->id,
                        'wastage_no' => $recordToDelete->wastage_no,
                        'sap_masterfile_id' => $recordToDelete->sap_masterfile_id,
                        'wastage_qty' => $recordToDelete->wastage_qty,
                        'cost' => $recordToDelete->cost,
                        'reason' => $recordToDelete->reason,
                        'deleted_by' => $userId,
                        'deleted_at' => now()
                    ];

                    $recordToDelete->delete();
                    $deletedRecords[] = $deletionInfo;

                    \Log::info('Successfully deleted wastage record', [
                        'wastage_id' => $recordToDelete->id,
                        'wastage_no' => $wastageNo,
                        'user_id' => $userId
                    ]);

                } catch (Exception $deletionError) {
                    $errorKey = "Deletion failed (ID: {$recordToDelete->id})";
                    $deletionErrors[$errorKey] = $deletionError->getMessage();

                    \Log::error('Failed to delete wastage record', [
                        'wastage_id' => $recordToDelete->id,
                        'wastage_no' => $wastageNo,
                        'error' => $deletionError->getMessage(),
                        'user_id' => $userId
                    ]);
                }
            }

            // Check for any errors
            $allErrors = array_merge($updateErrors, $deletionErrors);
            if (!empty($allErrors)) {
                $errorMessage = "Some operations failed: " . json_encode($allErrors);
                \Log::error('Multi-item wastage update/delete partially failed', [
                    'update_errors' => $updateErrors,
                    'deletion_errors' => $deletionErrors,
                    'successful_updates' => count($updatedRecords),
                    'successful_deletions' => count($deletedRecords),
                    'total_submitted' => count($data['items']),
                    'total_existing' => $allWastageRecords->count(),
                    'wastage_no' => $wastageNo
                ]);
                throw new Exception($errorMessage);
            }

            \Log::info('Multi-item wastage update/create/delete completed successfully', [
                'wastage_no' => $wastageNo,
                'existing_updated_count' => count($existingItems),
                'new_created_count' => count($newItems),
                'deleted_count' => count($deletedRecords),
                'total_submitted' => count($data['items']),
                'total_existing_before' => $allWastageRecords->count(),
                'user_id' => $userId
            ]);

            // Find a remaining record for redirect purposes (after commit but still in transaction)
            $remainingRecord = null;
            if (!empty($updatedRecords)) {
                // Use the first updated record for redirect
                $remainingRecord = $updatedRecords[0];
            }

            // Return updated, created and deleted records for proper feedback
            $result = [
                'updated_records' => $updatedRecords, // Includes both updated existing and newly created records
                'deleted_records' => $deletedRecords,
                'remaining_record_for_redirect' => $remainingRecord,
                'summary' => [
                    'existing_updated_count' => count($existingItems),
                    'new_created_count' => count($newItems),
                    'deleted_count' => count($deletedRecords),
                    'total_processed' => count($updatedRecords) + count($deletedRecords)
                ]
            ];

            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            \Log::error('Multi-item wastage update/delete transaction failed', [
                'error' => $e->getMessage(),
                'wastage_no' => $wastageNo,
                'items_count' => count($data['items'] ?? []),
                'user_id' => $userId
            ]);
            throw new Exception("Failed to update wastage records: " . $e->getMessage());
        }
    }

    /**
     * Update wastage status with validation
     */
    public function updateWastageStatus(Wastage $wastage, WastageStatus $newStatus, ?int $userId = null): Wastage
    {
        $currentStatus = $wastage->wastage_status;

        // Validate status transitions
        if (!$this->isValidStatusTransition($currentStatus, $newStatus)) {
            throw new Exception("Invalid status transition from {$currentStatus->getLabel()} to {$newStatus->getLabel()}");
        }

        DB::beginTransaction();
        try {
            $updateData = [
                'wastage_status' => $newStatus,
            ];

            // Set approver and action dates based on status
            if ($newStatus === WastageStatus::APPROVED_LVL1) {
                $updateData['approved_level1_by'] = $userId;
                $updateData['approved_level1_date'] = Carbon::now();
            } elseif ($newStatus === WastageStatus::APPROVED_LVL2) {
                $updateData['approved_level2_by'] = $userId;
                $updateData['approved_level2_date'] = Carbon::now();
            } elseif ($newStatus === WastageStatus::CANCELLED) {
                $updateData['cancelled_by'] = $userId;
                $updateData['cancelled_date'] = Carbon::now();
            }

            $wastage->update($updateData);

            DB::commit();
            return $wastage;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception("Failed to update wastage status: " . $e->getMessage());
        }
    }

    /**
     * Check if status transition is valid
     */
    private function isValidStatusTransition(?WastageStatus $current, WastageStatus $new): bool
    {
        if (!$current) {
            return $new === WastageStatus::PENDING;
        }

        return match([$current, $new]) {
            // PENDING can go to APPROVED_LVL1 or CANCELLED
            [WastageStatus::PENDING, WastageStatus::APPROVED_LVL1] => true,
            [WastageStatus::PENDING, WastageStatus::CANCELLED] => true,

            // APPROVED_LVL1 can go to APPROVED_LVL2 or CANCELLED
            [WastageStatus::APPROVED_LVL1, WastageStatus::APPROVED_LVL2] => true,
            [WastageStatus::APPROVED_LVL1, WastageStatus::CANCELLED] => true,

            // APPROVED_LVL2 is final
            [WastageStatus::APPROVED_LVL2, WastageStatus::APPROVED_LVL2] => true,

            // CANCELLED is final
            [WastageStatus::CANCELLED, WastageStatus::CANCELLED] => true,

            default => false
        };
    }

    /**
     * Get wastage records for a store
     */
    public function getWastageRecordsForStore(int $storeId, ?string $status = null, ?string $dateRange = null)
    {
        $query = Wastage::where('store_branch_id', $storeId)
            ->with(['storeBranch', 'sapMasterfile', 'encoder', 'approver1', 'approver2']);

        if ($status) {
            $query->where('wastage_status', $status);
        }

        if ($dateRange) {
            [$startDate, $endDate] = explode(',', $dateRange);
            $query->whereBetween('created_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay()
            ]);
        }

        return $query->latest()->paginate(10);
    }

    /**
     * Get wastage records that need level 1 approval
     */
    public function getWastageRecordsForLevel1Approval()
    {
        return Wastage::where('wastage_status', WastageStatus::PENDING)
            ->with(['storeBranch', 'sapMasterfile', 'encoder'])
            ->latest()
            ->paginate(10);
    }

    /**
     * Get wastage records that need level 2 approval
     */
    public function getWastageRecordsForLevel2Approval()
    {
        return Wastage::where('wastage_status', WastageStatus::APPROVED_LVL1)
            ->with(['storeBranch', 'sapMasterfile', 'encoder', 'approver1'])
            ->latest()
            ->paginate(10);
    }

    /**
     * Check if user can perform action on wastage record
     */
    public function canUserPerformAction(Wastage $wastage, string $action, $user): bool
    {
        switch ($action) {
            case 'edit':
                return $wastage->created_by == $user->id && $wastage->wastage_status->canBeEdited();
            case 'approve_level1':
                return $wastage->wastage_status->canBeApprovedLevel1();
            case 'approve_level2':
                return $wastage->wastage_status->canBeApprovedLevel2();
            case 'cancel':
                return $wastage->wastage_status->canBeCancelled();
            case 'delete':
                return $wastage->wastage_status === WastageStatus::PENDING &&
                       ($wastage->created_by == $user->id || $user->hasPermissionTo('delete wastage record'));
            default:
                return false;
        }
    }

    /**
     * Get wastage records accessible by user (based on assigned stores)
     */
    public function getWastageRecordsForUser($user, ?array $filters = null)
    {
        // Get user's assigned stores
        $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();

        if (empty($assignedStoreIds)) {
            // User has no assigned stores, return empty query
            return Wastage::whereRaw('1 = 0')->latest()->paginate(10);
        }

        $query = Wastage::whereIn('store_branch_id', $assignedStoreIds)
            ->with(['storeBranch', 'sapMasterfile', 'encoder', 'approver1', 'approver2']);

        // Apply filters
        if ($filters) {
            if (!empty($filters['status'])) {
                $query->where('wastage_status', $filters['status']);
            }

            if (!empty($filters['store_branch_id'])) {
                $query->where('store_branch_id', $filters['store_branch_id']);
            }

            if (!empty($filters['date_range'])) {
                [$startDate, $endDate] = explode(',', $filters['date_range']);
                $query->whereBetween('created_at', [
                    Carbon::parse($startDate)->startOfDay(),
                    Carbon::parse($endDate)->endOfDay()
                ]);
            }

            if (!empty($filters['search'])) {
                $search = $filters['search'];
                $query->where(function($q) use ($search) {
                    $q->where('wastage_no', 'like', "%{$search}%")
                      ->orWhere('reason', 'like', "%{$search}%")
                      ->orWhereHas('sapMasterfile', function($sq) use ($search) {
                          $sq->where('ItemCode', 'like', "%{$search}%")
                            ->orWhere('ItemDescription', 'like', "%{$search}%");
                      });
                });
            }
        }

        return $query->latest()->paginate(10);
    }

    /**
     * Get grouped wastage records for display (aggregated by wastage_no)
     */
    public function getGroupedWastageRecordsForUser($user, ?array $filters = null)
    {
        // Get user's assigned stores
        $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();

        
        if (empty($assignedStoreIds)) {
            // User has no assigned stores, return empty paginated result
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10);
        }

        // Start with base query
        $query = Wastage::whereIn('store_branch_id', $assignedStoreIds);

        // Apply filters
        if ($filters) {
            if (!empty($filters['status'])) {
                $query->where('wastage_status', $filters['status']);
            }

            if (!empty($filters['store_branch_id'])) {
                $query->where('store_branch_id', $filters['store_branch_id']);
            }

            if (!empty($filters['date_range'])) {
                [$startDate, $endDate] = explode(',', $filters['date_range']);
                $query->whereBetween('created_at', [
                    Carbon::parse($startDate)->startOfDay(),
                    Carbon::parse($endDate)->endOfDay()
                ]);
            }

            if (!empty($filters['search'])) {
                $search = $filters['search'];
                $query->where(function($q) use ($search) {
                    $q->where('wastage_no', 'like', "%{$search}%")
                      ->orWhere('reason', 'like', "%{$search}%");
                });
            }
        }

        // Get distinct wastage_no values first for pagination
        $wastageNosQuery = clone $query;
        $wastageNos = $wastageNosQuery->distinct()->pluck('wastage_no');

        // Manual pagination
        $page = request()->get('page', 1);
        $perPage = 10;
        $total = $wastageNos->count();
        $currentPageWastageNos = $wastageNos->forPage($page, $perPage);

        // If no wastage numbers, return empty paginated result
        if ($currentPageWastageNos->isEmpty()) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], $total, $perPage, $page, [
                'path' => request()->url(),
                'pageName' => 'page',
            ]);
        }

        // Get grouped data for current page
        $groupedData = [];
        foreach ($currentPageWastageNos as $wastageNo) {
            $recordsQuery = clone $query;
            $records = $recordsQuery->where('wastage_no', $wastageNo)->get();

            if ($records->isNotEmpty()) {
                $firstRecord = $records->first();

                $groupedData[] = [
                    'id' => $firstRecord->id, // Use first record's ID for show/edit links
                    'wastage_no' => $wastageNo,
                    'store_branch_id' => $firstRecord->store_branch_id,
                    'wastage_status' => $firstRecord->wastage_status,
                    'created_by' => $firstRecord->created_by,
                    'created_at' => $firstRecord->created_at,
                    'reason' => $firstRecord->reason,
                    'total_quantity' => $records->sum('wastage_qty'),
                    'total_cost' => $records->sum(function($record) { return $record->wastage_qty * $record->cost; }),
                    'items_count' => $records->count(),
                    'storeBranch' => $firstRecord->storeBranch,
                    'encoder' => $firstRecord->encoder,
                    // Use accessor for formatted data
                    'encoded_date' => $firstRecord->created_at,
                    'status_label' => $firstRecord->status_label,
                ];
            }
        }

        // Create custom paginator
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $groupedData,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );

        return $paginator;
    }

    /**
     * Get summary statistics for wastage records
     */
    public function getWastageStatistics(?array $storeIds = null): array
    {
        $query = Wastage::query();

        if ($storeIds) {
            $query->whereIn('store_branch_id', $storeIds);
        }

        return [
            'total' => $query->distinct()->pluck('wastage_no')->count(),
            'pending' => $query->clone()->where('wastage_status', WastageStatus::PENDING)->distinct()->pluck('wastage_no')->count(),
            'approved_lvl1' => $query->clone()->where('wastage_status', WastageStatus::APPROVED_LVL1)->distinct()->pluck('wastage_no')->count(),
            'approved_lvl2' => $query->clone()->where('wastage_status', WastageStatus::APPROVED_LVL2)->distinct()->pluck('wastage_no')->count(),
            'cancelled' => $query->clone()->where('wastage_status', WastageStatus::CANCELLED)->distinct()->pluck('wastage_no')->count(),
            'total_cost' => $this->getTotalCost($storeIds),
        ];
    }

    /**
     * Get total cost for wastage records
     */
    private function getTotalCost(?array $storeIds = null): float
    {
        $query = "SELECT SUM(wastage_qty * cost) as total_cost FROM wastages";

        if ($storeIds) {
            $placeholders = str_repeat('?,', count($storeIds) - 1) . '?';
            $query .= " WHERE store_branch_id IN ($placeholders)";
            $result = DB::selectOne($query, $storeIds);
        } else {
            $result = DB::selectOne($query);
        }

        return $result ? (float) $result->total_cost : 0;
    }

    /**
     * Prepare data for export
     */
    public function prepareExportData($user, ?array $filters = null): array
    {
        $query = Wastage::query()
            ->with(['storeBranch', 'sapMasterfile', 'encoder', 'approver1', 'approver2']);

        // Filter by user's assigned stores
        $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();

        if (!empty($assignedStoreIds)) {
            $query->whereIn('store_branch_id', $assignedStoreIds);
        }

        // Apply filters
        if ($filters) {
            if (!empty($filters['status'])) {
                $query->where('wastage_status', $filters['status']);
            }

            if (!empty($filters['store_branch_id'])) {
                $query->where('store_branch_id', $filters['store_branch_id']);
            }

            if (!empty($filters['date_range'])) {
                [$startDate, $endDate] = explode(',', $filters['date_range']);
                $query->whereBetween('created_at', [
                    Carbon::parse($startDate)->startOfDay(),
                    Carbon::parse($endDate)->endOfDay()
                ]);
            }
        }

        return $query->latest()->get();
    }
}