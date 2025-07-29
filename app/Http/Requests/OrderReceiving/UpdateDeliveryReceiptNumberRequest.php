<?php

namespace App\Http\Requests\OrderReceiving;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule; // Import Rule facade

class UpdateDeliveryReceiptNumberRequest extends FormRequest
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
            'id' => ['required'],
            'delivery_receipt_number' => ['required', Rule::unique('delivery_receipts')->ignore($this->id)], // Unique except for itself
            'sap_so_number' => ['required', 'string', 'max:255', Rule::unique('delivery_receipts')->ignore($this->id)], // Added validation for SAP SO Number
            'store_order_id' => ['required', 'exists:store_orders,id'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:1000'] // Added nullable and max length for remarks
        ];
    }
}
