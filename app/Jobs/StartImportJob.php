<?php

namespace App\Jobs;

use App\Imports\StoreTransactionImport;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class StartImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filePath;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * Create a new job instance.
     *
     * @param string $filePath
     * @return void
     */
    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $this->onQueue('imports');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            Log::info('Starting import process', [
                'file' => $this->filePath
            ]);

            if (!Storage::exists($this->filePath)) {
                throw new Exception("Import file not found: {$this->filePath}");
            }

            Excel::import(new StoreTransactionImport, $this->filePath);

            Log::info('Import completed successfully', [
                'file' => $this->filePath
            ]);
        } catch (Exception $e) {
            Log::error('Import process failed', [
                'file' => $this->filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}
