<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApprovalMatrixRequest extends StoreApprovalMatrixRequest
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
        $rules = parent::rules();

        // Make some fields optional for updates
        $rules['matrix_name'] = ['sometimes', 'string', 'max:255'];
        $rules['module_name'] = ['sometimes', 'string', 'in:store_orders,wastages,interco_transfers'];
        $rules['entity_type'] = ['sometimes', 'string', 'max:100'];
        $rules['approval_levels'] = ['sometimes', 'integer', 'min:1', 'max:10'];
        $rules['approval_type'] = ['sometimes', 'string', 'in:sequential,parallel'];
        $rules['basis_column'] = ['sometimes', 'string', 'max:100'];
        $rules['basis_operator'] = ['sometimes', 'string', 'in:equals,not_equals,in,not_in,greater_than,less_than,between'];
        $rules['basis_value'] = ['sometimes', 'array'];
        $rules['approvers'] = ['sometimes', 'array', 'min:1'];
        $rules['rules'] = ['sometimes', 'array'];

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'matrix_name.sometimes' => 'Matrix name may be provided.',
            'module_name.sometimes' => 'Module name may be provided.',
            'entity_type.sometimes' => 'Entity type may be provided.',
            'approval_levels.sometimes' => 'Approval levels may be provided.',
            'approval_type.sometimes' => 'Approval type may be provided.',
            'basis_column.sometimes' => 'Basis column may be provided.',
            'basis_operator.sometimes' => 'Basis operator may be provided.',
            'basis_value.sometimes' => 'Basis value may be provided.',
            'approvers.sometimes' => 'Approvers may be provided.',
            'approvers.min' => 'At least one approver is required when provided.',
            'rules.sometimes' => 'Rules may be provided.',
        ]);
    }
}