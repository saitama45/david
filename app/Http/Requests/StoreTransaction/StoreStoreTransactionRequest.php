<?php

namespace App\Http\Requests\StoreTransaction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreStoreTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'order_date' => ['required', 'date_format:Y-m-d'],
            'lot_serial' => ['nullable'],
            'posted' => ['required'],
            'tim_number' => ['required'],
            'receipt_number' => ['required'],
            'store_branch_id' => ['required'],
            'customer_id' => ['nullable'],
            'customer' => ['nullable'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'items.*.base_quantity' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['required', 'numeric'],
            'items.*.line_total' => ['required', 'numeric', 'min:0'],
            'items.*.net_total' => ['required', 'numeric', 'min:0'],
            'items.*.take_out' => ['sometimes', 'boolean'],
        ];
    }
}
