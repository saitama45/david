<?php

namespace App\Http\Requests\OrderReceiving;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

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
            'delivery_receipt_number' => ['required'],
            'store_order_id' => ['required', 'exists:store_orders,id'],
            'remarks' => ['sometimes']
        ];
    }
}
