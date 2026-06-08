<?php

namespace App\Console\Commands;

use App\Models\ImportLog;
use App\Services\ImportQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReconcileImports extends Command
{
    protected $signature = 'imports:reconcile
        {--apply : Update import logs and dispatch the next pending import}
        {--stale-minutes= : Minutes before a processing log with no queue job is considered stale}';

    protected $description = 'Reconcile import logs with queue and failed job state.';

    public function handle(ImportQueueService $importQueue): int
    {
        $apply = (bool) $this->option('apply');
        $staleMinutes = is_numeric($this->option('stale-minutes'))
            ? (int) $this->option('stale-minutes')
            : null;
        $staleCutoff = $importQueue->staleProcessingCutoff($staleMinutes);
        $rows = [];

        $this->recoverIncompleteFailures($importQueue, $apply, $rows);
        $this->reconcileProcessingLogs($importQueue, $apply, $staleCutoff, $rows);

        $dispatched = null;
        if ($apply) {
            $dispatched = $importQueue->dispatchNextPending();
        }

        if ($dispatched) {
            $rows[] = [
                'id' => $dispatched->id,
                'status' => 'pending',
                'action' => 'dispatched_next',
                'detail' => Str::limit($dispatched->original_filename, 90),
            ];
        }

        if (empty($rows)) {
            $this->info('Import queue is already reconciled.');
            return self::SUCCESS;
        }

        $this->table(['id', 'status', 'action', 'detail'], $rows);

        if (!$apply) {
            $this->warn('Dry run only. Re-run with --apply to update import logs and dispatch work.');
        } else {
            $this->info('Import reconciliation completed.');
        }

        return self::SUCCESS;
    }

    private function recoverIncompleteFailures(ImportQueueService $importQueue, bool $apply, array &$rows): void
    {
        ImportLog::query()
            ->where('status', 'failed')
            ->where(function ($query) {
                $query->where('error_message', 'like', '%__PHP_Incomplete_Class_Name%')
                    ->orWhere('error_message', 'like', '%Job is incomplete class%');
            })
            ->orderBy('created_at')
            ->get()
            ->each(function (ImportLog $log) use ($importQueue, $apply, &$rows) {
                if (!$log->source_file_path || !Storage::exists($log->source_file_path)) {
                    $message = $log->source_file_path
                        ? "Source file missing: {$log->source_file_path}"
                        : 'Source file path missing.';

                    if ($apply) {
                        $log->update([
                            'error_message' => $message,
                            'failed_at' => $log->failed_at ?: now(),
                            'completed_at' => $log->completed_at ?: now(),
                        ]);
                    }

                    $rows[] = [
                        'id' => $log->id,
                        'status' => $log->status,
                        'action' => 'cannot_recover',
                        'detail' => Str::limit($message, 90),
                    ];

                    return;
                }

                if ($apply) {
                    $importQueue->deleteQueueArtifactsForImportLog($log->id);
                    $this->resetForRetry($log);
                }

                $rows[] = [
                    'id' => $log->id,
                    'status' => $log->status,
                    'action' => 'recover_incomplete_class',
                    'detail' => Str::limit($log->original_filename, 90),
                ];
            });
    }

    private function reconcileProcessingLogs(ImportQueueService $importQueue, bool $apply, Carbon $staleCutoff, array &$rows): void
    {
        ImportLog::query()
            ->where('status', 'processing')
            ->orderBy('created_at')
            ->get()
            ->each(function (ImportLog $log) use ($importQueue, $apply, $staleCutoff, &$rows) {
                $job = $importQueue->queueJobForImportLogId($log->id);

                if ($job) {
                    $rows[] = [
                        'id' => $log->id,
                        'status' => $log->status,
                        'action' => $job->reserved_at ? 'running' : 'queued',
                        'detail' => 'Queue job #' . $job->id,
                    ];

                    return;
                }

                $failedJob = $importQueue->failedJobForImportLogId($log->id);

                if ($failedJob) {
                    $message = $importQueue->summarizeException($failedJob->exception);

                    if ($apply) {
                        $this->markFailed($log, $message);
                    }

                    $rows[] = [
                        'id' => $log->id,
                        'status' => $log->status,
                        'action' => 'failed_from_queue',
                        'detail' => Str::limit($message, 90),
                    ];

                    return;
                }

                if ($this->isStaleWithoutQueueJob($log, $staleCutoff)) {
                    $message = 'Processing import has no queue job and no recent heartbeat.';

                    if ($apply) {
                        $this->markFailed($log, $message);
                    }

                    $rows[] = [
                        'id' => $log->id,
                        'status' => $log->status,
                        'action' => 'failed_stale_processing',
                        'detail' => $message,
                    ];

                    return;
                }

                $rows[] = [
                    'id' => $log->id,
                    'status' => $log->status,
                    'action' => 'waiting_for_heartbeat',
                    'detail' => 'No queue job found yet, but processing status is not stale.',
                ];
            });
    }

    private function resetForRetry(ImportLog $log): void
    {
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
    }

    private function markFailed(ImportLog $log, string $message): void
    {
        $log->update([
            'status' => 'failed',
            'error_message' => $message,
            'failed_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function isStaleWithoutQueueJob(ImportLog $log, Carbon $staleCutoff): bool
    {
        $lastSignal = $log->last_heartbeat_at
            ?: $log->processing_started_at
            ?: $log->updated_at;

        return $lastSignal && $lastSignal->lte($staleCutoff);
    }
}
