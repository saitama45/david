<?php

namespace App\Http\Requests\CashPullOut;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class StoreCashPullOutRequest extends FormRequest
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
            'store_branch_id' => ['required', 'exists:store_branches,id'],
            'vendor' => ['required'],
            'date_needed' => ['required', 'date'],
            'vendor_address' => ['required'],
            'orders' => ['required', 'array']
        ];
    }
}
