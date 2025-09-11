<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Models\DTSDeliverySchedule;
use Carbon\Carbon;

class IsValidDTSOrder implements ValidationRule
{
    protected $globalOrderDate;

    public function __construct($globalOrderDate)
    {
        $this->globalOrderDate = $globalOrderDate;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $orderDate = $this->globalOrderDate;
        if (!$orderDate) {
            $fail("The order date is required.");
            return;
        }

        $orderDayOfWeek = strtoupper(Carbon::parse($orderDate)->format('l'));

        foreach ($value as $index => $item) {
            $storeBranchId = $item['store_branch_id'] ?? null;
            $variant = $item['variant'] ?? null;

            if (!$storeBranchId || !$variant) {
                // This case should ideally be caught by other validation rules, but as a fallback:
                $fail("Branch and variant are required for item #" . ($index + 1) . ".");
                return;
            }

            $validDays = DTSDeliverySchedule::where('store_branch_id', $storeBranchId)
                ->where('variant', $variant)
                ->with('deliverySchedule')
                ->get()
                ->pluck('deliverySchedule.day')
                ->unique()
                ->values();

            if ($validDays->isEmpty()) {
                $fail("No delivery schedule found for the selected branch and variant in item #" . ($index + 1) . ".");
                return;
            }

            if (!$validDays->contains($orderDayOfWeek)) {
                $fail("The order date is not a valid delivery day for item #" . ($index + 1) . ". Valid days are: " . $validDays->implode(', '));
                return;
            }
        }
    }
}
