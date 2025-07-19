<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreUserRequest extends FormRequest
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
            'first_name' => ['required'],
            'middle_name' => ['nullable'],
            'last_name' => ['required'],
            // Updated regex for phone number format: 0912 345 6789
            'phone_number' => ['required', 'regex:/^09\d{2} \d{3} \d{4}$/'],
            'password' => ['required', 'string', 'min:8'],
            'email' => ['required', 'unique:users,email'],
            'roles' => ['required'],
            'remarks' => ['nullable'],
            'assignedBranches' => ['required', 'array'],
            'assignedSuppliers' => ['nullable', 'array'],
            'assignedSuppliers.*' => ['exists:suppliers,id'], // Validate each ID exists in the suppliers table
        ];
    }
}
