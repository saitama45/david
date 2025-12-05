<?php

namespace App\Http\Controllers;

use App\Http\Services\WastageService;
use App\Models\Wastage;
use App\Models\StoreBranch;
use App\Models\SAPMasterfile;
use App\Enums\WastageStatus;
use App\Http\Requests\WastageRequest;
use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\WastageExport;

class WastageController extends Controller
{
    protected $wastageService;
    protected $googleDriveService;

    public function __construct(WastageService $wastageService, GoogleDriveService $googleDriveService)
    {
        $this->wastageService = $wastageService;
        $this->googleDriveService = $googleDriveService;
    }

    /**
     * Display a listing of wastage records
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $filters = [
            'status' => $request->get('status') ?? 'pending',
            'store_branch_id' => $request->get('store_branch_id'),
            'date_range' => $request->get('date_range'),
            'search' => $request->get('search'),
        ];

        // Get grouped wastage records for display
        $wastages = $this->wastageService->getGroupedWastageRecordsForUser($user, $filters);

        // Get statistics for user's assigned stores
        $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();

        $statistics = $this->wastageService->getWastageStatistics(
            !empty($assignedStoreIds) ? $assignedStoreIds : null
        );

        return Inertia::render('Wastage/Index', [
            'wastages' => $wastages,
            'statistics' => $statistics,
            'filters' => $filters,
            'statusOptions' => collect(WastageStatus::cases())->map(fn($status) => [
                'value' => $status->value,
                'label' => $status->getLabel(),
                'color' => $status->getColor(),
                'bg_color' => $status->getBackgroundColor(),
            ]),
            'storeOptions' => StoreBranch::whereIn('id', $assignedStoreIds)->get()
                ->map(fn($store) => [
                    'value' => $store->id,
                    'label' => $store->name . ' (' . $store->branch_code . ')',
                ]),
            'permissions' => [
                'can_create' => $user->hasPermissionTo('create wastage record'),
                'can_edit' => $user->hasPermissionTo('edit wastage record'),
                'can_delete' => $user->hasPermissionTo('delete wastage record'),
                'can_export' => $user->hasPermissionTo('export wastage record'),
                'can_view_cost' => $user->hasPermissionTo('view cost wastage record'),
            ]
        ]);
    }

    /**
     * Show the form for creating a new wastage record
     */
    public function create()
    {
        $user = auth()->user();

        // Get user's assigned stores
        $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();

        $branches = StoreBranch::whereIn('id', $assignedStoreIds)->get();
        $items = SAPMasterfile::where('is_active', true)->orderBy('ItemDescription')->get();

        return Inertia::render('Wastage/Create', [
            'branches' => $branches->map(fn($branch) => [
                'value' => $branch->id,
                'label' => $branch->name . ' (' . $branch->branch_code . ')',
            ]),
            'items' => $items->map(fn($item) => [
                'id' => $item->id,
                'item_code' => $item->ItemCode,
                'description' => $item->ItemDescription,
                'uom' => $item->BaseUOM,
                'alt_uom' => $item->AltUOM,
            ]),
            'canViewCost' => $user->hasPermissionTo('view cost wastage record'),
        ]);
    }

    /**
     * Store a newly created wastage record
     */
    public function store(WastageRequest $request)
    {
        $user = auth()->user();
        $data = $request->validated();

        try {
            // Handle multiple image uploads
            $imageUrls = [];
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $file) {
                    $imageUrls[] = $this->googleDriveService->uploadImage($file);
                }
            }
            // Store as JSON array, even if empty
            $data['image_url'] = json_encode($imageUrls);

