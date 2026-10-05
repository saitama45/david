<?php

namespace App\Http\Requests\OrderReceiving;

use App\Http\Services\MonthEndClosedPeriodService;
use App\Models\StoreOrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ReceiveOrderRequest extends FormRequest
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
            'quantity_received' => [
                'required',
                'numeric',
                'min:1',
            ],
            'received_date' => [
                'required',
                'date_format:Y-m-d\TH:i',
                'before_or_equal:' . now(),
                // Not inside a period the store closed with a final approved month end count.
                function (string $attribute, mixed $value, \Closure $fail) {
                    $branchId = StoreOrderItem::find($this->route('id'))?->store_order?->store_branch_id;

                    if ($branchId && $problem = app(MonthEndClosedPeriodService::class)->problem((int) $branchId, $value)) {
                        $fail($problem);
                    }
                },
            ],
            'remarks' => ['sometimes'],
            'expiry_date' => ['required', 'date', 'after:today']
        ];
    }
}
