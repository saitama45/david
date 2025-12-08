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
use Illuminate\Support\Collection;
use App\Models\UserAssignedStoreBranch;
use Illuminate\Pagination\LengthAwarePaginator;

class WastageService
{
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
                'reason' => $data['reason'],
                'remarks' => $data['remarks'] ?? null,
                'wastage_status' => WastageStatus::PENDING->value,
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
                    'reason' => $item['reason'],
                    'remarks' => $data['remarks'] ?? null,
                    'image_url' => $data['image_url'] ?? null,
                    'wastage_status' => WastageStatus::PENDING->value,
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
                'reason' => $data['reason'],
                'remarks' => $data['remarks'] ?? null,
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
    public function updateMultipleWastageRecords(Wastage $wastage, array $data, ?int $userId = null, ?string $imageUrl = null): array
    {
        \Illuminate\Support\Facades\Log::info('--- Service: updateMultipleWastageRecords ---');
        \Illuminate\Support\Facades\Log::info('Explicit imageUrl received: ' . ($imageUrl ?? 'NULL'));

        DB::beginTransaction();
        try {
            // Check if wastage can be edited
            if (!$wastage->wastage_status->canBeEdited()) {
                throw new Exception("Wastage record cannot be edited in current status: {$wastage->wastage_status->getLabel()}");
            }

            $wastageNo = $wastage->wastage_no;
            $submittedItems = collect($data['items']);
            $allWastageRecords = Wastage::where('wastage_no', $wastageNo)->get();

            $summary = [
                'existing_updated_count' => 0,
                'new_created_count' => 0,
                'deleted_count' => 0,
            ];

            $submittedItemIds = $submittedItems->pluck('id')->filter()->toArray();
            $itemsToDelete = $allWastageRecords->whereNotIn('id', $submittedItemIds);

            // Handle Deletions
            foreach ($itemsToDelete as $recordToDelete) {
                $recordToDelete->delete();
                $summary['deleted_count']++;
            }

            // Handle Updates and Creations
            foreach ($submittedItems as $itemData) {
                $updateData = [
                    'remarks' => $data['remarks'] ?? null,
                    'updated_by' => $userId,
                    'sap_masterfile_id' => $itemData['sap_masterfile_id'],
                    'wastage_qty' => $itemData['wastage_qty'],
                    'cost' => $itemData['cost'],
                    'reason' => $itemData['reason'],
                ];

                // Use the explicitly passed imageUrl for all items in the transaction
                \Illuminate\Support\Facades\Log::info('Checking for imageUrl before adding to updateData. URL: ' . ($imageUrl ?? 'NULL'));
                if ($imageUrl !== null) {
                    $updateData['image_url'] = $imageUrl;
                    \Illuminate\Support\Facades\Log::info('image_url has been added to updateData.');
                }

                if (isset($itemData['id']) && is_numeric($itemData['id']) && $itemData['id'] > 0) {
                    // Update existing record
                    $recordToUpdate = $allWastageRecords->find($itemData['id']);
                    if ($recordToUpdate) {
                        \Illuminate\Support\Facades\Log::info('Preparing to update record ID: ' . $recordToUpdate->id . ' with data: ' . json_encode($updateData));
                        $recordToUpdate->update($updateData);
                        \Illuminate\Support\Facades\Log::info('Eloquent update call finished for record ID: ' . $recordToUpdate->id);
                        $summary['existing_updated_count']++;
                    }
                } else {
                    // Create new record
                    $newWastageData = array_merge($updateData, [
                        'wastage_no' => $wastageNo,
                        'store_branch_id' => $wastage->store_branch_id,
                        'created_by' => $userId,
                        'wastage_status' => $wastage->wastage_status,
                    ]);
                    \Illuminate\Support\Facades\Log::info('Preparing to create new record with data: ' . json_encode($newWastageData));
                    Wastage::create($newWastageData);
                    \Illuminate\Support\Facades\Log::info('Eloquent create call finished.');
                    $summary['new_created_count']++;
                }
            }

            DB::commit();

            return [
                'summary' => $summary,
                'remaining_record_for_redirect' => Wastage::where('wastage_no', $wastageNo)->first(),
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Multi-item wastage update failed', [
                'error' => $e->getMessage(),
                'wastage_no' => $wastage->wastage_no,
                'user_id' => $userId,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
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
        return Wastage::where('wastage_status', WastageStatus::PENDING->value)
            ->with(['storeBranch', 'sapMasterfile', 'encoder'])
            ->latest()
            ->paginate(10);
    }

    /**
     * Get wastage records that need level 2 approval
     */
    public function getWastageRecordsForLevel2Approval()
    {
        return Wastage::where('wastage_status', WastageStatus::APPROVED_LVL1->value)
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
        $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();

        if (empty($assignedStoreIds)) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10);
        }

        $baseQuery = Wastage::whereIn('store_branch_id', $assignedStoreIds);

        if ($filters) {
            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $baseQuery->where('wastage_status', $filters['status']);
            }

            if (!empty($filters['store_branch_id'])) {
                $baseQuery->where('store_branch_id', $filters['store_branch_id']);
            }

            if (!empty($filters['date_range'])) {
                [$startDate, $endDate] = explode(',', $filters['date_range']);
                $baseQuery->whereBetween('created_at', [
                    Carbon::parse($startDate)->startOfDay(),
                    Carbon::parse($endDate)->endOfDay()
                ]);
            }

            if (!empty($filters['search'])) {
                $search = $filters['search'];
                $baseQuery->where(function($q) use ($search) {
                    $q->where('wastage_no', 'like', "%{$search}%")
                      ->orWhere('reason', 'like', "%{$search}%")
                      ->orWhereHas('storeBranch', function($storeQuery) use ($search) {
                          $storeQuery->where('name', 'like', "%{$search}%")
                                     ->orWhere('branch_code', 'like', "%{$search}%");
                      })
                      ->orWhereHas('sapMasterfile', function($itemQuery) use ($search) {
                          $itemQuery->where('ItemCode', 'like', "%{$search}%")
                                   ->orWhere('ItemDescription', 'like', "%{$search}%");
                      });
                });
            }
        }

