<?php

namespace App\Http\Services;

use App\Models\EntityApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use App\Models\User;
use App\Models\StoreOrder;
use App\Models\Wastage;
use App\Events\WorkflowInitiated;
use App\Events\ApprovalActionProcessed;
use App\Events\WorkflowCompleted;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class ApprovalNotificationService
{
    /**
     * Handle workflow initiation
     */
    public function handleWorkflowInitiated(WorkflowInitiated $event): void
    {
        $workflow = $event->workflow;
        $entity = $workflow->entity;

        if (!$entity) {
            return;
        }

        // Notify approvers for current level
        $currentStepApprovers = $workflow->getCurrentLevelApprovers;

        foreach ($currentStepApprovers as $step) {
            $approver = $step->effective_approver;
            if ($approver) {
                $this->sendApprovalNotification($approver, $workflow, $entity, $step);
            }
        }

        // Notify initiator
        $initiator = $workflow->initiatedBy;
        if ($initiator && method_exists($initiator, 'notify')) {
            $this->sendWorkflowInitiatedNotification($initiator, $workflow, $entity);
        }

        Log::info("Notifications sent for workflow initiation: {$workflow->id}");
    }

    /**
     * Handle approval action processed
     */
    public function handleApprovalActionProcessed(ApprovalActionProcessed $event): void
    {
        $workflow = $event->workflow;
        $step = $event->step;
        $entity = $workflow->entity;

        if (!$entity) {
            return;
        }

        $action = $step->action;
        $approver = $step->approverUser;

        switch ($action) {
            case ApprovalWorkflowStep::ACTION_APPROVED:
                $this->handleApprovalAction($approver, $workflow, $entity, $step);
                break;

            case ApprovalWorkflowStep::ACTION_REJECTED:
                $this->handleRejectionAction($approver, $workflow, $entity, $step);
                break;

            case ApprovalWorkflowStep::ACTION_DELEGATED:
                $this->handleDelegationAction($approver, $workflow, $entity, $step);
                break;
        }

        Log::info("Notifications sent for approval action: {$workflow->id}, action: {$action}");
    }

    /**
     * Handle workflow completion
     */
    public function handleWorkflowCompleted(WorkflowCompleted $event): void
    {
        $workflow = $event->workflow;
        $entity = $workflow->entity;

        if (!$entity) {
            return;
        }

        // Notify initiator about completion
        $initiator = $workflow->initiatedBy;
        if ($initiator && method_exists($initiator, 'notify')) {
            $this->sendWorkflowCompletedNotification($initiator, $workflow, $entity);
        }

        // Notify all involved approvers
        $involvedApprovers = $workflow->steps()
            ->whereIn('action', [
                ApprovalWorkflowStep::ACTION_APPROVED,
                ApprovalWorkflowStep::ACTION_REJECTED,
                ApprovalWorkflowStep::ACTION_DELEGATED,
            ])
            ->with(['approverUser', 'delegatedToUser'])
            ->get()
            ->pluck('effective_approver')
            ->filter()
            ->unique('id');

        foreach ($involvedApprovers as $approver) {
            $this->sendWorkflowCompletionNotification($approver, $workflow, $entity);
        }

        Log::info("Notifications sent for workflow completion: {$workflow->id}");
    }

    /**
     * Send approval notification to approver
     */
    protected function sendApprovalNotification(
        User $approver,
        EntityApprovalWorkflow $workflow,
        $entity,
        ApprovalWorkflowStep $step
    ): void {
        $data = $this->getNotificationData($workflow, $entity, $step);

        try {
            Mail::to($approver->email)->send(new \App\Mail\ApprovalRequired($data));

            if (method_exists($approver, 'notify')) {
                $approver->notify(new \App\Notifications\ApprovalRequired($data));
            }
        } catch (\Exception $e) {
            Log::error("Failed to send approval notification to user {$approver->id}: " . $e->getMessage());
        }
    }

    /**
     * Handle approval action notifications
     */
    protected function handleApprovalAction(
        User $approver,
        EntityApprovalWorkflow $workflow,
        $entity,
        ApprovalWorkflowStep $step
    ): void {
        // Notify other approvers at the same level
        $sameLevelApprovers = $workflow->steps()
            ->where('approval_level', $step->approval_level)
            ->where('action', ApprovalWorkflowStep::ACTION_PENDING)
            ->where('is_active', true)
            ->get()
            ->pluck('effective_approver')
            ->filter()
            ->where('id', '!=', $approver->id);

        foreach ($sameLevelApprovers as $otherApprover) {
            $this->sendPeerApprovalNotification($otherApprover, $workflow, $entity, $step);
        }

        // Notify initiator about progress
        $initiator = $workflow->initiatedBy;
        if ($initiator && $initiator->id !== $approver->id) {
            $this->sendApprovalProgressNotification($initiator, $workflow, $entity, $step, 'approved');
        }
    }

    /**
     * Handle rejection notifications
     */
    protected function handleRejectionAction(
        User $approver,
        EntityApprovalWorkflow $workflow,
        $entity,
        ApprovalWorkflowStep $step
    ): void {
        // Notify initiator immediately about rejection
        $initiator = $workflow->initiatedBy;
        if ($initiator) {
            $this->sendRejectionNotification($initiator, $workflow, $entity, $step);
        }

        // Notify other approvers
        $this->notifyAllApprovers($workflow, $entity, $step, 'rejected');
    }

    /**
     * Handle delegation notifications
     */
    protected function handleDelegationAction(
        User $approver,
        EntityApprovalWorkflow $workflow,
        $entity,
        ApprovalWorkflowStep $step
    ): void {
        $delegatedTo = $step->delegatedToUser;
        if ($delegatedTo) {
            $this->sendDelegationNotification($delegatedTo, $workflow, $entity, $step);
        }

        // Notify initiator about delegation
        $initiator = $workflow->initiatedBy;
        if ($initiator) {
            $this->sendDelegationProgressNotification($initiator, $workflow, $entity, $step);
        }
    }

    /**
     * Send notification data for emails/notifications
     */
    protected function getNotificationData(
        EntityApprovalWorkflow $workflow,
        $entity,
        ApprovalWorkflowStep $step
    ): array {
        return [
            'workflow_id' => $workflow->id,
            'entity_type' => $workflow->entity_type,
            'entity_id' => $workflow->entity_id,
            'entity_name' => $this->getEntityDisplayName($entity),
            'entity_reference' => $this->getEntityReference($entity),
            'approval_level' => $step->approval_level,
            'total_levels' => $workflow->total_approval_required,
            'deadline' => $step->deadline_at?->format('M d, Y H:i'),
            'urgency' => $step->deadline_urgency,
            'initiated_by' => $workflow->initiatedBy?->name,
            'initiated_at' => $workflow->initiated_at->format('M d, Y H:i'),
            'entity_url' => $this->getEntityUrl($entity),
            'approval_url' => $this->getApprovalUrl($workflow),
        ];
    }

    /**
     * Get display name for entity
     */
    protected function getEntityDisplayName($entity): string
    {
        return match(get_class($entity)) {
            StoreOrder::class => "Store Order #{$entity->order_number}",
            Wastage::class => "Wastage #{$entity->wastage_no}",
            default => "Entity #{$entity->id}",
        };
    }

    /**
     * Get entity reference number
     */
    protected function getEntityReference($entity): string
    {
        return match(get_class($entity)) {
            StoreOrder::class => $entity->order_number,
            Wastage::class => $entity->wastage_no,
            default => "#{$entity->id}",
        };
    }

    /**
     * Get entity URL
     */
    protected function getEntityUrl($entity): string
    {
        $baseUrl = config('app.url');

        return match(get_class($entity)) {
            StoreOrder::class => "{$baseUrl}/store-orders/{$entity->id}",
            Wastage::class => "{$baseUrl}/wastages/{$entity->id}",
            default => "{$baseUrl}/",
        };
    }

    /**
     * Get approval URL
     */
    protected function getApprovalUrl(EntityApprovalWorkflow $workflow): string
    {
        return config('app.url') . "/approvals/{$workflow->id}";
    }

    /**
     * Send overdue workflow notifications
     */
    public function sendOverdueNotifications(): int
    {
        $overdueWorkflows = EntityApprovalWorkflow::pending()
            ->active()
            ->whereHas('steps', function ($query) {
                $query->where('action', EntityApprovalWorkflow::ACTION_PENDING)
                    ->where('deadline_at', '<', now()->subHours(24))
                    ->where('is_active', true);
            })
            ->with(['entity', 'steps'])
            ->get();

        $notificationsSent = 0;

        foreach ($overdueWorkflows as $workflow) {
            $currentSteps = $workflow->getCurrentLevelApprovers;

            foreach ($currentSteps as $step) {
                $approver = $step->effective_approver;
                if ($approver) {
                    $this->sendOverdueNotification($approver, $workflow, $step);
                    $notificationsSent++;
                }
            }

            // Notify workflow initiator about overdue items
            $initiator = $workflow->initiatedBy;
            if ($initiator) {
                $this->sendOverdueInitiatorNotification($initiator, $workflow);
            }
        }

        Log::info("Sent {$notificationsSent} overdue workflow notifications");

        return $notificationsSent;
    }

    /**
     * Send escalation notifications
     */
    public function sendEscalationNotifications(EntityApprovalWorkflow $workflow): void
    {
        $entity = $workflow->entity;
        $initiator = $workflow->initiatedBy;

        // Get system administrators
        $adminUsers = User::whereHas('roles', function ($query) {
            $query->where('name', 'admin');
        })->get();

        foreach ($adminUsers as $admin) {
            $this->sendEscalationNotification($admin, $workflow, $entity);
        }

        // Notify initiator about escalation
        if ($initiator) {
            $this->sendEscalationInitiatorNotification($initiator, $workflow, $entity);
        }

        Log::info("Sent escalation notifications for workflow: {$workflow->id}");
    }

    /**
     * Send reminder notifications for pending approvals
     */
    public function sendReminderNotifications(): int
    {
        $pendingWorkflows = EntityApprovalWorkflow::pending()
            ->active()
            ->where('initiated_at', '>', now()->subDays(7)) // Last 7 days
            ->with(['entity', 'steps'])
            ->get();

        $remindersSent = 0;

        foreach ($pendingWorkflows as $workflow) {
            $currentSteps = $workflow->getCurrentLevelApprovers;

            foreach ($currentSteps as $step) {
                // Only send reminder if step has been pending for more than 1 day
                if ($step->assigned_at && $step->assigned_at < now()->subDay()) {
                    $approver = $step->effective_approver;
                    if ($approver) {
                        $this->sendReminderNotification($approver, $workflow, $step);
                        $remindersSent++;
                    }
                }
            }
        }

        Log::info("Sent {$remindersSent} reminder notifications");

        return $remindersSent;
    }

    // Helper methods for different notification types
    protected function sendWorkflowInitiatedNotification(User $initiator, EntityApprovalWorkflow $workflow, $entity): void
    {
        $data = $this->getNotificationData($workflow, $entity, $workflow->current_step);

        try {
            if (method_exists($initiator, 'notify')) {
                $initiator->notify(new \App\Notifications\WorkflowInitiated($data));
            }
        } catch (\Exception $e) {
            Log::error("Failed to send workflow initiated notification: " . $e->getMessage());
        }
    }

    protected function sendWorkflowCompletedNotification(User $user, EntityApprovalWorkflow $workflow, $entity): void
    {
        $data = $this->getNotificationData($workflow, $entity, $workflow->steps->last());

        try {
            if (method_exists($user, 'notify')) {
                $user->notify(new \App\Notifications\WorkflowCompleted($data));
            }
        } catch (\Exception $e) {
            Log::error("Failed to send workflow completed notification: " . $e->getMessage());
        }
    }

    protected function sendRejectionNotification(User $user, EntityApprovalWorkflow $workflow, $entity, ApprovalWorkflowStep $step): void
    {
        $data = $this->getNotificationData($workflow, $entity, $step);

        try {
            if (method_exists($user, 'notify')) {
                $user->notify(new \App\Notifications\WorkflowRejected($data));
            }
        } catch (\Exception $e) {
            Log::error("Failed to send rejection notification: " . $e->getMessage());
        }
    }

    protected function sendDelegationNotification(User $user, EntityApprovalWorkflow $workflow, $entity, ApprovalWorkflowStep $step): void
    {
        $data = $this->getNotificationData($workflow, $entity, $step);

        try {
            if (method_exists($user, 'notify')) {
                $user->notify(new \App\Notifications\ApprovalDelegated($data));
            }
        } catch (\Exception $e) {
            Log::error("Failed to send delegation notification: " . $e->getMessage());
        }
    }

    protected function sendOverdueNotification(User $approver, EntityApprovalWorkflow $workflow, ApprovalWorkflowStep $step): void
    {
        $data = $this->getNotificationData($workflow, $workflow->entity, $step);

        try {
            if (method_exists($approver, 'notify')) {
                $approver->notify(new \App\Notifications\ApprovalOverdue($data));
            }
        } catch (\Exception $e) {
            Log::error("Failed to send overdue notification: " . $e->getMessage());
        }
    }

    protected function sendReminderNotification(User $approver, EntityApprovalWorkflow $workflow, ApprovalWorkflowStep $step): void
    {
        $data = $this->getNotificationData($workflow, $workflow->entity, $step);

        try {
            if (method_exists($approver, 'notify')) {
                $approver->notify(new \App\Notifications\ApprovalReminder($data));
            }
        } catch (\Exception $e) {
            Log::error("Failed to send reminder notification: " . $e->getMessage());
        }
    }

    protected function sendEscalationNotification(User $admin, EntityApprovalWorkflow $workflow, $entity): void
    {
        $data = $this->getNotificationData($workflow, $entity, $workflow->steps->last());

        try {
            if (method_exists($admin, 'notify')) {
                $admin->notify(new \App\Notifications\WorkflowEscalated($data));
            }
        } catch (\Exception $e) {
            Log::error("Failed to send escalation notification: " . $e->getMessage());
        }
    }

    // Additional helper methods would be implemented here...
    protected function sendPeerApprovalNotification(User $approver, EntityApprovalWorkflow $workflow, $entity, ApprovalWorkflowStep $step): void { /* Implementation */ }
    protected function sendApprovalProgressNotification(User $initiator, EntityApprovalWorkflow $workflow, $entity, ApprovalWorkflowStep $step, string $action): void { /* Implementation */ }
    protected function notifyAllApprovers(EntityApprovalWorkflow $workflow, $entity, ApprovalWorkflowStep $step, string $action): void { /* Implementation */ }
    protected function sendDelegationProgressNotification(User $initiator, EntityApprovalWorkflow $workflow, $entity, ApprovalWorkflowStep $step): void { /* Implementation */ }
    protected function sendOverdueInitiatorNotification(User $initiator, EntityApprovalWorkflow $workflow): void { /* Implementation */ }
    protected function sendEscalationInitiatorNotification(User $initiator, EntityApprovalWorkflow $workflow, $entity): void { /* Implementation */ }
}