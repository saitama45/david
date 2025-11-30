<?php

namespace App\Policies;

use App\Models\ApprovalMatrix;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ApprovalMatrixPolicy
{
    /**
     * Determine whether the user can view any approval matrices.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission([
            'manage approval matrices',
            'view approval matrices',
            'initiate approval workflows',
        ]);
    }

    /**
     * Determine whether the user can view the approval matrix.
     */
    public function view(User $user, ApprovalMatrix $matrix): bool
    {
        // Users with manage permission can view all matrices
        if ($user->hasPermission('manage approval matrices')) {
            return true;
        }

        // Users with view permission can view all active matrices
        if ($user->hasPermission('view approval matrices') && $matrix->is_active) {
            return true;
        }

        // Users can view matrices they are approvers for
        if ($matrix->approvers()->where('user_id', $user->id)->exists()) {
            return true;
        }

        // Users can view matrices if they can initiate workflows for the module
        if ($user->hasPermission('initiate approval workflows')) {
            return $matrix->isCurrentlyValid();
        }

        return false;
    }

    /**
     * Determine whether the user can create approval matrices.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can update the approval matrix.
     */
    public function update(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can delete the approval matrix.
     */
    public function delete(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can restore the approval matrix.
     */
    public function restore(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can permanently delete the approval matrix.
     */
    public function forceDelete(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can clone the approval matrix.
     */
    public function clone(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can toggle active status of the approval matrix.
     */
    public function toggleActive(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can validate the approval matrix configuration.
     */
    public function validate(User $user): bool
    {
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can manage approvers for the matrix.
     */
    public function manageApprovers(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can view approvers for the matrix.
     */
    public function viewApprovers(User $user, ApprovalMatrix $matrix): bool
    {
        // Users with manage permission can see all approvers
        if ($user->hasPermission('manage approval matrices')) {
            return true;
        }

        // Users can see approvers for matrices they are involved in
        if ($matrix->approvers()->where('user_id', $user->id)->exists()) {
            return true;
        }

        // Users who can initiate workflows can see approver information
        if ($user->hasPermission('initiate approval workflows') && $matrix->isCurrentlyValid()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delegate approval authority.
     */
    public function delegate(User $user, ApprovalMatrix $matrix): bool
    {
        // Check if user has permission to delegate
        if (!$user->hasPermission('delegate approvals')) {
            return false;
        }

        // Check if user is an approver for this matrix and can delegate
        $approver = $matrix->approvers()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('can_delegate', true)
            ->first();

        return $approver && $approver->isCurrentlyActive();
    }

    /**
     * Determine whether the user can manage delegations for the matrix.
     */
    public function manageDelegations(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can view delegation history for the matrix.
     */
    public function viewDelegations(User $user, ApprovalMatrix $matrix): bool
    {
        // Users with manage permission can see all delegations
        if ($user->hasPermission('manage approval matrices')) {
            return true;
        }

        // Users can see delegations they are involved in
        return $matrix->delegations()
            ->where(function ($query) use ($user) {
                $query->where('delegate_from_user_id', $user->id)
                    ->orWhere('delegate_to_user_id', $user->id);
            })
            ->exists();
    }

    /**
     * Determine whether the user can create workflows using this matrix.
     */
    public function initiateWorkflows(User $user, ApprovalMatrix $matrix): bool
    {
        // Must have permission to initiate workflows
        if (!$user->hasPermission('initiate approval workflows')) {
            return false;
        }

        // Matrix must be currently valid
        return $matrix->isCurrentlyValid();
    }

    /**
     * Determine whether the user can view analytics for the matrix.
     */
    public function viewAnalytics(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasAnyPermission([
            'manage approval matrices',
            'view approval analytics',
        ]);
    }

    /**
     * Determine whether the user can export data from the matrix.
     */
    public function export(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasAnyPermission([
            'manage approval matrices',
            'export approval data',
        ]);
    }

    /**
     * Determine whether the user can audit the matrix.
     */
    public function audit(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasAnyPermission([
            'manage approval matrices',
            'audit approval matrices',
        ]);
    }

    /**
     * Determine whether the user can override approval rules.
     */
    public function override(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasPermission('override approval rules');
    }

    /**
     * Determine whether the user can view matrix configuration.
     */
    public function viewConfiguration(User $user, ApprovalMatrix $matrix): bool
    {
        // Users with manage permission can see full configuration
        if ($user->hasPermission('manage approval matrices')) {
            return true;
        }

        // Users with initiate permission can see basic configuration
        if ($user->hasPermission('initiate approval workflows') && $matrix->isCurrentlyValid()) {
            return true;
        }

        // Users who are approvers can see relevant configuration
        if ($matrix->approvers()->where('user_id', $user->id)->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can test the matrix configuration.
     */
    public function test(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can view matrix activity logs.
     */
    public function viewActivity(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasAnyPermission([
            'manage approval matrices',
            'view approval activity',
        ]);
    }

    /**
     * Determine whether the user can pause or resume the matrix.
     */
    public function pause(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasPermission('manage approval matrices');
    }

    /**
     * Determine whether the user can prioritize the matrix.
     */
    public function prioritize(User $user, ApprovalMatrix $matrix): bool
    {
        return $user->hasPermission('manage approval matrices');
    }
}