        $wastageNos = $baseQuery->selectRaw('wastage_no, MAX(id) as max_id')
            ->groupBy('wastage_no')
            ->orderByDesc('max_id')
            ->paginate(10);

        $pageWastageNos = $wastageNos->pluck('wastage_no')->toArray();

        if (empty($pageWastageNos)) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10);
        }

        $allRecords = Wastage::whereIn('wastage_no', $pageWastageNos)
            ->with(['storeBranch', 'sapMasterfile', 'encoder', 'approver1', 'approver2'])
            ->get()
            ->groupBy('wastage_no');

        $groupedData = [];
        foreach ($pageWastageNos as $wastageNo) {
            $records = $allRecords->get($wastageNo, collect());
            if ($records->isNotEmpty()) {
                $firstRecord = $records->first();
                $groupedData[] = [
                    'id' => $firstRecord->id,
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
                    'encoded_date' => $firstRecord->created_at,
                    'status_label' => $firstRecord->status_label,
                ];
            }
        }

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $groupedData,
            $wastageNos->total(),
            $wastageNos->perPage(),
            $wastageNos->currentPage(),
            [
                'path' => $wastageNos->path(),
                'pageName' => 'page',
            ]
        );
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
            'pending' => $query->clone()->where('wastage_status', WastageStatus::PENDING->value)->distinct()->pluck('wastage_no')->count(),
            'approved_lvl1' => $query->clone()->where('wastage_status', WastageStatus::APPROVED_LVL1->value)->distinct()->pluck('wastage_no')->count(),
            'approved_lvl2' => $query->clone()->where('wastage_status', WastageStatus::APPROVED_LVL2->value)->distinct()->pluck('wastage_no')->count(),
            'cancelled' => $query->clone()->where('wastage_status', WastageStatus::CANCELLED->value)->distinct()->pluck('wastage_no')->count(),
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
     * Generate a unique wastage number for the given store branch
     * Format: WASTE-{branch_code}-{sequence} (e.g., WASTE-NNSSR-00001)
     */
    public function generateWastageNumber(StoreBranch $storeBranch): string
    {
        $branchCode = $storeBranch->branch_code;
        $maxAttempts = 10;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            // Get the latest wastage number for this branch pattern
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

            $wastageNo = sprintf("WASTE-%s-%05d", $branchCode, $newSequence);

            // Collision detection: ensure this number doesn't exist
            $existingWastage = Wastage::where('wastage_no', $wastageNo)->first();
            if (!$existingWastage) {
                return $wastageNo;
            }

            // If collision occurred, increment and try again
            $attempt++;
            \Log::warning("Wastage number collision detected, retrying", [
                'attempt' => $attempt,
                'wastage_no' => $wastageNo,
                'branch_code' => $branchCode
            ]);
        }

        // Fallback: use timestamp if all attempts fail
        $fallbackNumber = sprintf("WASTE-%s-%d", $branchCode, time());
        \Log::error("Wastage number generation failed, using fallback", [
            'fallback_number' => $fallbackNumber,
            'branch_code' => $branchCode,
            'attempts' => $maxAttempts
        ]);

        return $fallbackNumber;
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
