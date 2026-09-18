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
     * The item itself is validated against the order's supplier catalogue in
     * OrderReceivingService::addUnlistedItem(), which is where the order is known.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'item_code' => ['required', 'string', 'max:255'],
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
