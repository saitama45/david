<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Models\DTSDeliverySchedule;
use Carbon\Carbon;

class IsValidDTSOrder implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach ($value as $index => $item) {
            $orderDate = $item['order_date'] ?? null;
            if (!$orderDate) {
                $fail("The order date for item #" . ($index + 1) . " is required.");
                return;
            }

            $orderDayOfWeek = strtoupper(Carbon::parse($orderDate)->format('l'));

            $validDays = DTSDeliverySchedule::where('store_branch_id', $item['store_branch_id'])
                ->where('variant', $item['variant'])
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
