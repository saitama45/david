<?php

namespace App\Http\Requests\OrderReceiving;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class AddUnlistedReceivedItemRequest extends FormRequest
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
     * The item itself is validated against the order's supplier catalogue and the SAP
     * Masterlist in OrderReceivingService::addUnlistedItem(), which is where the order is known.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'item_code' => ['required', 'string', 'max:255'],
            // An item has one row per unit in both lists, so the unit says which one was picked.
            'uom' => ['nullable', 'string', 'max:255'],
            // Only read for an item outside the order's supplier list, which has no price.
            'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'quantity_received' => ['required', 'numeric', 'min:0.01'],
            // Non-perishables are received too, so an expiry is optional here — unlike the
            // edit form for ordered lines, which always has one.
            'expiry_date' => ['nullable', 'date', 'after:today'],
            'remarks' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'remarks.required' => 'Give a reason this item was received without being ordered.',
        ];
    }
}
