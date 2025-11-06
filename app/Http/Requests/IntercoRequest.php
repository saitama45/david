<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntercoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if ($this->isMethod('post')) {
            // For store method
            return $this->user()->hasPermissionTo('create interco requests');
        } elseif ($this->isMethod('put') || $this->isMethod('patch')) {
            // For update method
            $order = $this->route('interco');
            if ($order && $order->isInterco()) {
                return $this->user()->hasPermissionTo('edit interco requests') ||
                       ($order->encoder_id === $this->user()->id && $order->interco_status?->canBeEdited());
            }
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('put') || $this->isMethod('patch');

        return [
            'store_branch_id' => [
                'required',
                'exists:store_branches,id',
                'different:sending_store_branch_id',
            ],
            'sending_store_branch_id' => [
                'required',
                'exists:store_branches,id',
                'different:store_branch_id',
            ],
            'interco_reason' => 'required|string|max:1000',
            'transfer_date' => 'required|date',
            'remarks' => 'nullable|string|max:500',
            'items' => [
                'required',
                'array',
                'min:1',
            ],
            'items.*.id' => $isUpdate ? 'sometimes|nullable|integer|exists:store_order_items,id' : 'sometimes|integer',
            'items.*.item_code' => [
                'required',
                'exists:sap_masterfiles,ItemCode',
            ],
            'items.*.quantity_ordered' => [
                'required',
                'integer',
                'min:1',
                'max:999999',
            ],
            'items.*.cost_per_quantity' => [
                'required',
                'numeric',
                'min:0',
                'max:999999.99',
            ],
            'items.*.uom' => [
                'required',
                'string',
                'max:50',
            ],
            'items.*.remarks' => 'nullable|string|max:255',
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'store_branch_id.required' => 'Please select a receiving store.',
            'store_branch_id.exists' => 'Selected receiving store is invalid.',
            'store_branch_id.different' => 'Receiving store must be different from sending store.',

            'sending_store_branch_id.required' => 'Please select a sending store.',
            'sending_store_branch_id.exists' => 'Selected sending store is invalid.',
            'sending_store_branch_id.different' => 'Sending store must be different from receiving store.',

            'interco_reason.required' => 'Please provide a reason for the interco transfer.',
            'interco_reason.max' => 'Reason must not exceed 1000 characters.',

            'transfer_date.required' => 'Please select a transfer date.',
            'transfer_date.date' => 'Transfer date must be a valid date.',

            'remarks.max' => 'Remarks must not exceed 500 characters.',

            'items.required' => 'Please add at least one item to the transfer.',
            'items.array' => 'Items must be an array.',
            'items.min' => 'Please add at least one item to the transfer.',

            'items.*.item_code.required' => 'Item code is required for all items.',
            'items.*.item_code.exists' => 'Selected item is invalid.',

            'items.*.quantity_ordered.required' => 'Quantity is required for all items.',
            'items.*.quantity_ordered.integer' => 'Quantity must be a whole number.',
            'items.*.quantity_ordered.min' => 'Quantity must be at least 1.',
            'items.*.quantity_ordered.max' => 'Quantity must not exceed 999,999.',

            'items.*.cost_per_quantity.required' => 'Cost per quantity is required for all items.',
            'items.*.cost_per_quantity.numeric' => 'Cost per quantity must be a valid number.',
            'items.*.cost_per_quantity.min' => 'Cost per quantity must be at least 0.',
            'items.*.cost_per_quantity.max' => 'Cost per quantity must not exceed 999,999.99.',

            'items.*.uom.required' => 'Unit of measure is required for all items.',
            'items.*.uom.max' => 'Unit of measure must not exceed 50 characters.',

            'items.*.remarks.max' => 'Item remarks must not exceed 255 characters.',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->isMethod('post') || $this->isMethod('put') || $this->isMethod('patch')) {
                $this->validateStockAvailability($validator);
                $this->validateUniqueItems($validator);
                $this->validateTotalCost($validator);
            }
        });
    }

    /**
     * Validate stock availability for sending store
     */
    protected function validateStockAvailability($validator)
    {
        $items = $this->input('items', []);
        $sendingStoreId = $this->input('sending_store_branch_id');

        \Log::info('Validating stock availability for ' . count($items) . ' items, sending store: ' . $sendingStoreId);

        if (empty($items) || !$sendingStoreId) {
            \Log::warning('No items or sending store ID provided for stock validation');
            return;
        }

        foreach ($items as $index => $item) {
            $itemCode = $item['item_code'] ?? null;
            $quantity = $item['quantity_ordered'] ?? 0;

            \Log::info("Validating item {$index}: code={$itemCode}, quantity={$quantity}");

            if (!$itemCode || $quantity <= 0) {
                continue;
            }

            // Get the SAP masterfile ID from the item code first
            $sapMasterfile = \App\Models\SAPMasterfile::where('ItemCode', $itemCode)
                ->where('is_active', true)
                ->first();

            if ($sapMasterfile) {
                \Log::info("Found SAP Masterfile for {$itemCode}: " . json_encode(['id' => $sapMasterfile->id, 'description' => $sapMasterfile->ItemDescription]));
            } else {
                \Log::warning("SAP Masterfile not found for item code: {$itemCode}");
            }

            if (!$sapMasterfile) {
                $validator->errors()->add("items.{$index}.item_code",
                    "Item {$itemCode} not found in product masterfile.");
                continue;
            }

            // Check stock availability in sending store using the correct numeric ID
            $stock = \App\Models\ProductInventoryStock::where('product_inventory_id', $sapMasterfile->id)
                ->where('store_branch_id', $sendingStoreId)
                ->first();

            if (!$stock) {
                $validator->errors()->add("items.{$index}.quantity_ordered",
                    "No stock record found for item {$itemCode} in the sending store.");
            } elseif ($stock->quantity < $quantity) {
                $available = $stock->quantity;
                $validator->errors()->add("items.{$index}.quantity_ordered",
                    "Insufficient stock for item {$itemCode}. Available: {$available}, Requested: {$quantity}.");
            }
        }
    }

    /**
     * Validate that items are unique within the request
     */
    protected function validateUniqueItems($validator)
    {
        $items = $this->input('items', []);
        $itemCodes = [];

        foreach ($items as $index => $item) {
            $itemCode = $item['item_code'] ?? null;
            $itemId = $item['id'] ?? null;

            // For updates, skip checking if it's the same item being updated
            if ($itemId && isset($itemCodes[$itemCode])) {
                if ($itemCodes[$itemCode] === $itemId) {
                    continue; // Same item, skip
                }
            }

            if (isset($itemCodes[$itemCode])) {
                $validator->errors()->add("items.{$index}.item_code",
                    "This item has already been added to the transfer.");
            } else {
                $itemCodes[$itemCode] = $itemId ?? true;
            }
        }
    }

    /**
     * Validate total cost doesn't exceed reasonable limits
     */
    protected function validateTotalCost($validator)
    {
        $items = $this->input('items', []);
        $totalCost = 0;

        foreach ($items as $item) {
            $quantity = $item['quantity_ordered'] ?? 0;
            $cost = $item['cost_per_quantity'] ?? 0;
            $totalCost += $quantity * $cost;
        }

        if ($totalCost > 999999999.99) {
            $validator->errors()->add('items',
                'Total cost of the transfer exceeds the maximum allowed amount.');
        }
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes(): array
    {
        return [
            'store_branch_id' => 'receiving store',
            'sending_store_branch_id' => 'sending store',
            'interco_reason' => 'reason for transfer',
            'transfer_date' => 'transfer date',
            'remarks' => 'remarks',
            'items' => 'items',
            'items.*.item_code' => 'item',
            'items.*.quantity_ordered' => 'quantity',
            'items.*.cost_per_quantity' => 'cost per quantity',
            'items.*.uom' => 'unit of measure',
            'items.*.remarks' => 'item remarks',
        ];
    }
}