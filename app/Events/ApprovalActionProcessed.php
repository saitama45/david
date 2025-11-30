<?php

namespace App\Events;

use App\Models\EntityApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApprovalActionProcessed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public EntityApprovalWorkflow $workflow,
        public ApprovalWorkflowStep $step,
        public string $action,
        public ?string $reason = null,
        public array $data = []
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('workflow.' . $this->workflow->id),
            new PrivateChannel('step.' . $this->step->id),
            new PrivateChannel('user.' . $this->workflow->initiated_by_id),
            'approvals.system',
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'approval.action_processed';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'workflow_id' => $this->workflow->id,
            'step_id' => $this->step->id,
            'action' => $this->action,
            'reason' => $this->reason,
            'approval_level' => $this->step->approval_level,
            'approver_user_id' => $this->step->approver_user_id,
            'approver_name' => $this->step->approverUser?->name,
            'delegated_to_user_id' => $this->step->delegated_to_user_id,
            'delegated_to_name' => $this->step->delegatedToUser?->name,
            'effective_approver_name' => $this->step->effective_approver?->name,
            'action_taken_at' => $this->step->action_taken_at?->toISOString(),
            'current_approval_level' => $this->workflow->current_approval_level,
            'total_approval_levels' => $this->workflow->total_approval_required,
            'workflow_status' => $this->workflow->current_status,
            'entity_type' => $this->workflow->entity_type,
            'entity_id' => $this->workflow->entity_id,
            'entity_name' => $this->getEntityDisplayName(),
            'deadline_at' => $this->step->deadline_at?->toISOString(),
            'is_overdue' => $this->step->isOverdue(),
            'time_until_deadline' => $this->step->time_until_deadline,
        ];
    }

    /**
     * Get the entity display name.
     */
    protected function getEntityDisplayName(): string
    {
        $entity = $this->workflow->entity;

        if (!$entity) {
            return "Unknown Entity";
        }

        return match(get_class($entity)) {
            \App\Models\StoreOrder::class => "Store Order #{$entity->order_number}",
            \App\Models\Wastage::class => "Wastage #{$entity->wastage_no}",
            \App\Models\IntercoTransfer::class => "Inter-Company Transfer #{$entity->interco_number}",
            default => "Entity #{$entity->id}",
        };
    }

    /**
     * Get the event title for notifications.
     */
    public function getTitle(): string
    {
        return match($this->action) {
            'approved' => 'Approval Action Processed',
            'rejected' => 'Rejection Action Processed',
            'delegated' => 'Delegation Action Processed',
            'skipped' => 'Skip Action Processed',
            default => 'Approval Action Processed',
        };
    }

    /**
     * Get the event description for notifications.
     */
    public function getDescription(): string
    {
        $entityName = $this->getEntityDisplayName();
        $level = $this->step->approval_level;
        $approverName = $this->step->effective_approver?->name;
        $totalLevels = $this->workflow->total_approval_required;

        return match($this->action) {
            'approved' => "Level {$level} of {$totalLevels} approval for {$entityName} has been approved by {$approverName}.",
            'rejected' => "Level {$level} of {$totalLevels} approval for {$entityName} has been rejected by {$approverName}.",
            'delegated' => "Level {$level} of {$totalLevels} approval for {$entityName} has been delegated by {$approverName}.",
            'skipped' => "Level {$level} of {$totalLevels} approval for {$entityName} has been skipped by {$approverName}.",
            default => "Approval action processed for {$entityName} by {$approverName}.",
        };
    }

    /**
     * Get the notification recipients.
     */
    public function getNotificationRecipients(): array
    {
        $recipients = [];

        // Always notify the workflow initiator
        if ($this->workflow->initiatedBy) {
            $recipients[] = $this->workflow->initiatedBy;
        }

        // Notify other approvers at the same level
        $sameLevelApprovers = $this->workflow->steps()
            ->where('approval_level', $this->step->approval_level)
            ->where('action', EntityApprovalWorkflow::ACTION_PENDING)
            ->where('is_active', true)
            ->get();

        foreach ($sameLevelApprovers as $otherStep) {
            if ($otherStep->effective_approver) {
                $recipients[] = $otherStep->effective_approver;
            }
        }

        // For delegation, notify the target user
        if ($this->action === 'delegated' && $this->step->delegatedToUser) {
            $recipients[] = $this->step->delegatedToUser;
        }

        return array_unique($recipients);
    }

    /**
     * Get the event priority.
     */
    public function getPriority(): string
    {
        return match($this->action) {
            'rejected' => 'critical',
            'approved' => 'high',
            'delegated' => 'medium',
            'skipped' => 'low',
            default => 'medium',
        };
    }

    /**
     * Get event type.
     */
    public function getType(): string
    {
        return match($this->action) {
            'approved' => 'approval_approved',
            'rejected' => 'approval_rejected',
            'delegated' => 'approval_delegated',
            'skipped' => 'approval_skipped',
            default => 'approval_action_processed',
        };
    }

    /**
     * Check if this is a completion event.
     */
    public function isWorkflowCompleted(): bool
    {
        return $this->workflow->current_status !== EntityApprovalWorkflow::STATUS_PENDING;
    }

    /**
     * Get the final workflow status if completed.
     */
    public function getFinalStatus(): ?string
    {
        return $this->isWorkflowCompleted() ? $this->workflow->current_status : null;
    }

    /**
     * Get the completion message.
     */
    public function getCompletionMessage(): ?string
    {
        if (!$this->isWorkflowCompleted()) {
            return null;
        }

        $entityName = $this->getEntityDisplayName();

        return match($this->workflow->current_status) {
            EntityApprovalWorkflow::STATUS_APPROVED => "All approval levels completed. {$entityName} has been approved.",
            EntityApprovalWorkflow::STATUS_REJECTED => "Approval workflow rejected. {$entityName} has been rejected.",
            EntityApprovalWorkflow::STATUS_ESCALATED => "Approval workflow escalated. {$entityName} requires administrative review.",
            EntityApprovalWorkflow::STATUS_CANCELLED => "Approval workflow cancelled. {$entityName} has been cancelled.",
            default => null,
        };
    }

    /**
     * Get the action data.
     */
    public function getActionData(): array
    {
        return array_merge($this->data, [
            'action' => $this->action,
            'reason' => $this->reason,
            'approver_id' => $this->step->approver_user_id,
            'approver_name' => $this->step->approverUser?->name,
            'approval_level' => $this->step->approval_level,
            'action_taken_at' => $this->step->action_taken_at,
            'deadline_at' => $this->step->deadline_at,
            'is_overdue' => $this->step->isOverdue(),
            'workflow_completed' => $this->isWorkflowCompleted(),
            'final_status' => $this->getFinalStatus(),
        ]);
    }
}