<?php

namespace App\Http\Controllers;

use App\Models\EntityApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use App\Http\Resources\ApprovalWorkflowResource;
use App\Http\Resources\PendingApprovalResource;
use App\Http\Services\ApprovalMatrixService;
use App\Http\Requests\BulkApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class PendingApprovalsController extends Controller
{
    public function __construct(
        private ApprovalMatrixService $approvalMatrixService
    ) {}

    /**
     * Display pending approvals dashboard.
     */
    public function dashboard(Request $request): Response
    {
        $user = Auth::user();

        // Get pending workflows with current steps
        $pendingWorkflows = EntityApprovalWorkflow::pending()
            ->active()
            ->whereHas('steps', function ($query) use ($user) {
                $query->where('approver_user_id', $user->id)
                    ->orWhere('delegated_to_user_id', $user->id)
                    ->where('action', EntityApprovalWorkflow::ACTION_PENDING)
                    ->where('is_active', true);
            })
            ->with(['entity', 'approvalMatrix', 'currentStep'])
            ->orderBy('initiated_at', 'desc')
            ->paginate(20);

        // Get statistics
        $stats = $this->approvalMatrixService->getWorkflowStatsForUser($user);

        // Get overdue items
        $overdueWorkflows = EntityApprovalWorkflow::pending()
            ->active()
            ->whereHas('steps', function ($query) use ($user) {
                $query->where('approver_user_id', $user->id)
                    ->orWhere('delegated_to_user_id', $user->id)
                    ->where('action', EntityApprovalWorkflow::ACTION_PENDING)
                    ->where('deadline_at', '<', now())
                    ->where('is_active', true);
            })
            ->with(['entity', 'approvalMatrix', 'currentStep'])
            ->orderBy('initiated_at', 'desc')
            ->get();

        // Get upcoming deadlines (within next 48 hours)
        $upcomingDeadlines = EntityApprovalWorkflow::pending()
            ->active()
            ->whereHas('steps', function ($query) use ($user) {
                $query->where('approver_user_id', $user->id)
                    ->orWhere('delegated_to_user_id', $user->id)
                    ->where('action', EntityApprovalWorkflow::ACTION_PENDING)
                    ->where('deadline_at', '>', now())
                    ->where('deadline_at', '<=', now()->addHours(48))
                    ->where('is_active', true);
            })
            ->with(['entity', 'approvalMatrix', 'currentStep'])
            ->orderBy('deadline_at', 'asc')
            ->get();

        return Inertia::render('PendingApprovals/Dashboard', [
            'pendingWorkflows' => PendingApprovalResource::collection($pendingWorkflows),
            'stats' => $stats,
            'overdueCount' => $overdueWorkflows->count(),
            'upcomingDeadlinesCount' => $upcomingDeadlines->count(),
            'overdueWorkflows' => PendingApprovalResource::collection($overdueWorkflows),
            'upcomingDeadlines' => PendingApprovalResource::collection($upcomingDeadlines),
        ]);
    }

    /**
     * Get pending approvals for current user (API endpoint).
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $limit = min($request->get('limit', 15), 50);

        $workflows = EntityApprovalWorkflow::pending()
            ->active()
            ->whereHas('steps', function ($query) use ($user) {
                $query->where('approver_user_id', $user->id)
                    ->orWhere('delegated_to_user_id', $user->id)
                    ->where('action', EntityApprovalWorkflow::ACTION_PENDING)
                    ->where('is_active', true);
            })
            ->when($request->input('entity_type'), function ($query, $entityType) {
                $query->where('entity_type', $entityType);
            })
            ->when($request->input('urgency'), function ($query, $urgency) {
                $query->whereHas('steps', function ($stepQuery) use ($urgency) {
                    $stepQuery->where('deadline_at', '>', now())
                        ->where(function ($deadlineQuery) use ($urgency) {
                            if ($urgency === 'high') {
                                $deadlineQuery->where('deadline_at', '<=', now()->addHours(24));
                            } elseif ($urgency === 'medium') {
                                $deadlineQuery->where('deadline_at', '<=', now()->addHours(72));
                            }
                        });
                });
            })
            ->when($request->input('overdue'), function ($query) {
                $query->whereHas('steps', function ($stepQuery) {
                    $stepQuery->where('deadline_at', '<', now());
                });
            })
            ->with(['entity', 'approvalMatrix', 'currentStep'])
            ->orderBy('initiated_at', 'desc')
            ->paginate($limit);

        return response()->json([
            'workflows' => PendingApprovalResource::collection($workflows),
            'pagination' => [
                'current_page' => $workflows->currentPage(),
                'last_page' => $workflows->lastPage(),
                'per_page' => $workflows->perPage(),
                'total' => $workflows->total(),
            ],
        ]);
    }

    /**
     * Get quick approval details for a workflow.
     */
    public function quickDetails(EntityApprovalWorkflow $workflow): JsonResponse
    {
        $this->authorize('view', $workflow);

        $workflow->load([
            'entity',
            'approvalMatrix',
            'currentStep',
            'initiatedBy',
        ]);

        $user = Auth::user();
        $step = $workflow->steps()
            ->where('approver_user_id', $user->id)
            ->orWhere('delegated_to_user_id', $user->id)
            ->where('action', EntityApprovalWorkflow::ACTION_PENDING)
            ->where('is_active', true)
            ->first();

        if (!$step) {
            return response()->json([
                'error' => 'No pending approval step found',
            ], 404);
        }

        return response()->json([
            'workflow' => new PendingApprovalResource($workflow),
            'step' => $step,
            'entity' => $workflow->entity,
            'matrix' => $workflow->approvalMatrix,
            'can_approve' => true,
            'can_delegate' => $step->approverUser?->canDelegate ?? false,
        ]);
    }

    /**
     * Quick approve action (for dashboard).
     */
    public function quickApprove(Request $request, EntityApprovalWorkflow $workflow): JsonResponse
    {
        $this->authorize('approve', $workflow);

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $result = $this->approvalMatrixService->processApproval(
                Auth::user(),
                $workflow,
                [
                    'action' => 'approve',
                    'reason' => $request->reason,
                ]
            );

            Log::info("Quick approval processed for workflow {$workflow->id} by user " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => new PendingApprovalResource($workflow->fresh()),
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to process quick approval for workflow {$workflow->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Quick reject action (for dashboard).
     */
    public function quickReject(Request $request, EntityApprovalWorkflow $workflow): JsonResponse
    {
        $this->authorize('approve', $workflow);

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $result = $this->approvalMatrixService->processApproval(
                Auth::user(),
                $workflow,
                [
                    'action' => 'reject',
                    'reason' => $request->reason,
                ]
            );

            Log::info("Quick rejection processed for workflow {$workflow->id} by user " . Auth::id());

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => new PendingApprovalResource($workflow->fresh()),
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to process quick rejection for workflow {$workflow->id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Bulk approve/reject workflows.
     */
    public function bulkAction(BulkApprovalRequest $request): JsonResponse
    {
        $workflows = EntityApprovalWorkflow::whereIn('id', $request->workflow_ids)
            ->where('current_status', EntityApprovalWorkflow::STATUS_PENDING)
            ->where('is_active', true)
            ->get();

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($workflows as $workflow) {
            if (!$this->approvalMatrixService->canApprove(Auth::user(), $workflow)) {
                $results[] = [
                    'workflow_id' => $workflow->id,
                    'success' => false,
                    'message' => 'Not authorized to approve this workflow',
                ];
                $errorCount++;
                continue;
            }

            try {
                $this->approvalMatrixService->processApproval(
                    Auth::user(),
                    $workflow,
                    [
                        'action' => $request->action,
                        'reason' => $request->reason,
                    ]
                );

                $results[] = [
                    'workflow_id' => $workflow->id,
                    'success' => true,
                    'message' => ucfirst($request->action) . ' successful',
                ];
                $successCount++;

            } catch (\Exception $e) {
                $results[] = [
                    'workflow_id' => $workflow->id,
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
                $errorCount++;
            }
        }

        Log::info("Bulk {$request->action} processed: {$successCount} success, {$errorCount} errors by user " . Auth::id());

        return response()->json([
            'success' => $errorCount === 0,
            'message' => "Processed {$successCount} workflows successfully" . ($errorCount > 0 ? " with {$errorCount} errors" : ""),
            'results' => $results,
            'summary' => [
                'total' => $workflows->count(),
                'success' => $successCount,
                'errors' => $errorCount,
            ],
        ]);
    }

    /**
     * Get pending approvals count (for notifications).
     */
    public function count(): JsonResponse
    {
        $user = Auth::user();

        $count = EntityApprovalWorkflow::pending()
            ->active()
            ->whereHas('steps', function ($query) use ($user) {
                $query->where('approver_user_id', $user->id)
                    ->orWhere('delegated_to_user_id', $user->id)
                    ->where('action', EntityApprovalWorkflow::ACTION_PENDING)
                    ->where('is_active', true);
            })
            ->count();

        return response()->json([
            'count' => $count,
        ]);
    }

    /**
     * Get overdue approvals for the current user.
     */
    public function overdue(): JsonResponse
    {
        $user = Auth::user();

        $workflows = EntityApprovalWorkflow::pending()
            ->active()
            ->whereHas('steps', function ($query) use ($user) {
                $query->where('approver_user_id', $user->id)
                    ->orWhere('delegated_to_user_id', $user->id)
                    ->where('action', EntityApprovalWorkflow::ACTION_PENDING)
                    ->where('deadline_at', '<', now())
                    ->where('is_active', true);
            })
            ->with(['entity', 'approvalMatrix', 'currentStep'])
            ->orderBy('initiated_at', 'desc')
            ->get();

        return response()->json([
            'workflows' => PendingApprovalResource::collection($workflows),
            'count' => $workflows->count(),
        ]);
    }

    /**
     * Search pending approvals.
     */
    public function search(Request $request): JsonResponse
    {
        $user = Auth::user();
        $search = $request->get('search', '');
        $limit = min($request->get('limit', 20), 50);

        $query = EntityApprovalWorkflow::pending()
            ->active()
            ->whereHas('steps', function ($query) use ($user) {
                $query->where('approver_user_id', $user->id)
                    ->orWhere('delegated_to_user_id', $user->id)
                    ->where('action', EntityApprovalWorkflow::ACTION_PENDING)
                    ->where('is_active', true);
            });

        if ($search) {
            $query->whereHas('entity', function ($entityQuery) use ($search) {
                $entityQuery->where('order_number', 'like', "%{$search}%")
                    ->orWhere('wastage_no', 'like', "%{$search}%");
            });
        }

        $workflows = $query
            ->with(['entity', 'approvalMatrix', 'currentStep'])
            ->orderBy('initiated_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'workflows' => PendingApprovalResource::collection($workflows),
            'count' => $workflows->count(),
        ]);
    }

    /**
     * Export pending approvals to CSV.
     */
    public function export(Request $request): JsonResponse
    {
        $user = Auth::user();

        $workflows = EntityApprovalWorkflow::pending()
            ->active()
            ->whereHas('steps', function ($query) use ($user) {
                $query->where('approver_user_id', $user->id)
                    ->orWhere('delegated_to_user_id', $user->id)
                    ->where('action', EntityApprovalWorkflow::ACTION_PENDING)
                    ->where('is_active', true);
            })
            ->with(['entity', 'approvalMatrix', 'currentStep'])
            ->orderBy('initiated_at', 'desc')
            ->get();

        $csv = $this->generatePendingApprovalsCsv($workflows);

        $filename = 'pending_approvals_' . now()->format('Y_m_d_H_i_s') . '.csv';

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Generate CSV for pending approvals.
     */
    protected function generatePendingApprovalsCsv($workflows): string
    {
        $headers = [
            'Workflow ID',
            'Entity Type',
            'Entity Reference',
            'Initiated By',
            'Initiated At',
            'Current Level',
            'Total Levels',
            'Deadline',
            'Urgency',
            'Entity URL',
        ];

        $rows = [$headers];

        foreach ($workflows as $workflow) {
            $entity = $workflow->entity;
            $currentStep = $workflow->current_step;

            $rows[] = [
                $workflow->id,
                ucfirst(str_replace('_', ' ', $workflow->entity_type)),
                $this->getEntityReference($entity),
                $workflow->initiatedBy?->name,
                $workflow->initiated_at->format('Y-m-d H:i:s'),
                $workflow->current_approval_level,
                $workflow->total_approval_required,
                $currentStep?->deadline_at?->format('Y-m-d H:i:s'),
                $currentStep?->deadline_urgency,
                $this->getEntityUrl($entity),
            ];
        }

        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(function ($field) {
                return '"' . str_replace('"', '""', $field) . '"';
            }, $row)) . "\n";
        }

        return $csv;
    }

    /**
     * Get entity reference.
     */
    protected function getEntityReference($entity): string
    {
        if (!$entity) return 'N/A';

        return match(get_class($entity)) {
            \App\Models\StoreOrder::class => $entity->order_number,
            \App\Models\Wastage::class => $entity->wastage_no,
            default => "#{$entity->id}",
        };
    }

    /**
     * Get entity URL.
     */
    protected function getEntityUrl($entity): string
    {
        if (!$entity) return '';

        $baseUrl = config('app.url');

        return match(get_class($entity)) {
            \App\Models\StoreOrder::class => "{$baseUrl}/store-orders/{$entity->id}",
            \App\Models\Wastage::class => "{$baseUrl}/wastages/{$entity->id}",
            default => "{$baseUrl}/",
        };
    }
}