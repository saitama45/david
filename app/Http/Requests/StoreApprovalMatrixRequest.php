<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApprovalMatrixRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->user()->can('manage approval matrices');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'matrix_name' => ['required', 'string', 'max:255'],
            'module_name' => ['required', 'string', 'in:store_orders,wastages,interco_transfers'],
            'entity_type' => ['required', 'string', 'max:100'],
            'approval_levels' => ['required', 'integer', 'min:1', 'max:10'],
            'approval_type' => ['nullable', 'string', 'in:sequential,parallel'],
            'basis_column' => ['required', 'string', 'max:100'],
            'basis_operator' => ['required', 'string', 'in:equals,not_equals,in,not_in,greater_than,less_than,between'],
            'basis_value' => ['required', 'array'],
            'minimum_amount' => ['nullable', 'numeric', 'min:0'],
            'maximum_amount' => ['nullable', 'numeric', 'min:0', 'gt:minimum_amount'],
            'is_active' => ['nullable', 'boolean'],
            'effective_date' => ['nullable', 'date', 'after_or_equal:today'],
            'expiry_date' => ['nullable', 'date', 'after:effective_date'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:999'],
            'description' => ['nullable', 'string', 'max:1000'],

            // Rules validation
            'rules' => ['nullable', 'array'],
            'rules.*.condition_group' => ['nullable', 'integer', 'min:1', 'max:10'],
            'rules.*.condition_logic' => ['nullable', 'string', 'in:AND,OR'],
            'rules.*.condition_column' => ['required_with:rules', 'string', 'max:100'],
            'rules.*.condition_operator' => [
                'required_with:rules',
                'string',
                'in:equals,not_equals,in,not_in,greater_than,less_than,between,not_between,like,not_like,is_null,is_not_null'
            ],
            'rules.*.condition_value' => ['nullable', 'array'],
            'rules.*.is_active' => ['nullable', 'boolean'],

            // Approvers validation
            'approvers' => ['required', 'array', 'min:1'],
            'approvers.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'approvers.*.approval_level' => ['required', 'integer', 'min:1', 'max:10'],
            'approvers.*.is_primary' => ['nullable', 'boolean'],
            'approvers.*.is_backup' => ['nullable', 'boolean'],
            'approvers.*.can_delegate' => ['nullable', 'boolean'],
            'approvers.*.approval_limit_amount' => ['nullable', 'numeric', 'min:0'],
            'approvers.*.approval_limit_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'approvers.*.approval_deadline_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'approvers.*.business_hours_only' => ['nullable', 'boolean'],
            'approvers.*.is_active' => ['nullable', 'boolean'],
            'approvers.*.effective_date' => ['nullable', 'date', 'after_or_equal:today'],
            'approvers.*.expiry_date' => ['nullable', 'date', 'after:effective_date'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'matrix_name.required' => 'Matrix name is required.',
            'module_name.required' => 'Module name is required.',
            'module_name.in' => 'Invalid module selected.',
            'entity_type.required' => 'Entity type is required.',
            'approval_levels.required' => 'Approval levels is required.',
            'approval_levels.min' => 'At least 1 approval level is required.',
            'approval_levels.max' => 'Maximum 10 approval levels allowed.',
            'basis_column.required' => 'Basis column is required.',
            'basis_operator.required' => 'Basis operator is required.',
            'basis_operator.in' => 'Invalid basis operator selected.',
            'basis_value.required' => 'Basis value is required.',
            'maximum_amount.gt' => 'Maximum amount must be greater than minimum amount.',
            'approvers.required' => 'At least one approver is required.',
            'approvers.min' => 'At least one approver is required.',
            'approvers.*.user_id.required' => 'Approver user is required.',
            'approvers.*.user_id.exists' => 'Selected user does not exist.',
            'approvers.*.approval_level.required' => 'Approval level is required.',
            'approvers.*.approval_level.min' => 'Approval level must be at least 1.',
            'approvers.*.approval_level.max' => 'Approval level cannot exceed 10.',
            'expiry_date.after' => 'Expiry date must be after effective date.',
            'approvers.*.expiry_date.after' => 'Expiry date must be after effective date.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function after(): array
    {
        return [
            function ($validator) {
                // Validate that approval levels match specified approval_levels
                $approvalLevels = $this->input('approval_levels');
                $approvers = $this->input('approvers', []);

                // Check if all levels from 1 to approval_levels have approvers
                for ($level = 1; $level <= $approvalLevels; $level++) {
                    $hasApprover = collect($approvers)->contains('approval_level', $level);
                    if (!$hasApprover) {
                        $validator->errors()->add('approvers', "Level {$level} requires at least one approver.");
                    }
                }

                // Check that each level has at least one primary approver
                $levelsWithPrimary = collect($approvers)
                    ->filter(fn($approver) => ($approver['is_primary'] ?? false))
                    ->pluck('approval_level')
                    ->unique()
                    ->toArray();

                for ($level = 1; $level <= $approvalLevels; $level++) {
                    if (!in_array($level, $levelsWithPrimary)) {
                        $validator->errors()->add('approvers', "Level {$level} requires at least one primary approver.");
                    }
                }

                // Validate basis_value format based on operator
                $basisOperator = $this->input('basis_operator');
                $basisValue = $this->input('basis_value');

                if ($basisOperator === 'between' && count($basisValue) !== 2) {
                    $validator->errors()->add('basis_value', 'Between operator requires exactly 2 values (min and max).');
                }

                if (in_array($basisOperator, ['in', 'not_in']) && empty($basisValue)) {
                    $validator->errors()->add('basis_value', 'In/Not In operator requires at least 1 value.');
                }

                if (in_array($basisOperator, ['equals', 'not_equals', 'greater_than', 'less_than']) && empty($basisValue)) {
                    $validator->errors()->add('basis_value', 'This operator requires a value.');
                }
            },
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'matrix_name' => 'matrix name',
            'module_name' => 'module name',
            'entity_type' => 'entity type',
            'approval_levels' => 'approval levels',
            'approval_type' => 'approval type',
            'basis_column' => 'basis column',
            'basis_operator' => 'basis operator',
            'basis_value' => 'basis value',
            'minimum_amount' => 'minimum amount',
            'maximum_amount' => 'maximum amount',
            'is_active' => 'is active',
            'effective_date' => 'effective date',
            'expiry_date' => 'expiry date',
            'priority' => 'priority',
            'description' => 'description',
            'rules.*.condition_group' => 'condition group',
            'rules.*.condition_logic' => 'condition logic',
            'rules.*.condition_column' => 'condition column',
            'rules.*.condition_operator' => 'condition operator',
            'rules.*.condition_value' => 'condition value',
            'rules.*.is_active' => 'is active',
            'approvers.*.user_id' => 'approver user',
            'approvers.*.approval_level' => 'approval level',
            'approvers.*.is_primary' => 'is primary',
            'approvers.*.is_backup' => 'is backup',
            'approvers.*.can_delegate' => 'can delegate',
            'approvers.*.approval_limit_amount' => 'approval limit amount',
            'approvers.*.approval_limit_percentage' => 'approval limit percentage',
            'approvers.*.approval_deadline_hours' => 'approval deadline hours',
            'approvers.*.business_hours_only' => 'business hours only',
            'approvers.*.is_active' => 'is active',
            'approvers.*.effective_date' => 'effective date',
            'approvers.*.expiry_date' => 'expiry date',
        ];
    }
}