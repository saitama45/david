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
