<?php

namespace App\Http\Services;

use App\Models\EntityApprovalWorkflow;
use App\Models\ApprovalMatrix;
use App\Models\StoreOrder;
use App\Models\Wastage;
use App\Models\User;
use App\Events\WorkflowInitiated;
use App\Events\WorkflowCompleted;
use App\Events\ApprovalActionProcessed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class WorkflowService
{
    public function __construct(
        private ApprovalMatrixService $approvalMatrixService
    ) {}

    /**
     * Initiate workflow for a store order
     */
    public function initiateStoreOrderWorkflow(StoreOrder $order, User $initiator): ?EntityApprovalWorkflow
    {
        // Only initiate for orders that are pending
        if ($order->order_status !== 'pending') {
            Log::info("Order {$order->id} is not pending, skipping workflow initiation");
            return null;
        }

        $workflow = $this->approvalMatrixService->initiateWorkflow(
            'store_order',
            $order->id,
            $initiator
        );

        if ($workflow) {
            // Link workflow to order
            $order->update([
                'approval_workflow_id' => $workflow->id,
                'current_approval_level' => $workflow->current_approval_level,
                'total_approval_required' => $workflow->total_approval_required,
            ]);

            // Fire event
            WorkflowInitiated::dispatch($workflow);

            Log::info("Workflow initiated for store order {$order->id}");
        }

        return $workflow;
    }

    /**
     * Initiate workflow for a wastage
     */
    public function initiateWastageWorkflow(Wastage $wastage, User $initiator): ?EntityApprovalWorkflow
    {
        // Only initiate for pending wastages
        if ($wastage->wastage_status !== 'PENDING') {
            Log::info("Wastage {$wastage->id} is not pending, skipping workflow initiation");
            return null;
        }

        $workflow = $this->approvalMatrixService->initiateWorkflow(
            'wastage',
            $wastage->id,
            $initiator
        );

        if ($workflow) {
            // Link workflow to wastage
            $wastage->update([
                'approval_workflow_id' => $workflow->id,
                'current_approval_level' => $workflow->current_approval_level,
                'total_approval_required' => $workflow->total_approval_required,
            ]);

            // Fire event
            WorkflowInitiated::dispatch($workflow);

            Log::info("Workflow initiated for wastage {$wastage->id}");
        }

        return $workflow;
    }

    /**
     * Process workflow completion and update entity status
     */
    public function processWorkflowCompletion(EntityApprovalWorkflow $workflow): void
    {
        $entity = $workflow->entity;

        if (!$entity) {
            Log::error("Entity not found for workflow {$workflow->id}");
            return;
        }

        DB::transaction(function () use ($workflow, $entity) {
            switch ($workflow->current_status) {
                case EntityApprovalWorkflow::STATUS_APPROVED:
                    $this->processApprovalCompletion($workflow, $entity);
                    break;

                case EntityApprovalWorkflow::STATUS_REJECTED:
                    $this->processRejectionCompletion($workflow, $entity);
                    break;

                case EntityApprovalWorkflow::STATUS_CANCELLED:
                    $this->processCancellationCompletion($workflow, $entity);
                    break;

                case EntityApprovalWorkflow::STATUS_ESCALATED:
                    $this->processEscalationCompletion($workflow, $entity);
                    break;
            }

            // Update entity approval info
            $entity->update([
                'approval_workflow_id' => $workflow->id,
                'current_approval_level' => $workflow->current_approval_level,
                'total_approval_required' => $workflow->total_approval_required,
            ]);

            // Fire completion event
            WorkflowCompleted::dispatch($workflow);

            Log::info("Workflow {$workflow->id} completed with status: {$workflow->current_status}");
        });
    }

    /**
     * Process approval completion
     */
    protected function processApprovalCompletion(EntityApprovalWorkflow $workflow, $entity): void
    {
        switch (get_class($entity)) {
            case StoreOrder::class:
                $this->processStoreOrderApproval($entity, $workflow);
                break;

            case Wastage::class:
                $this->processWastageApproval($entity, $workflow);
                break;

            default:
                Log::warning("No specific approval processing for entity type: " . get_class($entity));
                break;
        }
    }

    /**
     * Process rejection completion
     */
    protected function processRejectionCompletion(EntityApprovalWorkflow $workflow, $entity): void
    {
        switch (get_class($entity)) {
            case StoreOrder::class:
                $entity->update(['order_status' => 'rejected']);
                break;

            case Wastage::class:
                $entity->update(['wastage_status' => 'CANCELLED']);
                break;

            default:
                Log::warning("No specific rejection processing for entity type: " . get_class($entity));
                break;
        }
    }

    /**
     * Process cancellation completion
     */
    protected function processCancellationCompletion(EntityApprovalWorkflow $workflow, $entity): void
    {
        switch (get_class($entity)) {
            case StoreOrder::class:
                $entity->update(['order_status' => 'cancelled']);
                break;

            case Wastage::class:
                $entity->update(['wastage_status' => 'CANCELLED']);
                break;

            default:
                Log::warning("No specific cancellation processing for entity type: " . get_class($entity));
                break;
        }
    }

    /**
     * Process escalation completion
     */
    protected function processEscalationCompletion(EntityApprovalWorkflow $workflow, $entity): void
    {
        // For escalation, we typically keep the original status but mark it as escalated
        // This allows administrators to review and take appropriate action

        switch (get_class($entity)) {
            case StoreOrder::class:
                $entity->update(['order_status' => 'escalated']);
                break;

            case Wastage::class:
                $entity->update(['wastage_status' => 'ESCALATED']);
                break;

            default:
                Log::warning("No specific escalation processing for entity type: " . get_class($entity));
                break;
        }
    }

    /**
     * Process specific store order approval
     */
    protected function processStoreOrderApproval(StoreOrder $order, EntityApprovalWorkflow $workflow): void
    {
        // Update order status to approved
        $order->update([
            'order_status' => 'approved',
            'approval_action_date' => now(),
            'approver_id' => $workflow->initiated_by_id, // Or the last approver
        ]);

        // Update item quantities if they were modified during approval
        $this->updateOrderItemQuantities($order, $workflow);

        Log::info("Store order {$order->id} approved through workflow");
    }

    /**
     * Process specific wastage approval
     */
    protected function processWastageApproval(Wastage $wastage, EntityApprovalWorkflow $workflow): void
    {
        $currentLevel = $workflow->current_approval_level;
        $requiredLevels = $workflow->total_approval_required;

        if ($currentLevel <= 1) {
            // First level approval
            $wastage->update([
                'wastage_status' => 'APPROVED_LVL1',
                'approved_level1_by' => $workflow->initiated_by_id,
                'approved_level1_date' => now(),
            ]);
        } elseif ($currentLevel >= $requiredLevels) {
            // Final level approval
            $wastage->update([
                'wastage_status' => 'APPROVED_LVL2',
                'approved_level2_by' => $workflow->initiated_by_id,
                'approved_level2_date' => now(),
            ]);
        }

        Log::info("Wastage {$wastage->id} approved at level {$currentLevel}");
    }

    /**
     * Update order item quantities from approval workflow
     */
    protected function updateOrderItemQuantities(StoreOrder $order, EntityApprovalWorkflow $workflow): void
    {
        $approvalData = $workflow->approval_workflow ?? [];

        if (isset($approvalData['item_quantities']) && is_array($approvalData['item_quantities'])) {
            foreach ($approvalData['item_quantities'] as $itemId => $approvedQuantity) {
                $orderItem = $order->items()->find($itemId);
                if ($orderItem) {
                    $orderItem->update([
                        'quantity_approved' => $approvedQuantity,
                        'committed_by' => $workflow->initiated_by_id,
                        'committed_date' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Handle approval action event
     */
    public function handleApprovalAction(ApprovalActionProcessed $event): void
    {
        $workflow = $event->workflow;

        // Update entity status if workflow is completed
        if ($workflow->current_status !== EntityApprovalWorkflow::STATUS_PENDING) {
            $this->processWorkflowCompletion($workflow);
        }

        // Update entity approval progress
        if ($workflow->entity) {
            $workflow->entity->update([
                'current_approval_level' => $workflow->current_approval_level,
                'total_approval_required' => $workflow->total_approval_required,
            ]);
        }

        Log::info("Processed approval action for workflow {$workflow->id}");
    }

    /**
     * Cancel a workflow
     */
    public function cancelWorkflow(EntityApprovalWorkflow $workflow, string $reason, User $cancelledBy): bool
    {
        if (!$workflow->is_active) {
            return false;
        }

        $workflow->cancelWorkflow($reason);

        // Update entity status
        if ($workflow->entity) {
            $this->processWorkflowCompletion($workflow);
        }

        Log::info("Workflow {$workflow->id} cancelled by user {$cancelledBy->id}: {$reason}");

        return true;
    }

    /**
     * Get workflow status for an entity
     */
    public function getEntityWorkflowStatus($entity): array
    {
        $workflow = EntityApprovalWorkflow::where([
            'entity_type' => $this->getEntityType($entity),
            'entity_id' => $entity->id,
        ])
            ->where('is_active', true)
            ->first();

        if (!$workflow) {
            return [
                'has_workflow' => false,
                'status' => 'no_workflow',
            ];
        }

        return [
            'has_workflow' => true,
            'workflow_id' => $workflow->id,
            'status' => $workflow->current_status,
            'current_level' => $workflow->current_approval_level,
            'total_levels' => $workflow->total_approval_required,
            'status_description' => $workflow->status_description,
            'initiated_at' => $workflow->initiated_at,
            'completed_at' => $workflow->completed_at,
        ];
    }

    /**
     * Get entity type from entity
     */
    protected function getEntityType($entity): string
    {
        if (method_exists($entity, 'getEntityType')) {
            return $entity->getEntityType();
        }

        return match(get_class($entity)) {
            StoreOrder::class => 'store_order',
            Wastage::class => 'wastage',
            default => strtolower(class_basename($entity)),
        };
    }
}