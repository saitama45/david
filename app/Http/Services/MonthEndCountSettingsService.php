<?php

namespace App\Http\Services;

use App\Models\MonthEndCountSetting;
use App\Support\EntityContext;
use Illuminate\Support\Carbon;

/**
 * Resolves and applies the per-entity Month End Count business rules.
 *
 * All date math runs in the Asia/Manila timezone to match the rest of the
 * Month End Count flow. A "business" unit skips weekends while counting; a
 * "calendar" unit counts raw days.
 */
class MonthEndCountSettingsService
{
    public const TZ = 'Asia/Manila';

    /**
     * Effective settings for the active entity (DB row merged over defaults).
     */
    public function current(): array
    {
        $entityId = app(EntityContext::class)->id();

        return $this->forEntity($entityId);
    }

    /**
     * Effective settings for a specific entity. Falls back to defaults when no
     * row exists (or when there is no active entity).
     */
    public function forEntity(?int $entityId): array
    {
        $defaults = MonthEndCountSetting::defaults();

        if (! $entityId) {
            return $defaults;
        }

        $row = MonthEndCountSetting::where('entity_id', $entityId)->first();

        if (! $row) {
            return $defaults;
        }

        return array_merge($defaults, [
            'download_lead_days' => $row->download_lead_days,
            'download_lead_unit' => $row->download_lead_unit,
            'block_on_weekends' => $row->block_on_weekends,
            'upload_start_days' => $row->upload_start_days,
            'upload_start_unit' => $row->upload_start_unit,
            'upload_cutoff_enabled' => $row->upload_cutoff_enabled,
            'upload_cutoff_days' => $row->upload_cutoff_days,
            'upload_cutoff_unit' => $row->upload_cutoff_unit,
            'upload_cutoff_time' => $row->upload_cutoff_time
                ? Carbon::parse($row->upload_cutoff_time)->format('H:i:s')
                : $defaults['upload_cutoff_time'],
        ]);
    }

    /**
     * Add or subtract a number of days from a date, optionally skipping
     * weekends ("business" unit).
     */
    public function shiftDays(Carbon $date, int $days, string $unit, bool $forward): Carbon
    {
        $result = $date->copy();

        if ($unit === 'business') {
            $remaining = $days;
            while ($remaining > 0) {
                $forward ? $result->addDay() : $result->subDay();
                if (! $result->isWeekend()) {
                    $remaining--;
                }
            }

            return $result;
        }

        return $forward ? $result->addDays($days) : $result->subDays($days);
    }

    /**
     * First date the download is available for a schedule.
     */
    public function downloadStart(Carbon $calculatedDate, array $settings): Carbon
    {
        return $this->shiftDays(
            $calculatedDate->copy()->startOfDay(),
            (int) $settings['download_lead_days'],
            $settings['download_lead_unit'],
            false
        );
    }

    /**
     * Whether the template download is open today for the given schedule.
     */
    public function isDownloadOpen(Carbon $today, Carbon $calculatedDate, array $settings): bool
    {
        if ($settings['block_on_weekends'] && $today->isWeekend()) {
            return false;
        }

        $start = $this->downloadStart($calculatedDate, $settings)->startOfDay();
        $end = $calculatedDate->copy()->endOfDay();

        return $today->betweenIncluded($start, $end);
    }

    /**
     * First date the upload is allowed for a schedule (start of that day).
     */
    public function uploadStart(Carbon $calculatedDate, array $settings): Carbon
    {
        return $this->shiftDays(
            $calculatedDate->copy()->startOfDay(),
            (int) $settings['upload_start_days'],
            $settings['upload_start_unit'],
            true
        )->startOfDay();
    }

    /**
     * Upload cutoff datetime for a schedule, or null when disabled (open-ended).
     */
    public function uploadCutoff(Carbon $calculatedDate, array $settings): ?Carbon
    {
        if (! $settings['upload_cutoff_enabled'] || is_null($settings['upload_cutoff_days'])) {
            return null;
        }

        $date = $this->shiftDays(
            $calculatedDate->copy()->startOfDay(),
            (int) $settings['upload_cutoff_days'],
            $settings['upload_cutoff_unit'],
            true
        );

        [$h, $m, $s] = array_pad(explode(':', $settings['upload_cutoff_time']), 3, 0);

        return $date->setTime((int) $h, (int) $m, (int) $s);
    }

    /**
     * Whether uploading is currently open for the given schedule.
     */
    public function isUploadOpen(Carbon $now, Carbon $calculatedDate, array $settings): bool
    {
        if ($now->lt($this->uploadStart($calculatedDate, $settings))) {
            return false;
        }

        $cutoff = $this->uploadCutoff($calculatedDate, $settings);

        return is_null($cutoff) || $now->lte($cutoff);
    }

    /**
     * Whether a specific branch may upload right now — either the normal window
     * is open, or support granted that branch a reopen that has not expired.
     */
    public function isUploadOpenForBranch(Carbon $now, Carbon $calculatedDate, array $settings, ?Carbon $reopenedUntil): bool
    {
        if ($this->isUploadOpen($now, $calculatedDate, $settings)) {
            return true;
        }

        return $reopenedUntil !== null && $now->lte($reopenedUntil);
    }

    /**
     * Human-readable phrase for a day offset, e.g. "5 calendar days" or
     * "1 working day". Used to explain the rules on screen.
     */
    public function describeOffset(int $days, string $unit): string
    {
        $noun = $unit === 'business' ? 'working day' : 'calendar day';

        if ($days === 0) {
            return 'the same day';
        }

        return $days.' '.$noun.($days === 1 ? '' : 's');
    }

    /**
     * Plain-language summary of the upload rules, built from the entity's own
     * settings so the on-screen explanation can never drift from the rules
     * actually being enforced.
     */
    public function describeUploadRule(array $settings): string
    {
        $start = (int) $settings['upload_start_days'] === 0
            ? 'Uploading opens on the count date'
            : 'Uploading opens '.$this->describeOffset((int) $settings['upload_start_days'], $settings['upload_start_unit']).' after the count date';

        if (! $settings['upload_cutoff_enabled'] || is_null($settings['upload_cutoff_days'])) {
            return $start.' and stays open until the count is approved.';
        }

        $time = Carbon::parse($settings['upload_cutoff_time'])->format('g:i A');

        return $start.' and closes '
            .$this->describeOffset((int) $settings['upload_cutoff_days'], $settings['upload_cutoff_unit'])
            .' after it, at '.$time.'.';
    }
}
