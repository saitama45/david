<?php

namespace App\Services;

use App\Jobs\SAPMasterfileImportJob;
use App\Jobs\StoreTransactionImportJob;
use App\Models\ImportLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ImportQueueService
{
    public function isIncompleteClassFailure(?string $message): bool
    {
        if (!$message) {
            return false;
        }

        return str_contains($message, '__PHP_Incomplete_Class_Name')
            || str_contains($message, 'Job is incomplete class');
    }

    public function deleteQueueArtifactsForImportLog(int $importLogId): int
    {
        $deleted = 0;

        if (Schema::hasTable('jobs')) {
            DB::table('jobs')
                ->where('queue', 'imports')
                ->orderBy('id')
                ->get(['id', 'payload'])
                ->each(function ($job) use ($importLogId, &$deleted) {
                    if ($this->extractImportLogId((string) $job->payload) === $importLogId) {
                        $deleted += DB::table('jobs')->where('id', $job->id)->delete();
                    }
                });
        }

        if (Schema::hasTable('failed_jobs')) {
            DB::table('failed_jobs')
                ->where('queue', 'imports')
                ->orderBy('id')
                ->get(['id', 'payload'])
                ->each(function ($job) use ($importLogId, &$deleted) {
                    if ($this->extractImportLogId((string) $job->payload) === $importLogId) {
                        $deleted += DB::table('failed_jobs')->where('id', $job->id)->delete();
                    }
                });
        }

        return $deleted;
    }

    public function dispatchNextPending(?int $ignoreActiveImportLogId = null): ?ImportLog
    {
        return Cache::lock('imports:dispatch-next', 30)->block(5, function () use ($ignoreActiveImportLogId) {
            return DB::transaction(function () use ($ignoreActiveImportLogId) {
                while ($log = $this->nextPendingLog()) {
                    if ($this->hasActiveImport($ignoreActiveImportLogId)) {
                        return null;
                    }

                    $failureReason = $this->blockingFailureReason($log);

                    if ($failureReason) {
                        $log->update([
                            'status' => 'failed',
                            'error_message' => $failureReason,
                            'failed_at' => now(),
                            'completed_at' => now(),
                        ]);

                        continue;
                    }

                    $this->jobClassForType($log->type)::dispatch($log->source_file_path, $log->id);

                    return $log;
                }

                return null;
            });
        });
    }

    public function hasActiveImport(?int $ignoreImportLogId = null): bool
    {
        return ImportLog::where('status', 'processing')->exists()
            || $this->queuedImportLogIds()
                ->when($ignoreImportLogId, fn (Collection $ids) => $ids->reject(fn (int $id) => $id === $ignoreImportLogId))
                ->isNotEmpty();
    }

    public function queueJobForImportLogId(int $importLogId): ?object
    {
        if (!Schema::hasTable('jobs')) {
            return null;
        }

        return DB::table('jobs')
            ->where('queue', 'imports')
            ->orderBy('id')
            ->get(['id', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
            ->first(fn ($job) => $this->extractImportLogId((string) $job->payload) === $importLogId);
    }

    public function failedJobForImportLogId(int $importLogId): ?object
    {
        if (!Schema::hasTable('failed_jobs')) {
            return null;
        }

        return DB::table('failed_jobs')
            ->where('queue', 'imports')
            ->orderByDesc('id')
            ->get(['id', 'payload', 'exception', 'failed_at'])
            ->first(fn ($job) => $this->extractImportLogId((string) $job->payload) === $importLogId);
    }

    public function summarizeException(?string $exception): string
    {
        $firstLine = trim(strtok($exception ?: '', "\n") ?: '');

        return $firstLine !== ''
            ? $firstLine
            : 'Import queue job failed before it could update the import log.';
    }

    public function staleProcessingCutoff(int $staleMinutes = null): \Illuminate\Support\Carbon
    {
        $retryAfterSeconds = (int) Config::get('queue.connections.database.retry_after', 3900);
        $minutes = $staleMinutes ?? (int) ceil($retryAfterSeconds / 60) + 5;

        return now()->subMinutes(max(1, $minutes));
    }

    public function queueStateForLog(ImportLog $log): array
    {
        $job = $this->queueJobForImportLogId($log->id);
        $failedJob = $this->failedJobForImportLogId($log->id);

        if ($log->status === 'completed') {
            return ['state' => 'completed', 'label' => 'Completed'];
        }

        if ($log->status === 'failed') {
            return ['state' => 'failed', 'label' => 'Failed'];
        }

        if ($job) {
            return $job->reserved_at
                ? ['state' => 'running', 'label' => 'Running']
                : ['state' => 'queued', 'label' => 'Queued'];
        }

        if ($failedJob) {
            return ['state' => 'failed_job', 'label' => 'Failed job found'];
        }

        if ($log->status === 'processing') {
            return ['state' => 'orphaned', 'label' => 'Stale - no queue job'];
        }

        return ['state' => 'waiting', 'label' => 'Waiting'];
    }

    public function queuedImportLogIds(): Collection
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

    public function jobClassForType(string $type): ?string
    {
        return match ($type) {
            'sap_masterfile' => SAPMasterfileImportJob::class,
            'store_transaction' => StoreTransactionImportJob::class,
            default => null,
        };
    }

    public function extractImportLogId(string $payload): ?int
    {
        $decoded = json_decode($payload, true);
        $command = $decoded['data']['command'] ?? $payload;

        if (preg_match('/importLogId.*?i:(\d+)/s', $command, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    private function nextPendingLog(): ?ImportLog
    {
        return ImportLog::where('status', 'pending')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->first();
    }

    private function blockingFailureReason(ImportLog $log): ?string
    {
        if (!$log->source_file_path) {
            return 'Import source file path is missing.';
        }

        if (!Storage::exists($log->source_file_path)) {
            return "Import source file does not exist: {$log->source_file_path}";
        }

        if (!$this->jobClassForType($log->type)) {
            return "Unsupported import type: {$log->type}";
        }

        $failedJob = $this->failedJobForImportLogId($log->id);

        if ($failedJob) {
            if ($this->isIncompleteClassFailure($failedJob->exception)) {
                $this->deleteQueueArtifactsForImportLog($log->id);

                return null;
            }

            return $this->summarizeException($failedJob->exception);
        }

        return null;
    }
}
