<?php

namespace App\Jobs;

use App\Imports\StoreTransactionImport;
use App\Models\ImportLog;
use App\Services\ImportQueueService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class StoreTransactionImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
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
        $log->update(['status' => 'processing']);

        try {
            $filePath = $this->filePath ?: $log->source_file_path;

            Log::info('StoreTransaction Import: Job started.', ['file' => $filePath]);

            if (!$filePath || !Storage::exists($filePath)) {
                throw new Exception("Import file not found: {$filePath}");
            }

            $import = new StoreTransactionImport();
            DB::beginTransaction();
            Excel::import($import, Storage::path($filePath));
            DB::commit();

            $skippedRows    = $import->getSkippedRows();
            $skippedCount   = count($skippedRows);
            $processedCount = $import->getCreatedCount();
            $storeBranchIds = $import->getStoreBranchIds();

            $skippedFilePath = null;
            if ($skippedCount > 0) {
                $skippedFilePath = "import-logs/{$log->id}_skipped.csv";
                $csvLines = ["Row,Item Code,Store Code,Description,UOM,Qty,BOM Qty Deduction,Total Deduction,Variance,Current SOH,Date of Sales,Reason"];
                foreach ($skippedRows as $row) {
                    $csvLines[] = implode(',', array_map(
                        fn($v) => '"' . str_replace('"', '""', $v ?? '') . '"',
                        [
                            $row['row_number']        ?? '',
                            $row['item_code']          ?? '',
                            $row['store_code']         ?? '',
                            $row['item_description']   ?? '',
                            $row['uom']                ?? '',
                            $row['qty']                ?? '',
                            $row['bom_qty_deduction']  ?? '',
                            $row['total_deduction']    ?? '',
                            $row['variance']           ?? '',
                            $row['current_soh']        ?? '',
                            $row['date_of_sales']      ?? '',
                            $row['reason']             ?? '',
                        ]
                    ));
                }
                Storage::put($skippedFilePath, implode("\n", $csvLines));
            }

            $log->update([
                'status'            => 'completed',
                'processed_count'   => $processedCount,
                'skipped_count'     => $skippedCount,
                'skipped_file_path' => $skippedFilePath,
                'completed_at'      => now(),
            ]);
            $log->storeBranches()->sync($storeBranchIds);

            Storage::delete($filePath);

            Log::info('StoreTransaction Import: Job completed.', [
                'processed' => $processedCount,
                'skipped'   => $skippedCount,
            ]);

            app(ImportQueueService::class)->dispatchNextPending($this->importLogId);

        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);

            if (!empty($filePath ?? null)) {
                Storage::delete($filePath);
            }

            Log::error('StoreTransaction Import: Job failed.', ['error' => $e->getMessage()]);
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
            'error_message' => $e->getMessage(),
            'completed_at' => now(),
        ]);

        app(ImportQueueService::class)->dispatchNextPending($this->importLogId);
    }
}
