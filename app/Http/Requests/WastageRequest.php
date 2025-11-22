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
            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'image' => [
                'nullable',
                'file',
                'image',
                'mimes:jpeg,jpg,png',
                'max:5120', // 5MB max
            ],
            'image_url' => [
                'nullable',
                'string',
                'url',
            ],
        ];

        // Distinguish between create and update based on route parameter, not HTTP method
        if ($this->route('wastage')) {
            // This is an UPDATE request
            if ($this->has('items') && is_array($this->input('items'))) {
                // Multi-item update for edit method
                $rules['items'] = [
                    'required',
                    'array',
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
                $rules['items.*.reason'] = [
                    'required',
                    'string',
                    'max:255',
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
        } else {
            // This is a CREATE request
            $rules['cartItems'] = [
                'required',
                'array',
                'min:1',
            ];
            $rules['images'] = [
                'required',
                'array',
                'min:1',
            ];
            $rules['images.*'] = [
                'file',
                'image',
                'mimes:jpeg,jpg,png',
                'max:5120', // 5MB max
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
            $rules['cartItems.*.reason'] = [
                'required',
                'string',
                'max:255',
            ];
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

            'remarks.max' => 'Remarks must not exceed 1000 characters.',

            'image.file' => 'Please upload a valid file.',
            'image.image' => 'Please upload a valid image file.',
            'image.mimes' => 'Image must be a JPEG, JPG, or PNG file.',
            'image.max' => 'Image size must not exceed 5MB.',

            'image_url.url' => 'Please provide a valid URL.',
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

        $messages['cartItems.*.reason.required'] = 'Reason is required for each item.';
        $messages['cartItems.*.reason.string'] = 'Reason must be a string.';
        $messages['cartItems.*.reason.max'] = 'Reason must not exceed 255 characters.';

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

        $messages['items.*.reason.required'] = 'Reason is required for each item.';
        $messages['items.*.reason.string'] = 'Reason must be a string.';
        $messages['items.*.reason.max'] = 'Reason must not exceed 255 characters.';

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
     * Get custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes(): array
    {
        $attributes = [
            'store_branch_id' => 'store branch',
            'remarks' => 'remarks',
            'cartItems' => 'cart items',
            'cartItems.*.sap_masterfile_id' => 'product',
            'cartItems.*.quantity' => 'quantity',
            'cartItems.*.cost' => 'cost',
            'cartItems.*.reason' => 'reason',
        ];

        // Add multi-item update attributes for edit mode
        $attributes['items'] = 'items';
        $attributes['items.*.id'] = 'item ID';
        $attributes['items.*.sap_masterfile_id'] = 'product';
        $attributes['items.*.wastage_qty'] = 'wastage quantity';
        $attributes['items.*.cost'] = 'cost';
        $attributes['items.*.reason'] = 'reason';

        // Add single item attributes for edit mode (backward compatibility)
        $attributes['sap_masterfile_id'] = 'product';
        $attributes['wastage_qty'] = 'wastage quantity';
        $attributes['cost'] = 'cost';

        return $attributes;
    }
}