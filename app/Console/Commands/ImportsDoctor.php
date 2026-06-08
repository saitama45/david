<?php

namespace App\Console\Commands;

use App\Models\ImportLog;
use App\Services\ImportQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportsDoctor extends Command
{
    protected $signature = 'imports:doctor';

    protected $description = 'Report import log, queue, and failed job health.';

    public function handle(ImportQueueService $importQueue): int
    {
        $this->info('Import log status counts');
        $this->table(
            ['status', 'total'],
            DB::table('import_logs')
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->orderBy('status')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all()
        );

        $this->info('Oldest pending/processing import logs');
        $this->table(
            ['id', 'type', 'filename', 'status', 'created_at', 'updated_at'],
            DB::table('import_logs')
                ->select('id', 'type', 'original_filename', 'status', 'created_at', 'updated_at')
                ->whereIn('status', ['pending', 'processing'])
                ->orderBy('created_at')
                ->limit(20)
                ->get()
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'type' => $row->type,
                    'filename' => $row->original_filename,
                    'status' => $row->status,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])
                ->all()
        );

        if (Schema::hasTable('jobs')) {
            $this->info('Queued jobs');
            $this->table(
                ['queue', 'total', 'oldest', 'newest'],
                DB::table('jobs')
                    ->select('queue', DB::raw('COUNT(*) as total'), DB::raw('MIN(created_at) as oldest'), DB::raw('MAX(created_at) as newest'))
                    ->groupBy('queue')
                    ->orderBy('queue')
                    ->get()
                    ->map(fn ($row) => (array) $row)
                    ->all()
            );
        }

        $this->info('Detailed pending/processing import health');
        $this->table(
            ['id', 'status', 'queue_state', 'job_id', 'failed_job_id', 'heartbeat', 'runtime', 'filename'],
            ImportLog::query()
                ->whereIn('status', ['pending', 'processing'])
                ->orderBy('created_at')
                ->limit(20)
                ->get()
                ->map(function (ImportLog $log) use ($importQueue) {
                    $job = $importQueue->queueJobForImportLogId($log->id);
                    $failedJob = $importQueue->failedJobForImportLogId($log->id);
                    $queueState = $importQueue->queueStateForLog($log);
                    $startedAt = $log->processing_started_at
                        ?: ($log->status === 'processing' ? $log->updated_at : null);
                    $runtime = $startedAt
                        ? $startedAt->diffForHumans(now(), true)
                        : '-';

                    return [
                        'id' => $log->id,
                        'status' => $log->status,
                        'queue_state' => $queueState['label'],
                        'job_id' => $job->id ?? '-',
                        'failed_job_id' => $failedJob->id ?? '-',
                        'heartbeat' => $log->last_heartbeat_at ?: '-',
                        'runtime' => $runtime,
                        'filename' => $log->original_filename,
                    ];
                })
                ->all()
        );

        if (Schema::hasTable('failed_jobs')) {
            $this->info('Failed jobs');
            $this->table(
                ['queue', 'total', 'latest_failed_at'],
                DB::table('failed_jobs')
                    ->select('queue', DB::raw('COUNT(*) as total'), DB::raw('MAX(failed_at) as latest_failed_at'))
                    ->groupBy('queue')
                    ->orderBy('queue')
                    ->get()
                    ->map(fn ($row) => (array) $row)
                    ->all()
            );
        }

        return self::SUCCESS;
    }
}
