<?php

namespace App\Policies;

use App\Models\EntityApprovalWorkflow;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ApprovalWorkflowPolicy
{
    /**
     * Determine whether the user can view any workflows.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([
            'manage approval matrices',
            'view approval workflows',
            'approve store orders',
            'approve wastages',
        ]);
    }

    /**
     * Determine whether the user can view the workflow.
     */
    public function view(User $user, EntityApprovalWorkflow $workflow): bool
    {
        // Users with manage permission can view all workflows
        if ($user->hasPermission('manage approval matrices')) {
            return true;
        }

        // Users with view permission can view workflows
        if ($user->hasPermission('view approval workflows')) {
            return true;
        }

        // Workflow initiator can always view their own workflows
        if ($workflow->initiatedBy && $workflow->initiatedBy->is($user)) {
            return true;
        }

        // Users who are approvers for this workflow can view it
        if ($workflow->steps()->where('approver_user_id', $user->id)->exists()) {
            return true;
        }

        // Users who have been delegated authority can view it
        if ($workflow->steps()->where('delegated_to_user_id', $user->id)->exists()) {
            return true;
        }

        // Users with entity-specific permissions can view
        return $this->canViewEntityWorkflow($user, $workflow);
    }

    /**
     * Determine whether the user can approve the workflow.
     */
    public function approve(User $user, EntityApprovalWorkflow $workflow): bool
    {
        // Workflow must be pending and active
        if ($workflow->current_status !== EntityApprovalWorkflow::STATUS_PENDING || !$workflow->is_active) {
            return false;
        }

        // Users with override permission can approve any workflow
        if ($user->hasPermission('override approval rules')) {
            return true;
        }

        // Check if user has current pending step
        $currentStep = $workflow->steps()
            ->where('approval_level', $workflow->current_approval_level)
            ->where('action', EntityApprovalWorkflow::ACTION_PENDING)
            ->where('is_active', true)
            ->where(function ($query) use ($user) {
                $query->where('approver_user_id', $user->id)
                    ->orWhere('delegated_to_user_id', $user->id);
            })
            ->first();

        if (!$currentStep) {
            return false;
        }

        // Check if user has module-specific approval permissions
        return $this->hasApprovalPermission($user, $workflow);
    }

    /**
     * Determine whether the user can reject the workflow.
     */
    public function reject(User $user, EntityApprovalWorkflow $workflow): bool
    {
        return $this->approve($user, $workflow); // Same logic as approve
    }

    /**
     * Determine whether the user can delegate the workflow.
     */
    public function delegate(User $user, EntityApprovalWorkflow $workflow): bool
    {
        // Must have basic approval permission
        if (!$this->approve($user, $workflow)) {
            return false;
        }

        // Must have delegation permission
        if (!$user->hasPermission('delegate approvals')) {
            return false;
        }

        // Check if approver assignment allows delegation
        $currentStep = $workflow->current_step;
        if (!$currentStep) {
            return false;
        }

        $approverAssignment = $workflow->approvalMatrix?->approvers()
            ->where('user_id', $user->id)
            ->where('approval_level', $currentStep->approval_level)
            ->where('is_active', true)
            ->where('can_delegate', true)
            ->first();

        return $approverAssignment && $approverAssignment->isCurrentlyActive();
    }

    /**
     * Determine whether the user can cancel the workflow.
     */
    public function cancel(User $user, EntityApprovalWorkflow $workflow): bool
    {
        // Workflow must be active
        if (!$workflow->is_active) {
            return false;
        }

        // Workflow initiator can cancel their own workflows
        if ($workflow->initiatedBy && $workflow->initiatedBy->is($user)) {
            return true;
        }

        // Users with manage permission can cancel any workflow
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can escalate the workflow.
     */
    public function escalate(User $user, EntityApprovalWorkflow $workflow): bool
    {
        // Workflow must be active and not already escalated
        if (!$workflow->is_active || $workflow->current_status === EntityApprovalWorkflow::STATUS_ESCALATED) {
            return false;
        }

        // Only users with manage permission can escalate
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can skip an approval step.
     */
    public function skip(User $user, EntityApprovalWorkflow $workflow): bool
    {
        // Must have override permission
        if (!$user->hasPermission('override approval rules')) {
            return false;
        }

        // Must have basic approval permission
        return $this->approve($user, $workflow);
    }

    /**
     * Determine whether the user can reassign the workflow.
     */
    public function reassign(User $user, EntityApprovalWorkflow $workflow): bool
    {
        // Only users with manage permission can reassign
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can add comments to the workflow.
     */
    public function comment(User $user, EntityApprovalWorkflow $workflow): bool
    {
        return $this->view($user, $workflow);
    }

    /**
     * Determine whether the user can view workflow history.
     */
    public function viewHistory(User $user, EntityApprovalWorkflow $workflow): bool
    {
        return $this->view($user, $workflow);
    }

    /**
     * Determine whether the user can view workflow analytics.
     */
    public function viewAnalytics(User $user, EntityApprovalWorkflow $workflow): bool
    {
        return $user->hasAnyPermission([
            'manage approval matrices',
            'view approval analytics',
        ]);
    }

    /**
     * Determine whether the user can export workflow data.
     */
    public function export(User $user, EntityApprovalWorkflow $workflow): bool
    {
        // Users can export workflows they can view
        if ($this->view($user, $workflow)) {
            return $user->hasPermission('export approval data');
        }

        return false;
    }

    /**
     * Determine whether the user can audit the workflow.
     */
    public function audit(User $user, EntityApprovalWorkflow $workflow): bool
    {
        return $user->hasAnyPermission([
            'manage approval matrices',
            'audit approval workflows',
        ]);
    }

    /**
     * Determine whether the user can bulk process workflows.
     */
    public function bulkProcess(User $user): bool
    {
        return $user->hasPermission('bulk approve workflows');
    }

    /**
     * Determine whether the user can view entity details.
     */
    public function viewEntity(User $user, EntityApprovalWorkflow $workflow): bool
    {
        // Must be able to view workflow first
        if (!$this->view($user, $workflow)) {
            return false;
        }

        return $this->canViewEntity($user, $workflow->entity);
    }

    /**
     * Determine whether the user can modify entity during approval.
     */
    public function modifyEntity(User $user, EntityApprovalWorkflow $workflow): bool
    {
        // Workflow initiator can modify if still pending
        if ($workflow->initiatedBy && $workflow->initiatedBy->is($user)) {
            return $workflow->current_status === EntityApprovalWorkflow::STATUS_PENDING;
        }

        // Users with override permission can modify
        return $user->hasPermission('override approval rules');
    }

    /**
     * Determine whether the user can view approval matrix details.
     */
    public function viewMatrix(User $user, EntityApprovalWorkflow $workflow): bool
    {
        // Must be able to view workflow first
        if (!$this->view($user, $workflow)) {
            return false;
        }

        // Users with manage permission can view matrix
        if ($user->hasPermission('manage approval matrices')) {
            return true;
        }

        // Approvers can view matrix they're assigned to
        if ($workflow->steps()->where('approver_user_id', $user->id)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can override deadlines.
     */
    public function overrideDeadline(User $user, EntityApprovalWorkflow $workflow): bool
    {
        return $user->hasPermission('override approval rules');
    }

    /**
     * Determine whether the user can send reminders.
     */
    public function sendReminders(User $user, EntityApprovalWorkflow $workflow): bool
    {
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can add attachments.
     */
    public function addAttachments(User $user, EntityApprovalWorkflow $workflow): bool
    {
        // Workflow initiator can add attachments
        if ($workflow->initiatedBy && $workflow->initiatedBy->is($user)) {
            return $workflow->current_status === EntityApprovalWorkflow::STATUS_PENDING;
        }

        // Approvers can add attachments
        if ($this->approve($user, $workflow)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can view attachments.
     */
    public function viewAttachments(User $user, EntityApprovalWorkflow $workflow): bool
    {
        return $this->view($user, $workflow);
    }

    /**
     * Determine whether the user can modify approval amounts.
     */
    public function modifyAmounts(User $user, EntityApprovalWorkflow $workflow): bool
    {
        // Only approvers can modify amounts during approval
        return $this->approve($user, $workflow);
    }

    /**
     * Check if user has entity-specific approval permission.
     */
    protected function hasApprovalPermission(User $user, EntityApprovalWorkflow $workflow): bool
    {
        $entityType = $workflow->entity_type;

        return match($entityType) {
            'store_order' => $user->hasPermission('approve store orders'),
            'wastage' => $user->hasPermission('approve wastages'),
            'interco_transfer' => $user->hasPermission('approve interco transfers'),
            default => false,
        };
    }

    /**
     * Check if user can view entity-specific workflow.
     */
    protected function canViewEntityWorkflow(User $user, EntityApprovalWorkflow $workflow): bool
    {
        $entity = $workflow->entity;
        return $this->canViewEntity($user, $entity);
    }

    /**
     * Check if user can view entity.
     */
    protected function canViewEntity(User $user, $entity): bool
    {
        if (!$entity) {
            return false;
        }

        return match(get_class($entity)) {
            \App\Models\StoreOrder::class => $user->hasPermission('view store orders'),
            \App\Models\Wastage::class => $user->hasPermission('view wastages'),
            \App\Models\IntercoTransfer::class => $user->hasPermission('view interco transfers'),
            default => false,
        };
    }
}