<?php

namespace App\Console\Commands;

use App\Models\ImportLog;
use App\Services\ImportQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RepairFailedImportLogs extends Command
{
    protected $signature = 'imports:repair-failed-logs {--apply : Apply updates instead of dry-running}';

    protected $description = 'Mark pending import logs as failed when their queue jobs already failed.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $repairs = $this->collectRepairs();

        if ($repairs->isEmpty()) {
            $this->info('No pending or processing import logs matched failed import jobs.');
            return self::SUCCESS;
        }

        $this->table(
            ['failed_job_id', 'import_log_id', 'status', 'source_file_path', 'error'],
            $repairs->map(fn ($repair) => [
                'failed_job_id' => $repair['failed_job_id'],
                'import_log_id' => $repair['import_log']->id,
                'status' => $repair['import_log']->status,
                'source_file_path' => $repair['file_path'] ?: $repair['import_log']->source_file_path,
                'error' => Str::limit($repair['error_message'], 120),
            ])->all()
        );

        if (!$apply) {
            $this->warn('Dry run only. Re-run with --apply to update import logs.');
            return self::SUCCESS;
        }

        foreach ($repairs as $repair) {
            $log = $repair['import_log'];
            $updates = [
                'status' => 'failed',
                'error_message' => $repair['error_message'],
                'failed_at' => now(),
                'completed_at' => now(),
            ];

            if (!$log->source_file_path && $repair['file_path']) {
                $updates['source_file_path'] = $repair['file_path'];
            }

            $log->update($updates);
        }

        $this->info("Updated {$repairs->count()} import log(s).");

        return self::SUCCESS;
    }

    private function collectRepairs()
    {
        $importQueue = app(ImportQueueService::class);

        return DB::table('failed_jobs')
            ->select('id', 'payload', 'exception')
            ->where('queue', 'imports')
            ->orderBy('id')
            ->get()
            ->map(function ($job) use ($importQueue) {
                $payload = json_decode($job->payload, true);
                $command = $payload['data']['command'] ?? '';
                $importLogId = $this->extractImportLogId($command);

                if (!$importLogId) {
                    return null;
                }

                if ($importQueue->isIncompleteClassFailure($job->exception)) {
                    return null;
                }

                $log = ImportLog::whereIn('status', ['pending', 'processing'])->find($importLogId);

                if (!$log) {
                    return null;
                }

                return [
                    'failed_job_id' => $job->id,
                    'import_log' => $log,
                    'file_path' => $this->extractFilePath($command),
                    'error_message' => $this->summarizeException($job->exception),
                ];
            })
            ->filter()
            ->values();
    }

    private function extractImportLogId(string $command): ?int
    {
        if (preg_match('/importLogId.*?i:(\d+)/s', $command, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    private function extractFilePath(string $command): ?string
    {
        if (preg_match('/filePath.*?s:\d+:"([^"]+)/s', $command, $match)) {
            return $match[1];
        }

        return null;
    }

    private function summarizeException(string $exception): string
    {
        $firstLine = trim(strtok($exception, "\n") ?: $exception);

        return $firstLine !== ''
            ? $firstLine
            : 'Queued import job failed before it could update the import log.';
    }
}