            // Check if this is multi-item submission (cart) or single item
            if (isset($data['cartItems'])) {
                // The service will now receive a JSON string in 'image_url'
                // and should apply it to all created records.
                $wastageRecords = $this->wastageService->createMultipleWastageRecords($data, $user->id);

                return redirect()->route('wastage.index')
                    ->with('success', count($wastageRecords) . ' wastage records created successfully.');
            } else {
                // Single item submission (for backward compatibility)
                $wastage = $this->wastageService->createWastage($data, $user->id);

                return redirect()->route('wastage.show', $wastage->id)
                    ->with('success', 'Wastage record created successfully.');
            }

        } catch (\Exception $e) {
            \Log::error('Wastage record creation failed: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->id,
                'data' => $data
            ]);

            return back()->withInput()->withErrors(['error' => 'Failed to create wastage record: ' . $e->getMessage()]);
        }
    }

    /**
     * Display the specified wastage record
     */
    public function show(Wastage $wastage)
    {
        $user = auth()->user();

        // Check if user has access to this wastage record
        $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();

        if (!in_array($wastage->store_branch_id, $assignedStoreIds)) {
            abort(403, 'You do not have permission to view this wastage record');
        }

        // Load the primary wastage record with relationships
        $wastage->load([
            'storeBranch',
            'encoder',
            'approver1',
            'approver2',
            'canceller'
        ]);

        // Fetch all wastage records with the same wastage_no (grouped transaction)
        $relatedWastageRecords = Wastage::where('wastage_no', $wastage->wastage_no)
            ->with(['sapMasterfile'])
            ->get();

        // Structure the data to match what Vue component expects
        $wastageData = [
            'id' => $wastage->id,
            'wastage_no' => $wastage->wastage_no,
            'store_branch_id' => $wastage->store_branch_id,
            'remarks' => $wastage->remarks,
            'wastage_status' => $wastage->wastage_status,
            'status_label' => $wastage->status_label,
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

        return Inertia::render('Wastage/Show', [
            'wastage' => $wastageData,
            'permissions' => [
                'can_view' => true, // Always true for show page
                'can_view_cost' => $user->hasPermissionTo('view cost wastage record'),
            ],
            'statusTransitions' => [], // Empty since we're removing action buttons
        ]);
    }

    /**
     * Display wastage record by wastage number and redirect to show page with ID
     */
    public function showByNumber(string $wastage_no)
    {
        try {
            // Find the first wastage record with the given wastage_no
            $wastage = Wastage::where('wastage_no', $wastage_no)->firstOrFail();

            // Redirect to the show page with the actual wastage ID
            return redirect()->route('wastage.show', $wastage->id);

        } catch (\Exception $e) {
            \Log::error('Wastage record not found by number: ' . $e->getMessage(), [
                'wastage_no' => $wastage_no,
                'error' => $e->getMessage()
            ]);

            abort(404, 'Wastage record not found: ' . $wastage_no);
        }
    }

    /**
     * Show the form for editing the specified wastage record
     */
    public function edit(Wastage $wastage)
    {
        $user = auth()->user();

        // Debug logging to identify the failing condition
        \Log::info('Wastage edit permission check:', [
            'wastage_id' => $wastage->id,
            'wastage_no' => $wastage->wastage_no,
            'wastage_status' => $wastage->wastage_status->value,
            'created_by' => $wastage->created_by,
            'current_user_id' => $user->id,
            'current_user_email' => $user->email,
            'user_owns_record' => $wastage->created_by == $user->id,
            'status_allows_edit' => $wastage->wastage_status->canBeEdited(),
            'user_has_edit_permission' => $user->hasPermissionTo('edit wastage record'),
            'service_result' => $this->wastageService->canUserPerformAction($wastage, 'edit', $user)
        ]);

        // Check if user can edit this wastage record
        if (!$this->wastageService->canUserPerformAction($wastage, 'edit', $user)) {
            // Provide more specific error messages based on why the edit failed
            if (!$user->hasPermissionTo('edit wastage record')) {
                abort(403, 'You do not have permission to edit wastage records.');
            } elseif (!$wastage->wastage_status->canBeEdited()) {
                abort(403, 'This record cannot be edited because it has status: ' . $wastage->wastage_status->getLabel() . '. Only PENDING records can be edited.');
            } elseif ($wastage->created_by != $user->id) {
                abort(403, 'You can only edit records you created.');
            } else {
                abort(403, 'You do not have permission to edit this wastage record.');
            }
        }

        // Get user's assigned stores
        $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();

        $branches = StoreBranch::whereIn('id', $assignedStoreIds)->get();
        $items = SAPMasterfile::where('is_active', true)->orderBy('ItemDescription')->get();

        // Fetch all wastage records with the same wastage_no
        $relatedWastageRecords = Wastage::where('wastage_no', $wastage->wastage_no)
            ->with([
                'storeBranch',
                'sapMasterfile',
                'encoder',
                'approver1',
                'approver2',
                'canceller'
            ])
            ->get();

        // Structure the data to match what Vue component expects
        $wastageData = [
            'id' => $wastage->id,
            'wastage_no' => $wastage->wastage_no,
            'store_branch_id' => $wastage->store_branch_id,
            'remarks' => $wastage->remarks,
            'wastage_status' => $wastage->wastage_status,
            'created_by' => $wastage->created_by,
            'created_at' => $wastage->created_at,
            'updated_at' => $wastage->updated_at,
            'image_urls' => json_decode($wastage->image_url, true) ?? [],
            'items' => $relatedWastageRecords->map(function ($record) {
                return [
                    'id' => $record->id,
                    'sap_masterfile_id' => $record->sap_masterfile_id,
                    'wastage_qty' => $record->wastage_qty,
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

        return Inertia::render('Wastage/Edit', [
            'wastage' => $wastageData,
            'branches' => $branches->map(fn($branch) => [
                'value' => $branch->id,
                'label' => $branch->name . ' (' . $branch->branch_code . ')',
            ]),
            'items' => $items->map(fn($item) => [
                'id' => $item->id,
                'item_code' => $item->ItemCode,
                'description' => $item->ItemDescription,
                'uom' => $item->BaseUOM,
                'alt_uom' => $item->AltUOM,
            ]),
            'canViewCost' => $user->hasPermissionTo('view cost wastage record'),
        ]);
    }

    /**
     * Update the specified wastage record
     */
    public function update(WastageRequest $request, Wastage $wastage)
    {
        $user = auth()->user();
        $data = $request->validated();

        // Check if user can edit this wastage record
        if (!$this->wastageService->canUserPerformAction($wastage, 'edit', $user)) {
            abort(403, 'You cannot edit this wastage record');
        }

        try {
            // Handle multiple image uploads
            $newlyUploadedUrls = [];
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $file) {
                    $newlyUploadedUrls[] = $this->googleDriveService->uploadImage($file);
                }
            }

            // Handle existing and deleted images
            $originalUrls = json_decode($wastage->image_url, true) ?? [];
            $remainingUrls = [];

            if ($request->has('existing_image_urls')) {
                $clientUrls = $request->input('existing_image_urls', []);
                // Strip the "/storage/" prefix from the URLs sent by the client
                $remainingUrls = array_map(fn($url) => preg_replace('/^\/storage\//', '', $url), $clientUrls);
            }

            $deletedUrls = array_diff($originalUrls, $remainingUrls);
            foreach ($deletedUrls as $urlToDelete) {
                $this->googleDriveService->deleteImage($urlToDelete);
            }

            // Combine remaining existing URLs with newly uploaded ones
            $finalUrls = array_merge($remainingUrls, $newlyUploadedUrls);
            $data['image_url'] = json_encode($finalUrls);

            // Check if this is a multi-item update or single item update
            if (isset($data['items']) && is_array($data['items'])) {
                // Multi-item update with deletion support
                $result = $this->wastageService->updateMultipleWastageRecords($wastage, $data, $user->id, $data['image_url']);

                // Build detailed success message
                $existingUpdatedCount = $result['summary']['existing_updated_count'] ?? 0;
                $newCreatedCount = $result['summary']['new_created_count'] ?? 0;
                $deletedCount = $result['summary']['deleted_count'] ?? 0;
                $totalProcessed = $result['summary']['total_processed'] ?? 0;

                // Check if any items remain in the wastage group after deletion
                $remainingItemsCount = \App\Models\Wastage::where('wastage_no', $wastage->wastage_no)->count();
                $isWastageCompletelyDeleted = $remainingItemsCount === 0;

                $successMessage = '';
                if ($existingUpdatedCount > 0 && $newCreatedCount > 0 && $deletedCount > 0) {
                    $successMessage = "{$existingUpdatedCount} records updated, {$newCreatedCount} new records added, and {$deletedCount} records deleted successfully.";
                } elseif ($existingUpdatedCount > 0 && $newCreatedCount > 0 && $deletedCount === 0) {
                    $successMessage = "{$existingUpdatedCount} records updated and {$newCreatedCount} new records added successfully.";
                } elseif ($existingUpdatedCount > 0 && $newCreatedCount === 0 && $deletedCount > 0) {
                    $successMessage = "{$existingUpdatedCount} records updated and {$deletedCount} records deleted successfully.";
                } elseif ($existingUpdatedCount === 0 && $newCreatedCount > 0 && $deletedCount > 0) {
                    $successMessage = "{$newCreatedCount} new records added and {$deletedCount} records deleted successfully.";
                } elseif ($existingUpdatedCount > 0 && $newCreatedCount === 0 && $deletedCount === 0) {
                    $successMessage = "{$existingUpdatedCount} wastage records updated successfully.";
                } elseif ($existingUpdatedCount === 0 && $newCreatedCount > 0 && $deletedCount === 0) {
                    $successMessage = "{$newCreatedCount} new wastage records added successfully.";
                } elseif ($existingUpdatedCount === 0 && $newCreatedCount === 0 && $deletedCount > 0) {
                    $successMessage = "{$deletedCount} wastage records deleted successfully.";
                } else {
                    $successMessage = "Wastage records processed successfully.";
                }

                // Handle redirect logic
                if ($isWastageCompletelyDeleted) {
                    // If all items were deleted, redirect to index page
                    return redirect()->route('wastage.index')
                        ->with('success', "Wastage record #{$wastage->wastage_no} has been completely deleted. {$successMessage}");
                } else {
                    // If items remain, use the remaining record provided by the service for redirect
                    $remainingRecord = $result['remaining_record_for_redirect'] ?? null;

                    if ($remainingRecord) {
                        // Use the remaining record's ID for redirect (most efficient)
                        return redirect()->route('wastage.show', $remainingRecord->id)
                            ->with('success', $successMessage);
                    } else {
                        // Fallback: find any remaining record (defensive programming)
                        $fallbackRecord = \App\Models\Wastage::where('wastage_no', $wastage->wastage_no)
                            ->first();

                        if ($fallbackRecord) {
                            return redirect()->route('wastage.show', $fallbackRecord->id)
                                ->with('success', $successMessage);
                        } else {
                            // Last resort: redirect to index (shouldn't happen)
                            return redirect()->route('wastage.index')
                                ->with('success', "Wastage record #{$wastage->wastage_no} updated. {$successMessage}");
                        }
                    }
                }
            } else {
                // Single item update (fallback for backward compatibility)
                $wastage = $this->wastageService->updateWastage($wastage, $data, $user->id);

                return redirect()->route('wastage.show', $wastage->id)
                    ->with('success', 'Wastage record updated successfully.');
            }

        } catch (\Exception $e) {
            \Log::error('Wastage record update failed: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'wastage_id' => $wastage->id,
                'user_id' => $user->id
            ]);

            return back()->withInput()->withErrors(['error' => 'Failed to update wastage record: ' . $e->getMessage()]);
        }
    }

    /**
     * Remove the specified wastage record
     */
    public function destroy(Wastage $wastage)
    {
        $user = auth()->user();

        // Check if user can delete this wastage record
        if (!$this->wastageService->canUserPerformAction($wastage, 'delete', $user)) {
            abort(403, 'You cannot delete this wastage record');
        }

        try {
            DB::beginTransaction();

            // Only allow deletion of pending records
            if ($wastage->wastage_status !== WastageStatus::PENDING) {
                throw new \Exception('Only pending wastage records can be deleted');
            }

            $wastageNo = $wastage->wastage_no;
            $remainingItemsCount = \App\Models\Wastage::where('wastage_no', $wastageNo)->count();

            $wastage->delete();

            // Check if this was the last item in the wastage group
            $isWastageCompletelyDeleted = $remainingItemsCount <= 1;

            DB::commit();

            $successMessage = $isWastageCompletelyDeleted
                ? "Wastage record #{$wastageNo} has been completely deleted."
                : "Wastage record deleted successfully.";

            return redirect()->route('wastage.index')
                ->with('success', $successMessage);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Wastage record deletion failed: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'wastage_id' => $wastage->id,
                'user_id' => $user->id
            ]);

            return back()->withErrors(['error' => 'Failed to delete wastage record: ' . $e->getMessage()]);
        }
    }

    /**
     * Export wastage records to Excel
     */
    public function export(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermissionTo('export wastage record')) {
            abort(403, 'You do not have permission to export wastage records');
        }

        try {
            $filters = [
                'status' => $request->get('status'),
                'store_branch_id' => $request->get('store_branch_id'),
                'date_range' => $request->get('date_range'),
            ];

            $data = $this->wastageService->prepareExportData($user, $filters);

            $export = new WastageExport($data);

            return Excel::download($export, 'wastage_records_' . now()->format('Y_m_d_His') . '.xlsx');

        } catch (\Exception $e) {
            \Log::error('Wastage export failed: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'filters' => $filters ?? []
            ]);

            return back()->withErrors(['error' => 'Failed to export wastage records: ' . $e->getMessage()]);
        }
    }

    /**
     * Approve wastage record (Level 1)
     */
    public function approveLevel1(Request $request, Wastage $wastage): RedirectResponse
    {
        $user = auth()->user();

        try {
            $this->wastageService->updateWastageStatus($wastage, WastageStatus::APPROVED_LVL1, $user->id);

            return redirect()
                ->route('wastage.show', $wastage)
                ->with('success', 'Wastage record approved at level 1 successfully.');

        } catch (\Exception $e) {
            \Log::error('Failed to approve wastage record at level 1: ' . $e->getMessage(), [
                'wastage_id' => $wastage->id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return back()
                ->with('error', 'Failed to approve wastage record: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Approve wastage record (Level 2)
     */
    public function approveLevel2(Request $request, Wastage $wastage): RedirectResponse
    {
        $user = auth()->user();

        try {
            $this->wastageService->updateWastageStatus($wastage, WastageStatus::APPROVED_LVL2, $user->id);

            return redirect()
                ->route('wastage.show', $wastage)
                ->with('success', 'Wastage record approved at level 2 successfully.');

        } catch (\Exception $e) {
            \Log::error('Failed to approve wastage record at level 2: ' . $e->getMessage(), [
                'wastage_id' => $wastage->id,
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
    public function cancel(Request $request, Wastage $wastage): RedirectResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'cancellation_remarks' => 'nullable|string|max:1000',
        ]);

        try {
            $this->wastageService->updateWastageStatus($wastage, WastageStatus::CANCELLED, $user->id);

            // Update reason if provided
            if (!empty($validated['cancellation_remarks'])) {
                $wastage->update([
                    'reason' => $validated['cancellation_remarks']
                ]);
            }

            return redirect()
                ->route('wastage.show', $wastage)
                ->with('success', 'Wastage record cancelled successfully.');

        } catch (\Exception $e) {
            \Log::error('Failed to cancel wastage record: ' . $e->getMessage(), [
                'wastage_id' => $wastage->id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return back()
                ->with('error', 'Failed to cancel wastage record: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Get cost from SupplierItems table for a given ItemCode
     */
    private function getItemCostFromSupplier(string $itemCode): float
    {
        try {
            $cost = \App\Models\SupplierItems::where('ItemCode', $itemCode)
                ->where('is_active', true)
                ->value('cost');

            return $cost ?: 1.0;

        } catch (\Exception $e) {
            \Log::warning('getItemCostFromSupplier error for ItemCode ' . $itemCode . ': ' . $e->getMessage());
            return 1.0;
        }
    }

    /**
     * Get available items for wastage from a specific store
     */
    public function getAvailableItems(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => 'required|integer|exists:store_branches,id'
        ]);

        $storeId = $request->input('store_id');
        $search = $request->input('search');

        try {
            $query = SAPMasterfile::where('is_active', true)
                ->whereNotNull('ItemCode')
                ->where('ItemCode', '!=', '')
                ->whereNotNull('ItemDescription')
                ->whereColumn('BaseUOM', 'AltUOM')
                ->orderBy('ItemDescription');

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('ItemCode', 'like', "%{$search}%")
                      ->orWhere('ItemDescription', 'like', "%{$search}%");
                });
                $query->limit(50);
            } else {
                $query->limit(100);
            }

            $items = $query->get();

            $processedItems = $items->map(function ($item) use ($storeId) {
                // Get SOH from product_inventory_stock_managers table
                $soh = DB::table('product_inventory_stock_managers')
                    ->where('product_inventory_id', $item->id)
                    ->where('store_branch_id', $storeId)
                    ->sum('quantity');

                return [
                    'id' => $item->id,
                    'item_code' => $item->ItemCode,
                    'description' => $item->ItemDescription ?: "Product Item {$item->ItemCode}",
                    'uom' => $item->BaseUOM,
                    'alt_uom' => $item->AltUOM,
                    'cost_per_quantity' => $this->getItemCostFromSupplier($item->ItemCode),
                    'stock' => round($soh ?: 0, 4),
                ];
            });

            return response()->json([
                'items' => $processedItems,
                'total_items' => $processedItems->count()
            ]);

        } catch (\Exception $e) {
            \Log::error('getAvailableItems error for store ' . $storeId . ': ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to fetch items',
                'message' => 'Database query timeout or error. Please try again.',
                'items' => []
            ], 500);
        }
    }

    /**
     * Get item details including stock information
     */
    public function getItemDetails(Request $request): JsonResponse
    {
        $request->validate([
            'item_code' => 'required|string',
            'store_id' => 'required|integer|exists:store_branches,id'
        ]);

        try {
            $item = SAPMasterfile::where('ItemCode', $request->input('item_code'))
                ->where('is_active', true)
                ->first();

            if (!$item) {
                return response()->json(['error' => 'Item not found'], 404);
            }

            return response()->json([
                'item' => [
                    'id' => $item->id,
                    'item_code' => $item->ItemCode,
                    'description' => $item->ItemDescription,
                    'uom' => $item->BaseUOM,
                    'alt_uom' => $item->AltUOM,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch item details: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available status transitions for the current user
     */
    private function getAvailableStatusTransitions(?WastageStatus $currentStatus, $user): array
    {
        if (!$currentStatus) {
            return [];
        }

        $transitions = [];

        switch ($currentStatus) {
            case WastageStatus::PENDING:
                $transitions[] = [
                    'action' => 'approve_level1',
                    'label' => 'Approve Level 1',
                    'nextStatus' => WastageStatus::APPROVED_LVL1->value,
                    'color' => 'blue',
                ];
                $transitions[] = [
                    'action' => 'cancel',
                    'label' => 'Cancel',
                    'nextStatus' => WastageStatus::CANCELLED->value,
                    'color' => 'red',
                ];
                break;

            case WastageStatus::APPROVED_LVL1:
                $transitions[] = [
                    'action' => 'approve_level2',
                    'label' => 'Approve Level 2',
                    'nextStatus' => WastageStatus::APPROVED_LVL2->value,
                    'color' => 'green',
                ];
                $transitions[] = [
                    'action' => 'cancel',
                    'label' => 'Cancel',
                    'nextStatus' => WastageStatus::CANCELLED->value,
                    'color' => 'red',
                ];
                break;
        }

        return $transitions;
    }
}
