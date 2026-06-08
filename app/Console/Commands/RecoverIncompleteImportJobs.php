<?php

namespace App\Console\Commands;

use App\Models\ImportLog;
use App\Services\ImportQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecoverIncompleteImportJobs extends Command
{
    protected $signature = 'imports:recover-incomplete-jobs {--apply : Reset recoverable logs and clean stale queue rows instead of dry-running}';

    protected $description = 'Recover import logs failed by stale incomplete queue job payloads.';

    public function handle(ImportQueueService $importQueue): int
    {
        $apply = (bool) $this->option('apply');

        $logs = ImportLog::query()
            ->where('status', 'failed')
            ->where(function ($query) {
                $query->where('error_message', 'like', '%__PHP_Incomplete_Class_Name%')
                    ->orWhere('error_message', 'like', '%Job is incomplete class%');
            })
            ->orderBy('created_at')
            ->get();

        if ($logs->isEmpty()) {
            $this->info('No incomplete-class import failures found.');
            return self::SUCCESS;
        }

        $rows = [];

        foreach ($logs as $log) {
            $action = $this->plannedAction($log);
            $deletedArtifacts = 0;

            if ($apply) {
                $deletedArtifacts = $importQueue->deleteQueueArtifactsForImportLog($log->id);

                if ($action === 'recover') {
                    $log->forceFill([
                        'status' => 'pending',
                        'error_message' => null,
                        'completed_at' => null,
                        'processed_count' => null,
                        'skipped_count' => null,
                        'skipped_file_path' => null,
                    ])->save();
                } else {
                    $log->update([
                        'error_message' => $this->missingFileReason($log),
                        'completed_at' => now(),
                    ]);
                }
            }

            $rows[] = [
                'id' => $log->id,
                'type' => $log->type,
                'filename' => Str::limit($log->original_filename, 45),
                'action' => $action,
                'queue_rows_deleted' => $deletedArtifacts,
                'detail' => Str::limit($action === 'recover' ? $log->source_file_path : $this->missingFileReason($log), 80),
            ];
        }

        if ($apply) {
            $importQueue->dispatchNextPending();
        }

        $this->table(['id', 'type', 'filename', 'action', 'queue_rows_deleted', 'detail'], $rows);

        if (!$apply) {
            $this->warn('Dry run only. Re-run with --apply to recover logs and clean stale queue artifacts.');
        } else {
            $this->info('Incomplete-class import recovery completed.');
        }

        return self::SUCCESS;
    }

    private function plannedAction(ImportLog $log): string
    {
        return $log->source_file_path && Storage::exists($log->source_file_path)
            ? 'recover'
            : 'missing_file';
    }

    private function missingFileReason(ImportLog $log): string
    {
        if (!$log->source_file_path) {
            return 'Import source file path is missing. Re-upload the file to import it.';
        }

        return "Import source file does not exist: {$log->source_file_path}. Re-upload the file to import it.";
    }
}
