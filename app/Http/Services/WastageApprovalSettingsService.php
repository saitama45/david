<?php

namespace App\Http\Services;

use App\Enums\WastageStatus;
use App\Models\Setting;
use App\Models\Wastage;

class WastageApprovalSettingsService
{
    public const REQUIRED_LEVELS_KEY = 'wastage.approval_required_levels';

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

    public function isOneLevelMode(): bool
    {
        return $this->requiredLevels() === 1;
    }

    public function guidanceDefinitions(): array
    {
        $oneLevel = $this->isOneLevelMode();

        return [
            'wastage' => [
                'rule' => $oneLevel
                    ? 'Create a wastage record with the actual quantities, reasons and required evidence. Submit it for Level 1 final approval.'
                    : 'Create a wastage record with the actual quantities, reasons and required evidence. Submit it for Level 1 review, followed by Level 2 final approval.',
            ],
            'wastage_1' => [
                'label' => $oneLevel ? 'Review wastage Level 1 (final approval)' : 'Review wastage Level 1',
                'rule' => ($oneLevel
                    ? 'Review quantities and available stock. Level 1 is the final approval under the current Wastage Settings. Approval completes this record and updates inventory.'
                    : 'Review quantities and available stock. After Level 1 approval, the record goes to Level 2 for final approval.')
                    .' If stock validation blocks approval, correct the quantity or resolve the stock discrepancy first.',
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
        ];
    }
}
