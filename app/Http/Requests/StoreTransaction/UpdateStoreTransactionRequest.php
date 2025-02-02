<?php

namespace App\Http\Requests\StoreTransaction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateStoreTransactionRequest extends FormRequest
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
            'order_date' => ['required'],
            'lot_serial' => ['nullable'],
            'posted' => ['required'],
            'tim_number' => ['required'],
            'receipt_number' => ['required'],
            'store_branch_id' => ['required'],
            'customer_id' => ['nullable'],
            'customer' => ['nullable'],
            'items' => ['required', 'array'],
        ];
    }
}
