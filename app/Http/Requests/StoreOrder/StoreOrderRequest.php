<?php

namespace App\Http\Requests\StoreOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'exists:store_branches,id'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'order_date' => ['required'],
            'orders' => ['required', 'array'],
            'variant' => ['nullable']
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => 'Store field branch is required',
            'supplier_id.required' => 'Supplier field is required'
        ];
    }
}
