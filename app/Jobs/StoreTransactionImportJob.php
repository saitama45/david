<?php

namespace App\Jobs;

use App\Imports\StoreTransactionImport;
use App\Models\ImportLog;
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

class StoreTransactionImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 3600;

    public function __construct(
        protected string $filePath,
        protected int $importLogId
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $log = ImportLog::findOrFail($this->importLogId);
        $log->update(['status' => 'processing']);

        try {
            Log::info('StoreTransaction Import: Job started.', ['file' => $this->filePath]);

            if (!Storage::exists($this->filePath)) {
                throw new Exception("Import file not found: {$this->filePath}");
            }

            $import = new StoreTransactionImport();
            DB::beginTransaction();
            Excel::import($import, Storage::path($this->filePath));
            DB::commit();

            $skippedRows    = $import->getSkippedRows();
            $skippedCount   = count($skippedRows);
            $processedCount = $import->getCreatedCount();

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

            Storage::delete($this->filePath);

            Log::info('StoreTransaction Import: Job completed.', [
                'processed' => $processedCount,
                'skipped'   => $skippedCount,
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);

            Storage::delete($this->filePath);

            Log::error('StoreTransaction Import: Job failed.', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
