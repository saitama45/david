<?php

namespace App\Http\Services;

use App\Enums\WastageStatus;
use App\Models\Setting;
use App\Models\Wastage;

class WastageApprovalSettingsService
{
    public const REQUIRED_LEVELS_KEY = 'wastage.approval_required_levels';

    public const ALLOW_NEGATIVE_STOCK_KEY = 'wastage.approval_allow_negative_stock';

    /** Per entity: the stored key ends in `.entity_<id>`. */
    public const ALLOW_OUT_OF_STOCK_KEY = 'wastage.create_allow_out_of_stock';

    public function requiredLevels(): int
    {
        $levels = (int) Setting::get(self::REQUIRED_LEVELS_KEY, 2);

        return in_array($levels, [1, 2], true) ? $levels : 2;
    }

    public function setRequiredLevels(int $levels): void
    {
        if (!in_array($levels, [1, 2], true)) {
            throw new \InvalidArgumentException('Wastage approval levels must be 1 or 2.');
        }

        Setting::set(self::REQUIRED_LEVELS_KEY, $levels, 'integer');
    }

    /** Whether approving a wastage may take stock (SOH) below zero. On unless turned off in Wastage Settings. */
    public function allowsNegativeStock(): bool
    {
        return (bool) Setting::get(self::ALLOW_NEGATIVE_STOCK_KEY, true);
    }

    public function setAllowNegativeStock(bool $allow): void
    {
        Setting::set(self::ALLOW_NEGATIVE_STOCK_KEY, $allow ? 'true' : 'false', 'boolean');
    }

    /**
     * Whether a store may add an item with no stock on hand (SOH) to a wastage record. Off unless
     * switched on in Wastage Settings, and set per entity: TC switched it on while its stores were
     * not yet receiving through the system, which must not open it for every other entity.
     * Approving such a line still follows Negative Stock on Approval.
     */
    public function allowsOutOfStockItems(?int $entityId = null): bool
    {
        return (bool) Setting::get($this->outOfStockKey($entityId), false);
    }

    public function setAllowOutOfStockItems(bool $allow, ?int $entityId = null): void
    {
        Setting::set($this->outOfStockKey($entityId), $allow ? 'true' : 'false', 'boolean');
    }

    private function outOfStockKey(?int $entityId): string
    {
        $entityId ??= app(\App\Support\EntityContext::class)->id();

        return self::ALLOW_OUT_OF_STOCK_KEY . ($entityId ? ".entity_{$entityId}" : '');
    }

    public function isOneLevelMode(): bool
    {
        return $this->requiredLevels() === 1;
    }

    public function guidanceDefinitions(): array
    {
        $oneLevel = $this->isOneLevelMode();

        return [
            'wastage' => [
                'rule' => ($oneLevel
                    ? 'Create a wastage record with the actual quantities, reasons and required evidence. Submit it for Level 1 final approval.'
                    : 'Create a wastage record with the actual quantities, reasons and required evidence. Submit it for Level 1 review, followed by Level 2 final approval.')
                    .' A store with no wastage that day marks it with No Wastage for the Day instead.',
            ],
            'wastage_1' => [
                'label' => $oneLevel ? 'Review wastage Level 1 (final approval)' : 'Review wastage Level 1',
                'rule' => ($oneLevel
                    ? 'Review quantities and available stock. Level 1 is the final approval under the current Wastage Settings. Approval completes this record and updates inventory.'
                    : 'Review quantities and available stock. After Level 1 approval, the record goes to Level 2 for final approval.')
                    .($this->allowsNegativeStock()
                        ? ' Items without enough stock can still be approved after you confirm they will go to negative stock.'
                        : ' If stock validation blocks approval, correct the quantity or resolve the stock discrepancy first.'),
            ],
            'wastage_2' => [
                'rule' => $oneLevel
                    ? 'This record reached Level 2 before the approval setup changed. Complete its Level 2 final review. New and pending records now finish at Level 1.'
                    : 'Review the Level 1 approved quantities and complete Level 2 final approval.',
            ],
        ];
    }

    public function hasInFlightLevel2Records(): bool
    {
        return Wastage::where('wastage_status', WastageStatus::APPROVED_LVL1->value)->exists();
    }

    public function shouldShowLevel2(): bool
    {
        return $this->requiredLevels() === 2 || $this->hasInFlightLevel2Records();
    }

    public function sharedConfig(): array
    {
        return [
            'required_levels' => $this->requiredLevels(),
            'is_one_level_mode' => $this->isOneLevelMode(),
            'has_in_flight_level2_records' => $this->hasInFlightLevel2Records(),
            'show_level2' => $this->shouldShowLevel2(),
            'allow_negative_stock' => $this->allowsNegativeStock(),
            'allow_out_of_stock_items' => $this->allowsOutOfStockItems(),
        ];
    }
}
