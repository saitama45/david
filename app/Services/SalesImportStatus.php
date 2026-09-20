<?php

namespace App\Services;

use App\Models\ImportLog;
use App\Models\StoreBranch;
use App\Models\StoreTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SalesImportStatus
{
    public function branchIds(User $user): array
    {
        return ($user->hasRole('admin') ? StoreBranch::query() : $user->store_branches())
            ->pluck('store_branches.id')->all();
    }

    public function visibleQuery(User $user): Builder
    {
        $query = ImportLog::query();
        if ($user->hasRole('admin')) {
            return $query;
        }
        $branches = $user->can('view store transactions') ? $this->branchIds($user) : [];

        return $query->where(function ($query) use ($user, $branches) {
            $query->where('user_id', $user->id)->orWhere(function ($query) use ($branches) {
                // Reports cover the entire batch: every attached branch must be authorized.
                $query->where('type', 'pos_sales')
                    ->whereHas('storeBranches')
                    ->whereDoesntHave('storeBranches', fn ($q) => $q->withoutEntityScope()->whereNotIn('store_branches.id', $branches));
            });
        });
    }

    public function describe(ImportLog $log): array
    {
        return [
            'id' => $log->id, 'type' => $log->type, 'filename' => $log->original_filename,
            'status' => $log->display_status, 'processed_count' => (int) $log->processed_count,
            'skipped_count' => (int) $log->skipped_count,
            'completed_at' => $log->completed_at?->toIso8601String(),
            'updated_at' => $log->updated_at?->toIso8601String(),
        ];
    }

    public function summary(User $user, $branchId = 'all'): array
    {
        $branches = $this->branchIds($user);
        if ($branchId !== 'all') {
            $branches = array_values(array_intersect($branches, [(int) $branchId]));
        }
        $logs = $this->visibleQuery($user)->whereIn('type', ['store_transaction', 'pos_sales'])
            ->whereHas('storeBranches', fn ($q) => $q->whereIn('store_branches.id', $branches));
        $latest = (clone $logs)->latest('id')->first();
        $successful = (clone $logs)->where('type', 'pos_sales')->where('status', 'completed')
            ->where('skipped_count', 0)->latest('completed_at')->first();
        $date = StoreTransaction::query()->whereIn('store_branch_id', $branches)->max('order_date');

        $configured = collect(config('pos_sync.profiles', []))->filter(fn ($profile) => in_array($profile['branch_id'] ?? null, $branches)
            && (int) ($profile['entity_id'] ?? 0) === (int) app(\App\Support\EntityContext::class)->id()
            && app(PosSalesEligibility::class)->startDate($profile) !== null);
        $scanner = \Illuminate\Support\Facades\Cache::get('pos-sync:scanner-heartbeat');
        return [
            'scanner_checked_at' => $scanner['at'] ?? null,
            'scanner_ok' => !empty($scanner['success']) && \Carbon\Carbon::parse($scanner['at'])->gt(now()->subSeconds(90)),
            'unresolved_receipts' => \Illuminate\Support\Facades\Schema::hasTable('pos_sync_exceptions')
                ? \Illuminate\Support\Facades\DB::table('pos_sync_exceptions')->where('entity_id', app(\App\Support\EntityContext::class)->id())
                    ->whereIn('store_branch_id', $branches)->whereNull('resolved_at')->count() : 0,
            'automation_enabled' => (bool) config('pos_sync.enabled') && $configured->isNotEmpty(),
            'last_successful_sync' => $successful?->completed_at?->toIso8601String(),
            'latest_sales_date' => $date ? substr((string) $date, 0, 10) : null,
            'latest_run' => $latest ? $this->describe($latest) : null,
        ];
    }

    public function completions(User $user): array
    {
        return ImportLog::query()->where('user_id', $user->id)->where('type', 'store_transaction')
            ->whereIn('status', ['completed', 'failed'])->where('updated_at', '>=', now()->subDay())
            ->latest('updated_at')->limit(20)->get()->map(fn ($log) => $this->describe($log))->all();
    }
}
