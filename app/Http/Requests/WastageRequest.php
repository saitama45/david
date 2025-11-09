<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WastageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if ($this->isMethod('post')) {
            // For store method
            return $this->user()->hasPermissionTo('create wastage record');
        } elseif ($this->isMethod('put') || $this->isMethod('patch')) {
            // For update method
            $wastage = $this->route('wastage');
            if ($wastage) {
                return $this->user()->hasPermissionTo('edit wastage record') ||
                       ($wastage->created_by == $this->user()->id && $wastage->wastage_status?->canBeEdited());
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
        $rules = [
            'store_branch_id' => [
                'required',
                'exists:store_branches,id',
            ],
            'wastage_reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ];

        // For multi-item submission (store method)
        if ($this->isMethod('post')) {
            $rules['cartItems'] = [
                'required',
                'array',
                'min:1',
            ];
            $rules['cartItems.*.sap_masterfile_id'] = [
                'required',
                'exists:sap_masterfiles,id',
            ];
            $rules['cartItems.*.quantity'] = [
                'required',
                'numeric',
                'min:0.01',
                'max:999999.99',
            ];
            $rules['cartItems.*.cost'] = [
                'required',
                'numeric',
                'min:0',
                'max:999999.99',
            ];
        } else {
            // For update method (PUT/PATCH) - support both multi-item and single item
            if ($this->has('items') && is_array($this->input('items'))) {
                // Multi-item update for edit method
                $rules['items'] = [
                    'required',
                    'array',
                    'min:1',
                ];
                $rules['items.*.sap_masterfile_id'] = [
                    'required',
                    'exists:sap_masterfiles,id',
                ];
                $rules['items.*.wastage_qty'] = [
                    'required',
                    'numeric',
                    'min:0.01',
                    'max:999999.99',
                ];
                $rules['items.*.cost'] = [
                    'required',
                    'numeric',
                    'min:0',
                    'max:999999.99',
                ];

                // Handle mixed scenarios: existing items (with DB IDs) and new items (without DB IDs)
                foreach ($this->input('items', []) as $index => $item) {
                    if (isset($item['id']) && is_numeric($item['id']) && $item['id'] > 0) {
                        // Check if this ID actually exists in the database before applying exists rule
                        if (\App\Models\Wastage::where('id', $item['id'])->exists()) {
                            // Existing item - validate that it exists in database
                            $rules["items.{$index}.id"] = [
                                'required',
                                'exists:wastages,id',
                            ];
                        } else {
                            // This is a new item with client-side ID (large timestamp)
                            $rules["items.{$index}.id"] = [
                                'nullable',
                                'integer',
                            ];
                        }
                    } else {
                        // New item - skip ID validation (will be created in backend)
                        $rules["items.{$index}.id"] = [
                            'nullable',
                            'integer',
                        ];
                    }
                }
            } else {
                // Single item update (backward compatibility)
                $rules['sap_masterfile_id'] = [
                    'required',
                    'exists:sap_masterfiles,id',
                ];
                $rules['wastage_qty'] = [
                    'required',
                    'integer',
                    'min:1',
                    'max:999999',
                ];
                $rules['cost'] = [
                    'required',
                    'numeric',
                    'min:0',
                    'max:999999.99',
                ];
            }
        }

        return $rules;
    }

    /**
     * Get custom validation messages.
     *
     * @return array
     */
    public function messages(): array
    {
        $messages = [
            'store_branch_id.required' => 'Please select a store branch.',
            'store_branch_id.exists' => 'Selected store branch is invalid.',

            'wastage_reason.required' => 'Please provide a reason for the wastage.',
            'wastage_reason.max' => 'Wastage reason must not exceed 1000 characters.',
        ];

        // Multi-item cart validation messages
        $messages['cartItems.required'] = 'Please add at least one item to the wastage record.';
        $messages['cartItems.array'] = 'Invalid cart data format.';
        $messages['cartItems.min'] = 'Please add at least one item to the wastage record.';

        $messages['cartItems.*.sap_masterfile_id.required'] = 'Please select a product for each item.';
        $messages['cartItems.*.sap_masterfile_id.exists'] = 'Selected product is invalid.';

        $messages['cartItems.*.quantity.required'] = 'Quantity is required for each item.';
        $messages['cartItems.*.quantity.numeric'] = 'Quantity must be a valid number.';
        $messages['cartItems.*.quantity.min'] = 'Quantity must be greater than 0.';
        $messages['cartItems.*.quantity.max'] = 'Quantity must not exceed 999,999.';

        $messages['cartItems.*.cost.required'] = 'Cost is required for each item.';
        $messages['cartItems.*.cost.numeric'] = 'Cost must be a valid number.';
        $messages['cartItems.*.cost.min'] = 'Cost must be at least 0.';
        $messages['cartItems.*.cost.max'] = 'Cost must not exceed 999,999.99.';

        // Multi-item update validation messages (for edit mode)
        $messages['items.required'] = 'Please provide at least one item to update.';
        $messages['items.array'] = 'Invalid items data format.';
        $messages['items.min'] = 'Please provide at least one item to update.';

        $messages['items.*.id.required'] = 'Item ID is required.';
        $messages['items.*.id.exists'] = 'Item does not exist.';

        $messages['items.*.sap_masterfile_id.required'] = 'Please select a product for each item.';
        $messages['items.*.sap_masterfile_id.exists'] = 'Selected product is invalid.';

        $messages['items.*.wastage_qty.required'] = 'Wastage quantity is required for each item.';
        $messages['items.*.wastage_qty.numeric'] = 'Wastage quantity must be a valid number.';
        $messages['items.*.wastage_qty.min'] = 'Wastage quantity must be greater than 0.';
        $messages['items.*.wastage_qty.max'] = 'Wastage quantity must not exceed 999,999.';

        $messages['items.*.cost.required'] = 'Cost is required for each item.';
        $messages['items.*.cost.numeric'] = 'Cost must be a valid number.';
        $messages['items.*.cost.min'] = 'Cost must be at least 0.';
        $messages['items.*.cost.max'] = 'Cost must not exceed 999,999.99.';

        // Single item validation messages (for edit mode - backward compatibility)
        $messages['sap_masterfile_id.required'] = 'Please select a product.';
        $messages['sap_masterfile_id.exists'] = 'Selected product is invalid.';

        $messages['wastage_qty.required'] = 'Wastage quantity is required.';
        $messages['wastage_qty.integer'] = 'Wastage quantity must be a whole number.';
        $messages['wastage_qty.min'] = 'Wastage quantity must be at least 1.';
        $messages['wastage_qty.max'] = 'Wastage quantity must not exceed 999,999.';

        $messages['cost.required'] = 'Cost is required.';
        $messages['cost.numeric'] = 'Cost must be a valid number.';
        $messages['cost.min'] = 'Cost must be at least 0.';
        $messages['cost.max'] = 'Cost must not exceed 999,999.99.';

        return $messages;
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
                $this->validateStoreAccess($validator);

                if ($this->isMethod('post')) {
                    $this->validateCartItems($validator);
                } else {
                    $this->validateProductIsActive($validator);
                }
            }
        });
    }

    /**
     * Validate cart items for multi-item submission
     */
    protected function validateCartItems($validator)
    {
        $cartItems = $this->input('cartItems', []);

        if (empty($cartItems)) {
            return;
        }

        // Check for duplicate items in cart
        $itemIds = array_filter(array_column($cartItems, 'sap_masterfile_id'));
        if (count($itemIds) !== count(array_unique($itemIds))) {
            $validator->errors()->add('cartItems', 'Duplicate items found in cart. Each item can only be added once.');
        }

        // Validate each cart item
        foreach ($cartItems as $index => $item) {
            if (!isset($item['sap_masterfile_id']) || !$item['sap_masterfile_id']) {
                continue;
            }

            // Check if product is active
            $product = \App\Models\SAPMasterfile::find($item['sap_masterfile_id']);
            if (!$product || !$product->is_active) {
                $validator->errors()->add("cartItems.{$index}.sap_masterfile_id",
                    'Selected product is not active or does not exist.');
            }
        }
    }

    /**
     * Validate that user has access to the selected store
     */
    protected function validateStoreAccess($validator)
    {
        $storeId = $this->input('store_branch_id');
        $user = $this->user();

        if (!$storeId || !$user) {
            return;
        }

        // Check if user has access to this store
        $hasAccess = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->where('store_branch_id', $storeId)
            ->exists();

        if (!$hasAccess) {
            $validator->errors()->add('store_branch_id',
                'You do not have permission to create wastage records for this store.');
        }
    }

    /**
     * Validate that the product is active
     */
    protected function validateProductIsActive($validator)
    {
        $productId = $this->input('sap_masterfile_id');

        if (!$productId) {
            return;
        }

        $product = \App\Models\SAPMasterfile::find($productId);

        if (!$product || !$product->is_active) {
            $validator->errors()->add('sap_masterfile_id',
                'Selected product is not active.');
        }
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes(): array
    {
        $attributes = [
            'store_branch_id' => 'store branch',
            'wastage_reason' => 'wastage reason',
            'cartItems' => 'cart items',
            'cartItems.*.sap_masterfile_id' => 'product',
            'cartItems.*.quantity' => 'quantity',
            'cartItems.*.cost' => 'cost',
        ];

        // Add multi-item update attributes for edit mode
        $attributes['items'] = 'items';
        $attributes['items.*.id'] = 'item ID';
        $attributes['items.*.sap_masterfile_id'] = 'product';
        $attributes['items.*.wastage_qty'] = 'wastage quantity';
        $attributes['items.*.cost'] = 'cost';

        // Add single item attributes for edit mode (backward compatibility)
        $attributes['sap_masterfile_id'] = 'product';
        $attributes['wastage_qty'] = 'wastage quantity';
        $attributes['cost'] = 'cost';

        return $attributes;
    }
}