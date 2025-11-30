<?php

namespace App\Events;

use App\Models\EntityApprovalWorkflow;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkflowCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public EntityApprovalWorkflow $workflow,
        public ?string $completionReason = null,
        public array $completionData = []
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
            'workflows.completed',
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'workflow.completed';
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
            'completed_at' => $this->workflow->completed_at?->toISOString(),
            'duration_minutes' => $this->workflow->initiated_at->diffInMinutes($this->workflow->completed_at),
            'final_status' => $this->workflow->current_status,
            'total_approval_levels' => $this->workflow->total_approval_required,
            'completion_reason' => $this->completionReason,
            'entity_name' => $this->getEntityDisplayName(),
            'entity_url' => $this->getEntityUrl(),
            'approval_matrix_name' => $this->workflow->approvalMatrix?->matrix_name,
            'approval_steps' => $this->getApprovalStepsData(),
            'total_processing_time' => $this->getTotalProcessingTime(),
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
     * Get the approval steps data for broadcast.
     */
    protected function getApprovalStepsData(): array
    {
        $steps = $this->workflow->steps()
            ->with(['approverUser', 'delegatedToUser'])
            ->orderBy('approval_level')
            ->orderBy('action_taken_at')
            ->get();

        return $steps->map(function ($step) {
            return [
                'level' => $step->approval_level,
                'action' => $step->action,
                'approver' => $step->approverUser?->name,
                'delegated_to' => $step->delegatedToUser?->name,
                'effective_approver' => $step->effective_approver?->name,
                'assigned_at' => $step->assigned_at?->toISOString(),
                'action_taken_at' => $step->action_taken_at?->toISOString(),
                'deadline_at' => $step->deadline_at?->toISOString(),
                'reason' => $step->action_reason,
                'processing_time_minutes' => $step->assigned_at && $step->action_taken_at
                    ? $step->assigned_at->diffInMinutes($step->action_taken_at)
                    : null,
            'was_overdue' => $step->isOverdue(),
            'urgency' => $step->deadline_urgency,
            ];
        })->toArray();
    }

    /**
     * Get the total processing time in hours.
     */
    protected function getTotalProcessingTime(): array
    {
        if (!$this->workflow->completed_at) {
            return ['hours' => null, 'human_readable' => 'Not completed'];
        }

        $hours = $this->workflow->initiated_at->diffInHours($this->workflow->completed_at);

        return [
            'hours' => $hours,
            'human_readable' => $this->formatDuration($hours),
        ];
    }

    /**
     * Format duration into human readable format.
     */
    protected function formatDuration(float $hours): string
    {
        if ($hours < 1) {
            $minutes = round($hours * 60);
            return "{$minutes} minutes";
        } elseif ($hours < 24) {
            return round($hours) . " hours";
        } else {
            $days = floor($hours / 24);
            $remainingHours = $hours % 24;
            return $days . " day" . ($days > 1 ? "s" : "") .
                   ($remainingHours > 0 ? " " . round($remainingHours) . " hour" . (round($remainingHours) > 1 ? "s" : "") : "");
        }
    }

    /**
     * Get the event title for notifications.
     */
    public function getTitle(): string
    {
        return match($this->workflow->current_status) {
            EntityApprovalWorkflow::STATUS_APPROVED => 'Workflow Completed - Approved',
            EntityApprovalWorkflow::STATUS_REJECTED => 'Workflow Completed - Rejected',
            EntityApprovalWorkflow::STATUS_CANCELLED => 'Workflow Completed - Cancelled',
            EntityApprovalWorkflow::STATUS_ESCALATED => 'Workflow Completed - Escalated',
            default => 'Workflow Completed',
        };
    }

    /**
     * Get the event description for notifications.
     */
    public function getDescription(): string
    {
        $entityName = $this->getEntityDisplayName();
        $processingTime = $this->getTotalProcessingTime()['human_readable'];
        $totalLevels = $this->workflow->total_approval_required;

        return match($this->workflow->current_status) {
            EntityApprovalWorkflow::STATUS_APPROVED => "Approval workflow for {$entityName} has been completed and approved. Total processing time: {$processingTime}.",
            EntityApprovalWorkflow::STATUS_REJECTED => "Approval workflow for {$entityName} has been completed and rejected. Total processing time: {$processingTime}.",
            EntityApprovalWorkflow::STATUS_CANCELLED => "Approval workflow for {$entityName} has been cancelled. Total processing time: {$processingTime}.",
            EntityApprovalWorkflow::STATUS_ESCALATED => "Approval workflow for {$entityName} has been escalated for administrative review. Total processing time: {$processingTime}.",
            default => "Approval workflow for {$entityName} has been completed. Total processing time: {$processingTime}.",
        };
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

        // Notify all approvers who participated
        $participatingApprovers = $this->workflow->steps()
            ->whereIn('action', [
                EntityApprovalWorkflow::ACTION_APPROVED,
                EntityApprovalWorkflow::ACTION_REJECTED,
                EntityApprovalWorkflow::ACTION_DELEGATED,
                EntityApprovalWorkflow::ACTION_SKIPPED,
            ])
            ->with(['approverUser', 'delegatedToUser'])
            ->get()
            ->pluck('effective_approver')
            ->filter();

        foreach ($participatingApprovers as $approver) {
            $recipients[] = $approver;
        }

        // Notify administrators for escalated workflows
        if ($this->workflow->current_status === EntityApprovalWorkflow::STATUS_ESCALATED) {
            $adminUsers = \App\Models\User::whereHas('roles', function ($query) {
                $query->where('name', 'admin');
            })->get();

            foreach ($adminUsers as $admin) {
                $recipients[] = $admin;
            }
        }

        return array_unique($recipients);
    }

    /**
     * Get the event priority.
     */
    public function getPriority(): string
    {
        return match($this->workflow->current_status) {
            EntityApprovalWorkflow::STATUS_REJECTED => 'critical',
            EntityApprovalWorkflow::STATUS_APPROVED => 'high',
            EntityApprovalWorkflow::STATUS_ESCALATED => 'high',
            EntityApprovalWorkflow::STATUS_CANCELLED => 'medium',
            default => 'medium',
        };
    }

    /**
     * Get event type.
     */
    public function getType(): string
    {
        return match($this->workflow->current_status) {
            EntityApprovalWorkflow::STATUS_APPROVED => 'workflow_approved',
            EntityApprovalWorkflow::STATUS_REJECTED => 'workflow_rejected',
            EntityApprovalWorkflow::STATUS_CANCELLED => 'workflow_cancelled',
            EntityApprovalWorkflow::STATUS_ESCALATED => 'workflow_escalated',
            default => 'workflow_completed',
        };
    }

    /**
     * Check if workflow was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->workflow->current_status === EntityApprovalWorkflow::STATUS_APPROVED;
    }

    /**
     * Check if workflow was completed early (before deadline).
     */
    public function wasCompletedEarly(): bool
    {
        // This would require checking if any steps were overdue
        // For simplicity, we'll consider it early if total processing time is under 24 hours
        $totalHours = $this->workflow->initiated_at->diffInHours($this->workflow->completed_at);
        return $totalHours < 24;
    }

    /**
     * Get the completion summary.
     */
    public function getCompletionSummary(): array
    {
        $steps = $this->workflow->steps;
        $approvedCount = $steps->where('action', EntityApprovalWorkflow::ACTION_APPROVED)->count();
        $rejectedCount = $steps->where('action', EntityApprovalWorkflow::ACTION_REJECTED)->count();
        $delegatedCount = $steps->where('action', EntityApprovalWorkflow::ACTION_DELEGATED)->count();
        $skippedCount = $steps->where('action', EntityApprovalWorkflow::ACTION_SKIPPED)->count();

        return [
            'total_steps' => $steps->count(),
            'approved' => $approvedCount,
            'rejected' => $rejectedCount,
            'delegated' => $delegatedCount,
            'skipped' => $skippedCount,
            'success_rate' => $steps->count() > 0 ? round(($approvedCount / $steps->count()) * 100, 2) : 0,
        ];
    }
}