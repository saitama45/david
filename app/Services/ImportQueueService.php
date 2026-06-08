<?php

namespace App\Services;

use App\Jobs\SAPMasterfileImportJob;
use App\Jobs\StoreTransactionImportJob;
use App\Models\ImportLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
            if ($this->hasActiveImport($ignoreActiveImportLogId)) {
                return null;
            }

            while ($log = $this->nextPendingLog()) {
                $failureReason = $this->blockingFailureReason($log);

                if ($failureReason) {
                    $log->update([
                        'status' => 'failed',
                        'error_message' => $failureReason,
                        'completed_at' => now(),
                    ]);

                    continue;
                }

                $this->jobClassForType($log->type)::dispatch($log->source_file_path, $log->id);

                return $log;
            }

            return null;
        });
    }

    public function hasActiveImport(?int $ignoreImportLogId = null): bool
    {
        return ImportLog::where('status', 'processing')->exists()
            || $this->queuedImportLogIds()
                ->when($ignoreImportLogId, fn (Collection $ids) => $ids->reject(fn (int $id) => $id === $ignoreImportLogId))
                ->isNotEmpty();
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

        return null;
    }
}
