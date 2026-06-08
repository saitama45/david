<?php

namespace App\Console\Commands;

use App\Jobs\SAPMasterfileImportJob;
use App\Jobs\StoreTransactionImportJob;
use App\Models\ImportLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RequeueStuckImports extends Command
{
    protected $signature = 'imports:requeue-stuck
        {--apply : Dispatch jobs and update failed logs instead of dry-running}
        {--stale-minutes=60 : Requeue processing logs only after this many minutes without an update}';

    protected $description = 'Requeue pending or stale processing import logs when their queue job is missing.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $staleMinutes = max(1, (int) $this->option('stale-minutes'));
        $queuedImportLogIds = $this->queuedImportLogIds();
        $logs = $this->stuckImportLogs($staleMinutes);

        if ($logs->isEmpty()) {
            $this->info('No pending or stale processing import logs found.');
            return self::SUCCESS;
        }

        $rows = [];

        foreach ($logs as $log) {
            $plan = $this->planForLog($log, $queuedImportLogIds);
            $rows[] = [
                'id' => $log->id,
                'type' => $log->type,
                'status' => $log->status,
                'filename' => Str::limit($log->original_filename, 45),
                'action' => $plan['action'],
                'detail' => Str::limit($plan['detail'], 80),
            ];

            if ($apply) {
                $this->applyPlan($log, $plan);
            }
        }

        $this->table(['id', 'type', 'status', 'filename', 'action', 'detail'], $rows);

        if (!$apply) {
            $this->warn('Dry run only. Re-run with --apply to dispatch jobs or mark missing files as failed.');
        } else {
            $this->info('Stuck import recovery completed.');
        }

        return self::SUCCESS;
    }

    private function stuckImportLogs(int $staleMinutes): Collection
    {
        $staleBefore = Carbon::now()->subMinutes($staleMinutes);

        return ImportLog::query()
            ->where(function ($query) use ($staleBefore) {
                $query->where('status', 'pending')
                    ->orWhere(function ($query) use ($staleBefore) {
                        $query->where('status', 'processing')
                            ->where('updated_at', '<=', $staleBefore);
                    });
            })
            ->orderBy('created_at')
            ->get();
    }

    private function planForLog(ImportLog $log, Collection $queuedImportLogIds): array
    {
        if ($queuedImportLogIds->contains($log->id)) {
            return [
                'action' => 'already_queued',
                'detail' => 'A matching imports queue job already exists.',
            ];
        }

        if (!$log->source_file_path) {
            return [
                'action' => 'fail',
                'detail' => 'Import source file path is missing.',
            ];
        }

        if (!Storage::exists($log->source_file_path)) {
            return [
                'action' => 'fail',
                'detail' => "Import source file does not exist: {$log->source_file_path}",
            ];
        }

        $jobClass = $this->jobClassForType($log->type);

        if (!$jobClass) {
            return [
                'action' => 'fail',
                'detail' => "Unsupported import type: {$log->type}",
            ];
        }

        return [
            'action' => 'requeue',
            'detail' => $log->source_file_path,
            'job_class' => $jobClass,
        ];
    }

    private function applyPlan(ImportLog $log, array $plan): void
    {
        if ($plan['action'] === 'requeue') {
            $log->forceFill([
                'status' => 'pending',
                'error_message' => null,
                'completed_at' => null,
            ])->save();

            $plan['job_class']::dispatch($log->source_file_path, $log->id);
            return;
        }

        if ($plan['action'] === 'fail') {
            $log->update([
                'status' => 'failed',
                'error_message' => $plan['detail'],
                'completed_at' => now(),
            ]);
        }
    }

    private function jobClassForType(string $type): ?string
    {
        return match ($type) {
            'sap_masterfile' => SAPMasterfileImportJob::class,
            'store_transaction' => StoreTransactionImportJob::class,
            default => null,
        };
    }

    private function queuedImportLogIds(): Collection
    {
        if (!Schema::hasTable('jobs')) {
            return collect();
        }

        return DB::table('jobs')
            ->where('queue', 'imports')
            ->pluck('payload')
            ->map(fn ($payload) => $this->extractImportLogId((string) $payload))
            ->filter()
            ->unique()
            ->values();
    }

    private function extractImportLogId(string $payload): ?int
    {
        $decoded = json_decode($payload, true);
        $command = $decoded['data']['command'] ?? $payload;

        if (preg_match('/importLogId.*?i:(\d+)/s', $command, $match)) {
            return (int) $match[1];
        }

        return null;
    }
}
