<?php

namespace App\Events;

use App\Models\EntityApprovalWorkflow;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkflowInitiated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public EntityApprovalWorkflow $workflow
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('workflow.' . $this->workflow->id),
            new PrivateChannel('user.' . $this->workflow->initiated_by_id),
            'approvals.system',
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'workflow.initiated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'workflow_id' => $this->workflow->id,
            'entity_type' => $this->workflow->entity_type,
            'entity_id' => $this->workflow->entity_id,
            'initiated_by' => $this->workflow->initiatedBy?->name,
            'initiated_at' => $this->workflow->initiated_at->toISOString(),
            'total_approval_levels' => $this->workflow->total_approval_required,
            'current_approval_level' => $this->workflow->current_approval_level,
            'entity_name' => $this->getEntityDisplayName(),
            'entity_url' => $this->getEntityUrl(),
            'approval_matrix_name' => $this->workflow->approvalMatrix?->matrix_name,
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
     * Get the entity URL.
     */
    protected function getEntityUrl(): string
    {
        $entity = $this->workflow->entity;
        $baseUrl = config('app.url');

        if (!$entity) {
            return "{$baseUrl}/";
        }

        return match(get_class($entity)) {
            \App\Models\StoreOrder::class => "{$baseUrl}/store-orders/{$entity->id}",
            \App\Models\Wastage::class => "{$baseUrl}/wastages/{$entity->id}",
            \App\Models\IntercoTransfer::class => "{$baseUrl}/interco-transfers/{$entity->id}",
            default => "{$baseUrl}/",
        };
    }

    /**
     * Get the event title for notifications.
     */
    public function getTitle(): string
    {
        return "New Approval Workflow Initiated";
    }

    /**
     * Get the event description for notifications.
     */
    public function getDescription(): string
    {
        $entityName = $this->getEntityDisplayName();
        $levels = $this->workflow->total_approval_required;

        return "A new approval workflow has been initiated for {$entityName} requiring {$levels} level(s) of approval.";
    }

    /**
     * Get the notification recipients.
     */
    public function getNotificationRecipients(): array
    {
        $recipients = [];

        // Always notify the initiator
        if ($this->workflow->initiatedBy) {
            $recipients[] = $this->workflow->initiatedBy;
        }

        // Notify approvers for current level
        $currentSteps = $this->workflow->getCurrentLevelApprovers;
        foreach ($currentSteps as $step) {
            if ($step->effective_approver) {
                $recipients[] = $step->effective_approver;
            }
        }

        return array_unique($recipients);
    }

    /**
     * Get the event priority.
     */
    public function getPriority(): string
    {
        return 'high';
    }

    /**
     * Get the event type.
     */
    public function getType(): string
    {
        return 'workflow_initiated';
    }
}