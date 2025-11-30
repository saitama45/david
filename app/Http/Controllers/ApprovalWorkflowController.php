<?php

namespace App\Http\Controllers;

use App\Models\EntityApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use App\Http\Requests\ProcessApprovalRequest;
use App\Http\Resources\ApprovalWorkflowResource;
use App\Http\Resources\ApprovalWorkflowDetailResource;
use App\Http\Services\ApprovalMatrixService;
use App\Http\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalWorkflowController extends Controller
{
    public function __construct(
        private ApprovalMatrixService $approvalMatrixService,
        private WorkflowService $workflowService
    ) {}

    /**
     * Display a listing of approval workflows for the current user.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        $workflows = EntityApprovalWorkflow::query()
            ->whereHas('steps', function ($query) use ($user) {
                $query->where('approver_user_id', $user->id)
                    ->orWhere('delegated_to_user_id', $user->id);
            })
            ->when($request->input('status'), function ($query, $status) {
                $query->where('current_status', $status);
            })
            ->when($request->input('entity_type'), function ($query, $entityType) {
                $query->where('entity_type', $entityType);
            })
            ->with(['entity', 'approvalMatrix', 'steps' => function ($query) use ($user) {
                $query->where('action', EntityApprovalWorkflow::ACTION_PENDING)
                    ->where('is_active', true)
                    ->where(function ($q) use ($user) {
                        $q->where('approver_user_id', $user->id)
                            ->orWhere('delegated_to_user_id', $user->id);
                    });
            }])
            ->orderBy('initiated_at', 'desc')
            ->paginate(15);

        $stats = $this->approvalMatrixService->getWorkflowStatsForUser($user);

        $entityTypes = EntityApprovalWorkflow::select('entity_type')
            ->distinct()
            ->pluck('entity_type');

        return Inertia::render('ApprovalWorkflows/Index', [
            'workflows' => ApprovalWorkflowResource::collection($workflows),
            'stats' => $stats,
            'filters' => $request->only(['status', 'entity_type']),
            'entityTypes' => $entityTypes,
        ]);
    }

    /**
     * Display the specified approval workflow.
     */
    public function show(EntityApprovalWorkflow $workflow): Response
    {
        $this->authorize('view', $workflow);

        $workflow->load([
            'entity',
            'approvalMatrix.rules',
            'approvalMatrix.approvers.user',
            'initiatedBy',
            'steps.approverUser',
            'steps.delegatedToUser',
        ]);

        return Inertia::render('ApprovalWorkflows/Show', [
            'workflow' => new ApprovalWorkflowDetailResource($workflow),
            'canApprove' => $this->approvalMatrixService->canApprove(Auth::user(), $workflow),
            'currentStep' => $workflow->current_step,
        ]);
    }

    /**
     * Process an approval action.
     */
    public function process(ProcessApprovalRequest $request, EntityApprovalWorkflow $workflow): JsonResponse
    {
        $this->authorize('approve', $workflow);

        try {
            $result = $this->approvalMatrixService->processApproval(
                Auth::user(),
                $workflow,
                $request->validated()
            );

            // Process workflow completion if needed
            if ($workflow->current_status !== EntityApprovalWorkflow::STATUS_PENDING) {
                $this->workflowService->processWorkflowCompletion($workflow);
            }

            Log::info("Approval action processed for workflow {$workflow->id} by user " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'workflow' => new ApprovalWorkflowResource($workflow->fresh()),
                    'step' => $result['step'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to process approval action for workflow {$workflow->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get pending workflows for the current user (API endpoint).
     */
    public function pending(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 10);
        $workflows = $this->approvalMatrixService->getPendingWorkflowsForUser(Auth::user(), $limit);

        return response()->json([
            'workflows' => ApprovalWorkflowResource::collection($workflows),
            'count' => $workflows->count(),
        ]);
    }

    /**
     * Get workflow statistics for the current user.
     */
    public function stats(): JsonResponse
    {
        $stats = $this->approvalMatrixService->getWorkflowStatsForUser(Auth::user());

        return response()->json($stats);
    }

    /**
     * Cancel a workflow.
     */
    public function cancel(Request $request, EntityApprovalWorkflow $workflow): JsonResponse
    {
        $this->authorize('cancel', $workflow);

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $success = $this->workflowService->cancelWorkflow(
                $workflow,
                $request->reason,
                Auth::user()
            );

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Workflow cancelled successfully',
                    'data' => new ApprovalWorkflowResource($workflow->fresh()),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel workflow',
            ], 422);

        } catch (\Exception $e) {
            Log::error("Failed to cancel workflow {$workflow->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel workflow: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delegate an approval step.
     */
    public function delegate(Request $request, EntityApprovalWorkflow $workflow): JsonResponse
    {
        $this->authorize('approve', $workflow);

        $request->validate([
            'delegate_to_user_id' => 'required|exists:users,id',
            'delegation_reason' => 'required|string|max:500',
            'max_delegation_amount' => 'nullable|numeric|min:0',
            'can_further_delegate' => 'boolean',
            'end_date' => 'nullable|date|after:today',
        ]);

        try {
            $result = $this->approvalMatrixService->processApproval(
                Auth::user(),
                $workflow,
                [
                    'action' => 'delegate',
                    'delegate_to_user_id' => $request->delegate_to_user_id,
                    'delegation_reason' => $request->delegation_reason,
                    'max_delegation_amount' => $request->max_delegation_amount,
                    'can_further_delegate' => $request->can_further_delegate ?? false,
                    'end_date' => $request->end_date,
                ]
            );

            // Create delegation record
            $step = $result['step'];
            $approverAssignment = $workflow->approvalMatrix->approvers()
                ->where('user_id', Auth::id())
                ->where('approval_level', $step->approval_level)
                ->first();

            if ($approverAssignment) {
                $approverAssignment->delegations()->create([
                    'delegate_from_user_id' => Auth::id(),
                    'delegate_to_user_id' => $request->delegate_to_user_id,
                    'delegation_reason' => $request->delegation_reason,
                    'start_date' => now(),
                    'end_date' => $request->end_date ?? now()->addDays(7),
                    'max_delegation_amount' => $request->max_delegation_amount,
                    'can_further_delegate' => $request->can_further_delegate ?? false,
                    'is_active' => true,
                ]);
            }

            Log::info("Step delegated in workflow {$workflow->id} by user " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'workflow' => new ApprovalWorkflowResource($workflow->fresh()),
                    'step' => $result['step'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to delegate step in workflow {$workflow->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delegate approval: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get workflow history for an entity.
     */
    public function entityHistory(Request $request): JsonResponse
    {
        $request->validate([
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
        ]);

        $workflows = EntityApprovalWorkflow::where([
            'entity_type' => $request->entity_type,
            'entity_id' => $request->entity_id,
        ])
            ->with(['initiatedBy', 'steps.approverUser', 'steps.delegatedToUser'])
            ->orderBy('initiated_at', 'desc')
            ->get();

        return response()->json([
            'workflows' => ApprovalWorkflowResource::collection($workflows),
        ]);
    }

    /**
     * Get available delegation targets for the current user.
     */
    public function delegationTargets(Request $request): JsonResponse
    {
        $request->validate([
            'workflow_id' => 'required|exists:entity_approval_workflows,id',
        ]);

        $workflow = EntityApprovalWorkflow::findOrFail($request->workflow_id);
        $this->authorize('approve', $workflow);

        $currentStep = $workflow->current_step;
        if (!$currentStep) {
            return response()->json([]);
        }

        // Get users with appropriate permissions for the approval level
        $targets = User::where('is_active', true)
            ->whereHas('roles', function ($query) use ($workflow) {
                $query->where('name', 'like', '%approver%');
            })
            ->where('id', '!=', Auth::id())
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json($targets);
    }

    /**
     * Get dashboard data for approvals.
     */
    public function dashboard(): JsonResponse
    {
        $user = Auth::user();

        $stats = $this->approvalMatrixService->getWorkflowStatsForUser($user);

        $pendingWorkflows = $this->approvalMatrixService->getPendingWorkflowsForUser($user, 5);

        $overdueWorkflows = EntityApprovalWorkflow::pending()
            ->active()
            ->forUser($user->id)
            ->overdue()
            ->with(['entity', 'approvalMatrix'])
            ->limit(5)
            ->get();

        $recentlyCompleted = EntityApprovalWorkflow::whereIn('current_status', [
            EntityApprovalWorkflow::STATUS_APPROVED,
            EntityApprovalWorkflow::STATUS_REJECTED,
        ])
            ->whereHas('steps', function ($query) use ($user) {
                $query->where('approver_user_id', $user->id)
                    ->where('action_taken_at', '>', now()->subDays(7));
            })
            ->with(['entity', 'approvalMatrix'])
            ->orderBy('completed_at', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'stats' => $stats,
            'pending_workflows' => ApprovalWorkflowResource::collection($pendingWorkflows),
            'overdue_workflows' => ApprovalWorkflowResource::collection($overdueWorkflows),
            'recently_completed' => ApprovalWorkflowResource::collection($recentlyCompleted),
        ]);
    }

    /**
     * Search workflows.
     */
    public function search(Request $request): JsonResponse
    {
        $search = $request->get('search', '');
        $limit = min($request->get('limit', 20), 50);

        $workflows = EntityApprovalWorkflow::query()
            ->whereHas('steps', function ($query) use ($search) {
                $query->where('approver_user_id', Auth::id())
                    ->orWhere('delegated_to_user_id', Auth::id());
            })
            ->when($search, function ($query, $search) {
                $query->whereHas('entity', function ($entityQuery) use ($search) {
                    $entityQuery->where('order_number', 'like', "%{$search}%")
                        ->orWhere('wastage_no', 'like', "%{$search}%");
                });
            })
            ->with(['entity', 'approvalMatrix', 'steps' => function ($query) {
                $query->where('action', EntityApprovalWorkflow::ACTION_PENDING)
                    ->where('is_active', true);
            }])
            ->orderBy('initiated_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'workflows' => ApprovalWorkflowResource::collection($workflows),
            'count' => $workflows->count(),
        ]);
    }

    /**
     * Get workflow timeline/events.
     */
    public function timeline(EntityApprovalWorkflow $workflow): JsonResponse
    {
        $this->authorize('view', $workflow);

        $steps = $workflow->steps()
            ->with(['approverUser', 'delegatedToUser'])
            ->orderBy('approval_level')
            ->orderBy('assigned_at')
            ->get();

        $timeline = $steps->map(function ($step) {
            return [
                'id' => $step->id,
                'level' => $step->approval_level,
                'action' => $step->action,
                'status' => $step->status,
                'status_color' => $step->status_color,
                'approver' => $step->approverUser?->name,
                'delegated_to' => $step->delegatedToUser?->name,
                'assigned_at' => $step->assigned_at,
                'action_taken_at' => $step->action_taken_at,
                'deadline_at' => $step->deadline_at,
                'is_overdue' => $step->isOverdue(),
                'time_until_deadline' => $step->time_until_deadline,
                'urgency' => $step->deadline_urgency,
                'reason' => $step->action_reason,
            ];
        });

        return response()->json([
            'timeline' => $timeline,
            'current_status' => $workflow->current_status,
            'initiated_at' => $workflow->initiated_at,
            'completed_at' => $workflow->completed_at,
        ]);
    }
}