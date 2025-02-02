<?php

namespace App\Http\Requests\OrderReceiving;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class AddDeliveryReceiptNumberRequest extends FormRequest
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
            'delivery_receipt_number' => ['required', 'unique:delivery_receipts,delivery_receipt_number'],
            'store_order_id' => ['required', 'exists:store_orders,id'],
            'remarks' => ['sometimes']
        ];
    }
}
