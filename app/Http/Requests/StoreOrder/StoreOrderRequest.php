<?php

namespace App\Http\Requests\StoreOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule; // Import the Rule facade

class StoreOrderRequest extends FormRequest
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
            'branch_id' => ['required', 'exists:store_branches,id'],
            'supplier_id' => ['required', 'exists:suppliers,supplier_code'], // Correctly validates against supplier_code
            'order_date' => ['required', 'date'],
            'orders' => ['required', 'array', 'min:1'], // Ensure at least one order item
            'orders.*.id' => [ // This 'id' field from frontend holds the ItemCode
                'required',
                'string',
                // CRITICAL FIX: Validate that ItemCode exists in supplier_items
                // AND that it belongs to the selected supplier_id (which is the SupplierCode)
                Rule::exists('supplier_items', 'ItemCode')->where(function ($query) {
                    $query->where('SupplierCode', $this->supplier_id);
                }),
            ],
            'orders.*.inventory_code' => ['required', 'string', 'max:255'],
            'orders.*.name' => ['required', 'string', 'max:255'],
            'orders.*.unit_of_measurement' => ['required', 'string', 'max:255'],
            'orders.*.base_uom' => ['nullable', 'string', 'max:255'],
            'orders.*.quantity' => ['required', 'numeric', 'min:0.1'],
            'orders.*.cost' => ['required', 'numeric', 'min:0'],
            'orders.*.total_cost' => ['required', 'numeric', 'min:0'],
            'variant' => ['nullable', 'string'],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Store field branch is required',
            'supplier_id.required' => 'Supplier field is required',
            'supplier_id.exists' => 'The selected supplier does not exist.',
            'order_date.required' => 'Order date is required.',
            'orders.required' => 'At least one item must be added to the order.',
            'orders.array' => 'Order items must be in a valid format.',
            'orders.min' => 'At least one item must be added to the order.',
            'orders.*.id.required' => 'Item Code is required for each order item.',
            'orders.*.id.exists' => 'One or more selected Item Codes do not exist for the chosen supplier.', // More specific message
            'orders.*.quantity.min' => 'Quantity for an item must be at least 0.1.',
        ];
    }
}
