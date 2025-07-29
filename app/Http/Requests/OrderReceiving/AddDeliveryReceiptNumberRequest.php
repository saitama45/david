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
            'sap_so_number' => ['required', 'string', 'max:255', 'unique:delivery_receipts,sap_so_number'], // Added validation for SAP SO Number
            'store_order_id' => ['required', 'exists:store_orders,id'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:1000'] // Added nullable and max length for remarks
        ];
    }
}
