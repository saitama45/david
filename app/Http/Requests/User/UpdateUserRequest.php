<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class UpdateUserRequest extends FormRequest
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
            'phone_number' => ['required', 'regex:/^09\d{9}$/'],
            'email' => ['required', 'unique:users,email,' . $this->user->id],
            'roles' => ['required', 'array'],
            'remarks' => ['nullable'],
            'assignedBranches' => ['required', 'array'],
        ];
    }
}
