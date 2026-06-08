<?php

namespace App\Console\Commands;

use App\Models\ImportLog;
use App\Services\ImportQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RequeueStuckImports extends Command
{
    protected $signature = 'imports:requeue-stuck
        {--apply : Dispatch jobs and update failed logs instead of dry-running}
        {--include-failed : Also inspect failed logs and requeue them when their source file exists}
        {--stale-minutes=60 : Requeue processing logs only after this many minutes without an update}';

    protected $description = 'Requeue pending or stale processing import logs when their queue job is missing.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $staleMinutes = max(1, (int) $this->option('stale-minutes'));
        $importQueue = app(ImportQueueService::class);
        $queuedImportLogIds = $importQueue->queuedImportLogIds();
        $logs = $this->stuckImportLogs($staleMinutes, (bool) $this->option('include-failed'));

        if ($logs->isEmpty()) {
            $this->info('No pending or stale processing import logs found.');
            return self::SUCCESS;
        }

        $rows = [];

        foreach ($logs as $log) {
            $plan = $this->planForLog($log, $queuedImportLogIds, $importQueue);
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

        if ($apply) {
            $importQueue->dispatchNextPending();
        }

        $this->table(['id', 'type', 'status', 'filename', 'action', 'detail'], $rows);

        if (!$apply) {
            $this->warn('Dry run only. Re-run with --apply to dispatch jobs or mark missing files as failed.');
        } else {
            $this->info('Stuck import recovery completed.');
        }

        return self::SUCCESS;
    }

    private function stuckImportLogs(int $staleMinutes, bool $includeFailed): Collection
    {
        $staleBefore = Carbon::now()->subMinutes($staleMinutes);

        return ImportLog::query()
            ->where(function ($query) use ($staleBefore, $includeFailed) {
                $query->where('status', 'pending')
                    ->orWhere(function ($query) use ($staleBefore) {
                        $query->where('status', 'processing')
                            ->where('updated_at', '<=', $staleBefore);
                    });

                if ($includeFailed) {
                    $query->orWhere('status', 'failed');
                }
            })
            ->orderBy('created_at')
            ->get();
    }

    private function planForLog(ImportLog $log, Collection $queuedImportLogIds, ImportQueueService $importQueue): array
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

        $jobClass = $importQueue->jobClassForType($log->type);

        if (!$jobClass) {
            return [
                'action' => 'fail',
                'detail' => "Unsupported import type: {$log->type}",
            ];
        }

        return [
            'action' => 'requeue',
            'detail' => $log->source_file_path,
        ];
    }

    private function applyPlan(ImportLog $log, array $plan): void
    {
        if ($plan['action'] === 'requeue') {
            $log->forceFill([
                'status' => 'pending',
                'error_message' => null,
                'processing_started_at' => null,
                'last_heartbeat_at' => null,
                'failed_at' => null,
                'completed_at' => null,
                'processed_count' => null,
                'skipped_count' => null,
                'skipped_file_path' => null,
            ])->save();
            return;
        }

        if ($plan['action'] === 'fail') {
            $log->update([
                'status' => 'failed',
                'error_message' => $plan['detail'],
                'failed_at' => now(),
                'completed_at' => now(),
            ]);
        }
    }

    private function queuedImportLogIds(): Collection
    {
        return app(ImportQueueService::class)->queuedImportLogIds();
    }

    private function extractImportLogId(string $payload): ?int
    {
        return app(ImportQueueService::class)->extractImportLogId($payload);
    }
}
