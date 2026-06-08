<?php

namespace App\Jobs;

use App\Imports\SAPMasterfileImport;
use App\Models\ImportLog;
use App\Services\ImportQueueService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class SAPMasterfileImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 3600;

    public function __construct(
        protected string $filePath,
        protected int $importLogId
    ) {
        $this->onConnection('database');
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $log = ImportLog::findOrFail($this->importLogId);
        $log->update([
            'status' => 'processing',
            'processing_started_at' => $log->processing_started_at ?? now(),
            'last_heartbeat_at' => now(),
            'error_message' => null,
            'failed_at' => null,
        ]);

        try {
            $filePath = $this->filePath ?: $log->source_file_path;

            Log::info('SAPMasterfile Import: Job started.', ['file' => $filePath]);

            if (!$filePath || !Storage::exists($filePath)) {
                throw new Exception("Import file not found: {$filePath}");
            }

            SAPMasterfileImport::resetSeenCombinations();
            $import = new SAPMasterfileImport();
            Excel::import($import, Storage::path($filePath));
            $log->update(['last_heartbeat_at' => now()]);

            $skippedItems = $import->getSkippedItems();
            $skippedCount = $import->getSkippedCount();
            $processedCount = $import->getProcessedCount();

            $skippedFilePath = null;
            if ($skippedCount > 0) {
                $skippedFilePath = "import-logs/{$log->id}_skipped.csv";
                $csvLines = ["Item Code,AltUOM,Description,Reason"];
                foreach ($skippedItems as $item) {
                    $csvLines[] = implode(',', array_map(
                        fn($v) => '"' . str_replace('"', '""', $v ?? '') . '"',
                        [$item['item_code'], $item['alt_uom'], $item['item_description'], $item['reason']]
                    ));
                }
                Storage::put($skippedFilePath, implode("\n", $csvLines));
            }

            $log->update([
                'status'            => 'completed',
                'processed_count'   => $processedCount,
                'skipped_count'     => $skippedCount,
                'skipped_file_path' => $skippedFilePath,
                'last_heartbeat_at' => now(),
                'completed_at'      => now(),
            ]);

            Storage::delete($filePath);

            Log::info('SAPMasterfile Import: Job completed.', [
                'processed' => $processedCount,
                'skipped'   => $skippedCount,
            ]);

            app(ImportQueueService::class)->dispatchNextPending($this->importLogId);
        } catch (Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'last_heartbeat_at' => now(),
                'failed_at'     => now(),
                'completed_at'  => now(),
            ]);

            if (!empty($filePath ?? null)) {
                Storage::delete($filePath);
            }

            Log::error('SAPMasterfile Import: Job failed.', ['error' => $e->getMessage()]);
            app(ImportQueueService::class)->dispatchNextPending($this->importLogId);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $log = ImportLog::find($this->importLogId);

        if (!$log || !in_array($log->status, ['pending', 'processing'], true)) {
            return;
        }

        $log->update([
            'status' => 'failed',
            'error_message' => $log->error_message ?: $e->getMessage(),
            'last_heartbeat_at' => now(),
            'failed_at' => now(),
            'completed_at' => now(),
        ]);

        app(ImportQueueService::class)->dispatchNextPending($this->importLogId);
    }
}
