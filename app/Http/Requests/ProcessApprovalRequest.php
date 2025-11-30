<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessApprovalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled in the controller method
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $action = $this->input('action');

        return [
            'action' => ['required', 'string', 'in:approve,reject,delegate'],
            'reason' => $this->getReasonValidationRule($action),
            'delegate_to_user_id' => ['required_if:action,delegate', 'integer', 'exists:users,id', 'different:approver_user_id'],
            'delegation_reason' => ['required_if:action,delegate', 'string', 'max:500'],
            'max_delegation_amount' => ['nullable', 'numeric', 'min:0'],
            'can_further_delegate' => ['nullable', 'boolean'],
            'end_date' => ['nullable', 'date', 'after:today'],
            'item_quantities' => ['nullable', 'array'],
            'item_quantities.*' => ['nullable', 'integer', 'min:0'],
            'comments' => ['nullable', 'string', 'max:1000'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png'],
        ];
    }

    /**
     * Get the validation rule for the reason field based on action.
     */
    protected function getReasonValidationRule(string $action): array
    {
        return match($action) {
            'reject' => ['required', 'string', 'max:500'],
            'delegate' => ['nullable', 'string', 'max:500'],
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
            'action.required' => 'Action is required.',
            'action.in' => 'Invalid action specified.',
            'reason.required' => 'Reason is required for rejection.',
            'reason.max' => 'Reason cannot exceed 500 characters.',
            'delegate_to_user_id.required_if' => 'Delegation target user is required when action is delegate.',
            'delegate_to_user_id.exists' => 'Selected delegation user does not exist.',
            'delegate_to_user_id.different' => 'Cannot delegate to yourself.',
            'delegation_reason.required_if' => 'Delegation reason is required when action is delegate.',
            'delegation_reason.max' => 'Delegation reason cannot exceed 500 characters.',
            'max_delegation_amount.numeric' => 'Max delegation amount must be a number.',
            'max_delegation_amount.min' => 'Max delegation amount must be at least 0.',
            'end_date.after' => 'End date must be after today.',
            'item_quantities.*.integer' => 'Item quantities must be whole numbers.',
            'item_quantities.*.min' => 'Item quantities cannot be negative.',
            'comments.max' => 'Comments cannot exceed 1000 characters.',
            'attachments.*.max' => 'Attachment size cannot exceed 10MB.',
            'attachments.*.mimes' => 'Attachments must be PDF, DOC, DOCX, JPG, JPEG, or PNG files.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'action' => 'action',
            'reason' => 'reason',
            'delegate_to_user_id' => 'delegation target user',
            'delegation_reason' => 'delegation reason',
            'max_delegation_amount' => 'max delegation amount',
            'can_further_delegate' => 'can further delegate',
            'end_date' => 'end date',
            'item_quantities' => 'item quantities',
            'item_quantities.*' => 'item quantity',
            'comments' => 'comments',
            'attachments' => 'attachments',
            'attachments.*' => 'attachment',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $action = $this->input('action');

                if ($action === 'delegate') {
                    // Validate delegation end date is not too far in future
                    $endDate = $this->input('end_date');
                    $startDate = now();

                    if ($endDate && $startDate->diffInDays($endDate) > 30) {
                        $validator->errors()->add('end_date', 'Delegation cannot exceed 30 days.');
                    }

                    // Validate delegation amount limits
                    $maxDelegationAmount = $this->input('max_delegation_amount');
                    if ($maxDelegationAmount && $maxDelegationAmount > 1000000) {
                        $validator->errors()->add('max_delegation_amount', 'Max delegation amount cannot exceed 1,000,000.');
                    }
                }

                // Validate item quantities if provided
                if ($this->has('item_quantities') && is_array($this->input('item_quantities'))) {
                    $itemQuantities = $this->input('item_quantities');
                    foreach ($itemQuantities as $itemId => $quantity) {
                        if (!is_numeric($quantity)) {
                            $validator->errors()->add("item_quantities.{$itemId}", 'Item quantity must be a number.');
                        }
                    }
                }

                // Validate attachments
                if ($this->hasFile('attachments')) {
                    $attachments = $this->file('attachments');
                    if ($attachments && $attachments->count() > 5) {
                        $validator->errors()->add('attachments', 'Cannot upload more than 5 attachments.');
                    }
                }

                // For approval actions, ensure user is authorized to approve this level
                if ($action === 'approve') {
                    $workflow = $this->route('workflow');
                    $user = auth()->user();

                    if ($workflow && $user) {
                        // This will be checked in the controller but we can add pre-validation
                        $currentStep = $workflow->current_step;
                        if (!$currentStep) {
                            $validator->errors()->add('action', 'No pending approval step found.');
                        }
                    }
                }
            },
        ];
    }
}