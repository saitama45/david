<?php

namespace App\Services;

use App\Http\Services\GoLiveStoresService;
use App\Models\StoreBranch;
use App\Support\EntityContext;

/** Same ordering-based rollout definition used by the dashboard. */
class PosSalesEligibility
{
    public function startDate(array $profile): ?string
    {
        return app(EntityContext::class)->runAs((int) $profile['entity_id'], function () use ($profile) {
            $branch = StoreBranch::whereKey($profile['branch_id'])->where('is_active', true)->first();
            if (!$branch) return null;
            $dates = app(GoLiveStoresService::class)->goLiveDates([$branch->id]);
            $live = $dates[$branch->id] ?? null;
            if (!$live || $live > now()->toDateString()) return null;
            return max($live, $profile['start_date'] ?? '2026-09-14');
        });
    }
}
