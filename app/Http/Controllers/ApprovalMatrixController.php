<?php

namespace App\Http\Controllers;

use App\Models\ApprovalMatrix;
use App\Models\ApprovalMatrixRule;
use App\Models\ApprovalMatrixApprover;
use App\Models\User;
use App\Http\Requests\StoreApprovalMatrixRequest;
use App\Http\Requests\UpdateApprovalMatrixRequest;
use App\Http\Resources\ApprovalMatrixResource;
use App\Http\Resources\ApprovalMatrixDetailResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalMatrixController extends Controller
{
    /**
     * Display a listing of approval matrices.
     */
    public function index(Request $request): Response
    {
        $matrices = ApprovalMatrix::with(['createdBy', 'updatedBy'])
            ->when($request->input('search'), function ($query, $search) {
                $query->where('matrix_name', 'like', "%{$search}%")
                    ->orWhere('module_name', 'like', "%{$search}%");
            })
            ->when($request->input('module'), function ($query, $module) {
                $query->where('module_name', $module);
            })
            ->when($request->input('entity_type'), function ($query, $entityType) {
                $query->where('entity_type', $entityType);
            })
            ->when($request->input('status'), function ($query, $status) {
                if ($status === 'active') {
                    $query->active();
                } elseif ($status === 'inactive') {
                    $query->where('is_active', false);
                }
            })
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        $modules = ApprovalMatrix::select('module_name')
            ->distinct()
            ->pluck('module_name');

        $entityTypes = ApprovalMatrix::select('entity_type')
            ->distinct()
            ->pluck('entity_type');

        return Inertia::render('ApprovalMatrices/Index', [
            'matrices' => ApprovalMatrixResource::collection($matrices),
            'filters' => $request->only(['search', 'module', 'entity_type', 'status']),
            'modules' => $modules,
            'entityTypes' => $entityTypes,
        ]);
    }

    /**
     * Show the form for creating a new approval matrix.
     */
    public function create(): Response
    {
        $users = User::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $modules = [
            'store_orders' => 'Store Orders',
            'wastages' => 'Wastages',
            'interco_transfers' => 'Inter-Company Transfers',
        ];

        $entityTypes = [
            'regular' => 'Regular Orders',
            'mass_order' => 'Mass Orders',
            'interco' => 'Inter-Company Transfer',
            'wastage' => 'Wastage',
        ];

        $basisColumns = [
            'supplier_id' => 'Supplier ID',
            'supplier.supplier_code' => 'Supplier Code',
            'total_amount' => 'Total Amount',
            'store_branch_id' => 'Store Branch ID',
            'store_branch.region' => 'Store Branch Region',
            'encoder_id' => 'Encoder ID',
            'category' => 'Category',
        ];

        $operators = [
            'equals' => 'Equals',
            'not_equals' => 'Not Equals',
            'in' => 'In (multiple values)',
            'not_in' => 'Not In (multiple values)',
            'greater_than' => 'Greater Than',
            'less_than' => 'Less Than',
            'between' => 'Between (range)',
        ];

        return Inertia::render('ApprovalMatrices/Create', [
            'users' => $users,
            'modules' => $modules,
            'entityTypes' => $entityTypes,
            'basisColumns' => $basisColumns,
            'operators' => $operators,
        ]);
    }

    /**
     * Store a newly created approval matrix.
     */
    public function store(StoreApprovalMatrixRequest $request): JsonResponse
    {
        try {
            $matrix = DB::transaction(function () use ($request) {
                $matrix = ApprovalMatrix::create([
                    'matrix_name' => $request->matrix_name,
                    'module_name' => $request->module_name,
                    'entity_type' => $request->entity_type,
                    'approval_levels' => $request->approval_levels,
                    'approval_type' => $request->approval_type ?? 'sequential',
                    'basis_column' => $request->basis_column,
                    'basis_operator' => $request->basis_operator,
                    'basis_value' => $request->basis_value,
                    'minimum_amount' => $request->minimum_amount,
                    'maximum_amount' => $request->maximum_amount,
                    'is_active' => $request->is_active ?? true,
                    'effective_date' => $request->effective_date,
                    'expiry_date' => $request->expiry_date,
                    'priority' => $request->priority ?? 0,
                    'description' => $request->description,
                    'created_by' => auth()->id(),
                ]);

                // Create rules if provided
                if ($request->has('rules') && is_array($request->rules)) {
                    foreach ($request->rules as $ruleData) {
                        $matrix->rules()->create([
                            'condition_group' => $ruleData['condition_group'] ?? 1,
                            'condition_logic' => $ruleData['condition_logic'] ?? 'AND',
                            'condition_column' => $ruleData['condition_column'],
                            'condition_operator' => $ruleData['condition_operator'],
                            'condition_value' => $ruleData['condition_value'],
                            'is_active' => $ruleData['is_active'] ?? true,
                        ]);
                    }
                }

                // Create approvers if provided
                if ($request->has('approvers') && is_array($request->approvers)) {
                    foreach ($request->approvers as $approverData) {
                        $matrix->approvers()->create([
                            'user_id' => $approverData['user_id'],
                            'approval_level' => $approverData['approval_level'],
                            'is_primary' => $approverData['is_primary'] ?? false,
                            'is_backup' => $approverData['is_backup'] ?? false,
                            'can_delegate' => $approverData['can_delegate'] ?? true,
                            'approval_limit_amount' => $approverData['approval_limit_amount'],
                            'approval_limit_percentage' => $approverData['approval_limit_percentage'],
                            'approval_deadline_hours' => $approverData['approval_deadline_hours'],
                            'business_hours_only' => $approverData['business_hours_only'] ?? true,
                            'is_active' => $approverData['is_active'] ?? true,
                            'effective_date' => $approverData['effective_date'],
                            'expiry_date' => $approverData['expiry_date'],
                        ]);
                    }
                }

                return $matrix;
            });

            // Clear cache
            app(ApprovalMatrixService::class)->clearCache($request->module_name);

            Log::info("Approval matrix created: {$matrix->id} by user " . auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Approval matrix created successfully',
                'data' => new ApprovalMatrixResource($matrix),
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to create approval matrix: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create approval matrix: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified approval matrix.
     */
    public function show(ApprovalMatrix $matrix): Response
    {
        $matrix->load([
            'rules',
            'approvers.user',
            'approvers.delegations.delegateToUser',
            'createdBy',
            'updatedBy',
        ]);

        return Inertia::render('ApprovalMatrices/Show', [
            'matrix' => new ApprovalMatrixDetailResource($matrix),
        ]);
    }

    /**
     * Show the form for editing the specified approval matrix.
     */
    public function edit(ApprovalMatrix $matrix): Response
    {
        $matrix->load(['rules', 'approvers.user']);

        $users = User::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $modules = [
            'store_orders' => 'Store Orders',
            'wastages' => 'Wastages',
            'interco_transfers' => 'Inter-Company Transfers',
        ];

        $entityTypes = [
            'regular' => 'Regular Orders',
            'mass_order' => 'Mass Orders',
            'interco' => 'Inter-Company Transfer',
            'wastage' => 'Wastage',
        ];

        $basisColumns = [
            'supplier_id' => 'Supplier ID',
            'supplier.supplier_code' => 'Supplier Code',
            'total_amount' => 'Total Amount',
            'store_branch_id' => 'Store Branch ID',
            'store_branch.region' => 'Store Branch Region',
            'encoder_id' => 'Encoder ID',
            'category' => 'Category',
        ];

        $operators = [
            'equals' => 'Equals',
            'not_equals' => 'Not Equals',
            'in' => 'In (multiple values)',
            'not_in' => 'Not In (multiple values)',
            'greater_than' => 'Greater Than',
            'less_than' => 'Less Than',
            'between' => 'Between (range)',
        ];

        return Inertia::render('ApprovalMatrices/Edit', [
            'matrix' => new ApprovalMatrixDetailResource($matrix),
            'users' => $users,
            'modules' => $modules,
            'entityTypes' => $entityTypes,
            'basisColumns' => $basisColumns,
            'operators' => $operators,
        ]);
    }

    /**
     * Update the specified approval matrix.
     */
    public function update(UpdateApprovalMatrixRequest $request, ApprovalMatrix $matrix): JsonResponse
    {
        try {
            $matrix = DB::transaction(function () use ($request, $matrix) {
                $matrix->update([
                    'matrix_name' => $request->matrix_name,
                    'module_name' => $request->module_name,
                    'entity_type' => $request->entity_type,
                    'approval_levels' => $request->approval_levels,
                    'approval_type' => $request->approval_type ?? $matrix->approval_type,
                    'basis_column' => $request->basis_column,
                    'basis_operator' => $request->basis_operator,
                    'basis_value' => $request->basis_value,
                    'minimum_amount' => $request->minimum_amount,
                    'maximum_amount' => $request->maximum_amount,
                    'is_active' => $request->is_active ?? $matrix->is_active,
                    'effective_date' => $request->effective_date,
                    'expiry_date' => $request->expiry_date,
                    'priority' => $request->priority ?? $matrix->priority,
                    'description' => $request->description,
                    'updated_by' => auth()->id(),
                ]);

                // Update rules
                if ($request->has('rules')) {
                    // Delete existing rules
                    $matrix->rules()->delete();

                    // Create new rules
                    foreach ($request->rules as $ruleData) {
                        $matrix->rules()->create([
                            'condition_group' => $ruleData['condition_group'] ?? 1,
                            'condition_logic' => $ruleData['condition_logic'] ?? 'AND',
                            'condition_column' => $ruleData['condition_column'],
                            'condition_operator' => $ruleData['condition_operator'],
                            'condition_value' => $ruleData['condition_value'],
                            'is_active' => $ruleData['is_active'] ?? true,
                        ]);
                    }
                }

                // Update approvers
                if ($request->has('approvers')) {
                    // Delete existing approvers
                    $matrix->approvers()->delete();

                    // Create new approvers
                    foreach ($request->approvers as $approverData) {
                        $matrix->approvers()->create([
                            'user_id' => $approverData['user_id'],
                            'approval_level' => $approverData['approval_level'],
                            'is_primary' => $approverData['is_primary'] ?? false,
                            'is_backup' => $approverData['is_backup'] ?? false,
                            'can_delegate' => $approverData['can_delegate'] ?? true,
                            'approval_limit_amount' => $approverData['approval_limit_amount'],
                            'approval_limit_percentage' => $approverData['approval_limit_percentage'],
                            'approval_deadline_hours' => $approverData['approval_deadline_hours'],
                            'business_hours_only' => $approverData['business_hours_only'] ?? true,
                            'is_active' => $approverData['is_active'] ?? true,
                            'effective_date' => $approverData['effective_date'],
                            'expiry_date' => $approverData['expiry_date'],
                        ]);
                    }
                }

                return $matrix;
            });

            // Clear cache
            app(ApprovalMatrixService::class)->clearCache($matrix->module_name);

            Log::info("Approval matrix updated: {$matrix->id} by user " . auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Approval matrix updated successfully',
                'data' => new ApprovalMatrixResource($matrix),
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to update approval matrix {$matrix->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update approval matrix: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified approval matrix.
     */
    public function destroy(ApprovalMatrix $matrix): JsonResponse
    {
        try {
            // Check if matrix has active workflows
            $hasActiveWorkflows = $matrix->entityApprovalWorkflows()
                ->where('is_active', true)
                ->exists();

            if ($hasActiveWorkflows) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete approval matrix with active workflows',
                ], 422);
            }

            DB::transaction(function () use ($matrix) {
                $matrix->rules()->delete();
                $matrix->approvers()->delete();
                $matrix->delete();
            });

            // Clear cache
            app(ApprovalMatrixService::class)->clearCache($matrix->module_name);

            Log::info("Approval matrix deleted: {$matrix->id} by user " . auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Approval matrix deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to delete approval matrix {$matrix->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete approval matrix: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle approval matrix active status.
     */
    public function toggleActive(ApprovalMatrix $matrix): JsonResponse
    {
        try {
            $matrix->update([
                'is_active' => !$matrix->is_active,
                'updated_by' => auth()->id(),
            ]);

            // Clear cache
            app(ApprovalMatrixService::class)->clearCache($matrix->module_name);

            $status = $matrix->is_active ? 'activated' : 'deactivated';

            Log::info("Approval matrix {$status}: {$matrix->id} by user " . auth()->id());

            return response()->json([
                'success' => true,
                'message' => "Approval matrix {$status} successfully",
                'data' => new ApprovalMatrixResource($matrix),
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to toggle approval matrix {$matrix->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle approval matrix status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clone an approval matrix.
     */
    public function clone(ApprovalMatrix $matrix): JsonResponse
    {
        try {
            $newMatrix = DB::transaction(function () use ($matrix) {
                $newMatrix = $matrix->replicate([
                    'created_by',
                    'updated_by',
                    'created_at',
                    'updated_at',
                ]);

                $newMatrix->matrix_name = $matrix->matrix_name . ' (Copy)';
                $newMatrix->created_by = auth()->id();
                $newMatrix->updated_by = null;
                $newMatrix->save();

                // Clone rules
                foreach ($matrix->rules as $rule) {
                    $newRule = $rule->replicate();
                    $newRule->approval_matrix_id = $newMatrix->id;
                    $newRule->save();
                }

                // Clone approvers
                foreach ($matrix->approvers as $approver) {
                    $newApprover = $approver->replicate();
                    $newApprover->approval_matrix_id = $newMatrix->id;
                    $newApprover->save();
                }

                return $newMatrix;
            });

            // Clear cache
            app(ApprovalMatrixService::class)->clearCache($matrix->module_name);

            Log::info("Approval matrix cloned: {$matrix->id} -> {$newMatrix->id} by user " . auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Approval matrix cloned successfully',
                'data' => new ApprovalMatrixResource($newMatrix),
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to clone approval matrix {$matrix->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to clone approval matrix: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get approval matrices API endpoint for select dropdowns.
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $matrices = ApprovalMatrix::active()
            ->when($request->input('module'), function ($query, $module) {
                $query->where('module_name', $module);
            })
            ->when($request->input('entity_type'), function ($query, $entityType) {
                $query->where('entity_type', $entityType);
            })
            ->orderBy('priority', 'desc')
            ->orderBy('matrix_name')
            ->get(['id', 'matrix_name', 'module_name', 'entity_type', 'approval_levels']);

        return response()->json($matrices);
    }

    /**
     * Validate approval matrix configuration.
     */
    public function validate(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'module_name' => 'required|string',
                'entity_type' => 'required|string',
                'approval_levels' => 'required|integer|min:1|max:10',
                'basis_column' => 'required|string',
                'basis_operator' => 'required|string',
                'basis_value' => 'required|array',
                'approvers' => 'required|array|min:1',
            ]);

            $issues = [];

            // Check if approvers cover all approval levels
            $levels = array_unique(array_column($data['approvers'], 'approval_level'));
            for ($level = 1; $level <= $data['approval_levels']; $level++) {
                if (!in_array($level, $levels)) {
                    $issues[] = "Level {$level} has no approvers assigned";
                }
            }

            // Check if each level has at least one primary approver
            $levelsWithPrimary = [];
            foreach ($data['approvers'] as $approver) {
                if (($approver['is_primary'] ?? false)) {
                    $levelsWithPrimary[] = $approver['approval_level'];
                }
            }

            for ($level = 1; $level <= $data['approval_levels']; $level++) {
                if (!in_array($level, $levelsWithPrimary)) {
                    $issues[] = "Level {$level} has no primary approver assigned";
                }
            }

            return response()->json([
                'valid' => empty($issues),
                'issues' => $issues,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'valid' => false,
                'issues' => ['Validation error: ' . $e->getMessage()],
            ]);
        }
    }
}