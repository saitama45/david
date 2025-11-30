<?php

namespace App\Http\Services;

use App\Models\ApprovalMatrix;
use App\Models\EntityApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use App\Models\ApprovalMatrixApprover;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ApprovalMatrixService
{
    /**
     * Cache duration in minutes
     */
    private const CACHE_DURATION = 60;

    /**
     * Find the best matching approval matrix for an entity
     */
    public function findMatchingMatrix(string $entityType, $entity): ?ApprovalMatrix
    {
        $cacheKey = "approval_matrices:{$entityType}:active";

        $matrices = Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($entityType) {
            return ApprovalMatrix::active()
                ->forModule($entityType)
                ->forEntityType($this->getEntityType($entity))
                ->with(['rules', 'approvers'])
                ->byPriority()
                ->get();
        });

        foreach ($matrices as $matrix) {
            if ($matrix->matchesEntity($entity)) {
                return $matrix;
            }
        }

        return null;
    }

    /**
     * Initiate an approval workflow for an entity
     */
    public function initiateWorkflow(
        string $entityType,
        int $entityId,
        User $initiator,
        ?ApprovalMatrix $matrix = null
    ): ?EntityApprovalWorkflow {
        if (!$matrix) {
            $entity = $this->getEntityInstance($entityType, $entityId);
            $matrix = $this->findMatchingMatrix($entityType, $entity);
        }

        if (!$matrix || !$matrix->isValid()) {
            Log::warning("No valid approval matrix found for {$entityType}:{$entityId}");
            return null;
        }

        return DB::transaction(function () use ($matrix, $entityType, $entityId, $initiator) {
            $workflow = EntityApprovalWorkflow::create([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'initiated_by_type' => get_class($initiator),
                'initiated_by_id' => $initiator->id,
                'approval_matrix_id' => $matrix->id,
                'total_approval_required' => $matrix->approval_levels,
                'current_approval_level' => 1,
                'current_status' => EntityApprovalWorkflow::STATUS_PENDING,
                'initiated_at' => now(),
                'is_active' => true,
            ]);

            // Create steps for first level
            $this->createStepsForWorkflow($workflow, 1);

            Log::info("Approval workflow initiated for {$entityType}:{$entityId}");

            return $workflow;
        });
    }

    /**
     * Process an approval action
     */
    public function processApproval(
        User $approver,
        EntityApprovalWorkflow $workflow,
        array $data
    ): array {
        $action = $data['action'] ?? null;
        $reason = $data['reason'] ?? null;

        // Validate approver can perform action
        if (!$this->canApprove($approver, $workflow)) {
            throw new \InvalidArgumentException('User is not authorized to approve this workflow');
        }

        $step = $this->getCurrentStepForApprover($workflow, $approver);
        if (!$step) {
            throw new \InvalidArgumentException('No pending approval step found for this user');
        }

        $result = DB::transaction(function () use ($step, $action, $reason, $data, $workflow) {
            switch ($action) {
                case 'approve':
                    return $this->processApprovalAction($step, $reason, $data);
                case 'reject':
                    return $this->processRejectionAction($step, $reason, $data, $workflow);
                case 'delegate':
                    return $this->processDelegationAction($step, $data, $workflow);
                default:
                    throw new \InvalidArgumentException("Invalid action: {$action}");
            }
        });

        // Check if workflow can proceed to next level
        if ($workflow->canProceedToNextLevel()) {
            $workflow->proceedToNextLevel();
        }

        return $result;
    }

    /**
     * Check if a user can approve a workflow
     */
    public function canApprove(User $user, EntityApprovalWorkflow $workflow): bool
    {
        $step = $this->getCurrentStepForApprover($workflow, $user);
        return $step && $step->isPending();
    }

    /**
     * Get workflows pending for a user
     */
    public function getPendingWorkflowsForUser(User $user, int $limit = 20): Collection
    {
        return EntityApprovalWorkflow::pending()
            ->active()
            ->forUser($user->id)
            ->with(['entity', 'approvalMatrix', 'steps'])
            ->limit($limit)
            ->get();
    }

    /**
     * Get overdue workflows
     */
    public function getOverdueWorkflows(int $hours = 24): Collection
    {
        return EntityApprovalWorkflow::pending()
            ->active()
            ->whereHas('steps', function ($query) use ($hours) {
                $query->where('action', EntityApprovalWorkflow::ACTION_PENDING)
                    ->where('deadline_at', '<', now()->subHours($hours))
                    ->where('is_active', true);
            })
            ->with(['entity', 'approvalMatrix', 'steps'])
            ->get();
    }

    /**
     * Get entity type from entity instance
     */
    protected function getEntityType($entity): string
    {
        if (method_exists($entity, 'getEntityType')) {
            return $entity->getEntityType();
        }

        $className = class_basename($entity);
        return strtolower(str_replace('StoreOrder', 'store_order', $className));
    }

    /**
     * Get entity instance
     */
    protected function getEntityInstance(string $entityType, int $entityId)
    {
        $modelClass = match($entityType) {
            'store_order' => \App\Models\StoreOrder::class,
            'wastage' => \App\Models\Wastage::class,
            'interco_transfer' => \App\Models\IntercoTransfer::class,
            default => null,
        };

        if (!$modelClass) {
            throw new \InvalidArgumentException("Unsupported entity type: {$entityType}");
        }

        return $modelClass::findOrFail($entityId);
    }

    /**
     * Create steps for a workflow level
     */
    protected function createStepsForWorkflow(EntityApprovalWorkflow $workflow, int $level): void
    {
        $approvers = $workflow->approvalMatrix->getApproversForLevel($level)->get();

        foreach ($approvers as $approver) {
            $effectiveApprover = $approver->getEffectiveApprover();
            $deadline = $approver->getApprovalDeadlineFrom(now());

            ApprovalWorkflowStep::create([
                'entity_approval_workflow_id' => $workflow->id,
                'approval_level' => $level,
                'approver_user_id' => $effectiveApprover->id,
                'delegated_to_user_id' => $approver->getCurrentDelegatedUser()?->id,
                'assigned_at' => now(),
                'deadline_at' => $deadline,
                'is_active' => true,
            ]);
        }
    }

    /**
     * Get current step for an approver
     */
    protected function getCurrentStepForApprover(EntityApprovalWorkflow $workflow, User $approver): ?ApprovalWorkflowStep
    {
        return $workflow->steps()
            ->where('approval_level', $workflow->current_approval_level)
            ->where('action', EntityApprovalWorkflow::ACTION_PENDING)
            ->where('is_active', true)
            ->where(function ($query) use ($approver) {
                $query->where('approver_user_id', $approver->id)
                    ->orWhere('delegated_to_user_id', $approver->id);
            })
            ->first();
    }

    /**
     * Process approval action
     */
    protected function processApprovalAction(ApprovalWorkflowStep $step, ?string $reason, array $data): array
    {
        $step->approve($reason, $data);

        return [
            'status' => 'approved',
            'message' => 'Step approved successfully',
            'step' => $step,
        ];
    }

    /**
     * Process rejection action
     */
    protected function processRejectionAction(
        ApprovalWorkflowStep $step,
        string $reason,
        array $data,
        EntityApprovalWorkflow $workflow
    ): array {
        $step->reject($reason, $data);
        $workflow->completeWorkflow(EntityApprovalWorkflow::STATUS_REJECTED, $reason);

        return [
            'status' => 'rejected',
            'message' => 'Workflow rejected',
            'step' => $step,
            'workflow' => $workflow,
        ];
    }

    /**
     * Process delegation action
     */
    protected function processDelegationAction(
        ApprovalWorkflowStep $step,
        array $data,
        EntityApprovalWorkflow $workflow
    ): array {
        $toUserId = $data['delegate_to_user_id'] ?? null;
        $delegationReason = $data['delegation_reason'] ?? 'Delegated approval';

        if (!$toUserId) {
            throw new \InvalidArgumentException('Delegation target user ID is required');
        }

        $step->delegate($toUserId, $delegationReason);

        return [
            'status' => 'delegated',
            'message' => 'Step delegated successfully',
            'step' => $step,
        ];
    }

    /**
     * Clear cache for entity type
     */
    public function clearCache(string $entityType): void
    {
        $cacheKey = "approval_matrices:{$entityType}:active";
        Cache::forget($cacheKey);
    }

    /**
     * Get workflow statistics for a user
     */
    public function getWorkflowStatsForUser(User $user): array
    {
        $pending = EntityApprovalWorkflow::pending()
            ->active()
            ->forUser($user->id)
            ->count();

        $approved = EntityApprovalWorkflow::approved()
            ->forUser($user->id)
            ->where('initiated_by_id', '!=', $user->id)
            ->count();

        $rejected = EntityApprovalWorkflow::rejected()
            ->forUser($user->id)
            ->where('initiated_by_id', '!=', $user->id)
            ->count();

        return [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'total' => $pending + $approved + $rejected,
        ];
    }

    /**
     * Escalate overdue workflows
     */
    public function escalateOverdueWorkflows(): int
    {
        $overdueWorkflows = $this->getOverdueWorkflows();
        $escalatedCount = 0;

        foreach ($overdueWorkflows as $workflow) {
            $workflow->completeWorkflow(
                EntityApprovalWorkflow::STATUS_ESCALATED,
                'Escalated due to deadline breach'
            );
            $escalatedCount++;
        }

        Log::info("Escalated {$escalatedCount} overdue workflows");

        return $escalatedCount;
    }
}