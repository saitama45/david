<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkApprovalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->user()->can('bulk approve workflows');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $action = $this->input('action');

        return [
            'workflow_ids' => ['required', 'array', 'min:1', 'max:50'],
            'workflow_ids.*' => ['integer', 'exists:entity_approval_workflows,id'],
            'action' => ['required', 'string', 'in:approve,reject'],
            'reason' => $this->getReasonValidationRule($action),
            'apply_to_all' => ['nullable', 'boolean'],
            'skip_existing' => ['nullable', 'boolean'],
            'send_notifications' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get the validation rule for the reason field based on action.
     */
    protected function getReasonValidationRule(string $action): array
    {
        return match($action) {
            'reject' => ['required', 'string', 'max:500'],
            'approve' => ['nullable', 'string', 'max:500'],
            default => ['nullable', 'string', 'max:500'],
        };
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'workflow_ids.required' => 'Workflow IDs are required.',
            'workflow_ids.min' => 'At least 1 workflow must be selected.',
            'workflow_ids.max' => 'Cannot process more than 50 workflows at once.',
            'workflow_ids.*.exists' => 'One or more selected workflows do not exist.',
            'action.required' => 'Action is required.',
            'action.in' => 'Invalid action specified.',
            'reason.required' => 'Reason is required for bulk rejection.',
            'reason.max' => 'Reason cannot exceed 500 characters.',
            'apply_to_all.boolean' => 'Apply to all must be true or false.',
            'skip_existing.boolean' => 'Skip existing must be true or false.',
            'send_notifications.boolean' => 'Send notifications must be true or false.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'workflow_ids' => 'workflows',
            'workflow_ids.*' => 'workflow',
            'action' => 'action',
            'reason' => 'reason',
            'apply_to_all' => 'apply to all',
            'skip_existing' => 'skip existing',
            'send_notifications' => 'send notifications',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $workflowIds = $this->input('workflow_ids', []);
                $action = $this->input('action');
                $user = auth()->user();

                if (empty($workflowIds)) {
                    return;
                }

                // Check if user has authority for all workflows
                if ($user) {
                    $unauthorizedCount = 0;
                    foreach ($workflowIds as $workflowId) {
                        $workflow = \App\Models\EntityApprovalWorkflow::find($workflowId);
                        if ($workflow && !app(\App\Http\Services\ApprovalMatrixService::class)->canApprove($user, $workflow)) {
                            $unauthorizedCount++;
                        }
                    }

                    if ($unauthorizedCount > 0) {
                        $validator->errors()->add('workflow_ids', "You are not authorized to approve {$unauthorizedCount} of the selected workflows.");
                    }
                }

                // Check if workflows are in correct status
                $invalidStatusCount = \App\Models\EntityApprovalWorkflow::whereIn('id', $workflowIds)
                    ->where('current_status', '!=', \App\Models\EntityApprovalWorkflow::STATUS_PENDING)
                    ->count();

                if ($invalidStatusCount > 0) {
                    $validator->errors()->add('workflow_ids', "{$invalidStatusCount} of the selected workflows are not in pending status.");
                }

                // Check if workflows are active
                $inactiveCount = \App\Models\EntityApprovalWorkflow::whereIn('id', $workflowIds)
                    ->where('is_active', false)
                    ->count();

                if ($inactiveCount > 0 && !$this->boolean('skip_existing')) {
                    $validator->errors()->add('workflow_ids', "{$inactiveCount} of the selected workflows are inactive. Use 'skip existing' to ignore them.");
                }

                // Validate reason for rejection
                if ($action === 'reject' && !$this->filled('reason')) {
                    $validator->errors()->add('reason', 'Reason is required when rejecting workflows.');
                }
            },
        ];
    }

    /**
     * Get validated data with processing.
     */
    public function validated(): array
    {
        $data = parent::validated();

        // Add metadata for processing
        $data['user_id'] = auth()->id();
        $data['processed_at'] = now();
        $data['batch_id'] = uniqid();

        return $data;
    }
}