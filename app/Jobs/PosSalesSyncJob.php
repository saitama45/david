<?php

namespace App\Jobs;

use App\Jobs\Concerns\UsesEntityContext;
use App\Models\ImportLog;
use App\Services\ImportQueueService;
use App\Services\PosSalesSource;
use App\Services\PosSalesSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PosSalesSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesEntityContext;

    public $tries = 1;
    public $timeout = 3600;

    public function __construct(protected string $filePath, protected int $importLogId)
    {
        $this->onConnection('database');
        $this->onQueue('pos-sales');
        $this->captureEntityContext();
    }

    public function handle(): void
    {
        $log = ImportLog::withoutEntityScope()->findOrFail($this->importLogId);
        $this->runWithEntityContext(function () use ($log) {
            if ($log->status === 'completed') {
                return;
            }
            $report = fopen('php://temp', 'w+');
            fputcsv($report, ['Source Receipt', 'Status', 'Reason']);
            $skipped = 0;
            $log->update(['status' => 'processing', 'processing_started_at' => $log->processing_started_at ?? now(),
                'last_heartbeat_at' => now(), 'error_message' => null, 'failed_at' => null, 'completed_at' => null]);
            try {
                $request = json_decode(Storage::get($this->filePath), true, 512, JSON_THROW_ON_ERROR);
                $sync = app(PosSalesSync::class);
                $profile = $sync->profile($request['name'], true);
                if ($profile !== $request['profile'] || (int) $profile['entity_id'] !== (int) $log->entity_id) {
                    throw new \RuntimeException('POS profile changed since queueing. Create a new sync request.');
                }
                foreach (app(PosSalesSource::class)->receipts($profile, $request['from'], $request['to'], $request['window'] ?? null) as $packet) {
                    $result = $sync->process($packet, $profile, $log->id);
                    if (in_array($result['status'], ['review', 'ineligible'], true)) {
                        $skipped++;
                    }
                    fputcsv($report, [implode('/', $packet['identity']), $result['status'], $result['reason']]);
                    $log->update(['last_heartbeat_at' => now(), 'skipped_count' => $skipped]);
                }
            } catch (Throwable $e) {
                $log->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'failed_at' => now(), 'completed_at' => now()]);
                throw $e;
            } finally {
                try {
                    rewind($report);
                    $path = "import-logs/{$log->id}_pos_sync.csv";
                    if (!Storage::put($path, $report)) {
                        throw new \RuntimeException('Could not save POS reconciliation report.');
                    }
                    $log->update(['skipped_file_path' => $path, 'last_heartbeat_at' => now(),
                        'processed_count' => DB::table('pos_sync_receipts')->where('import_log_id', $log->id)->count()]);
                    if ($log->status === 'processing') {
                        if (!empty($request['window'])) {
                            $sync->advanceCursor($profile, $request['window']['until']);
                        }
                        $log->update(['status' => 'completed', 'completed_at' => now()]);
                    }
                } catch (Throwable $e) {
                    $log->update(['status' => 'failed', 'error_message' => $e->getMessage(),
                        'failed_at' => now(), 'completed_at' => now()]);
                    throw $e;
                } finally {
                    fclose($report);
                    app(ImportQueueService::class)->dispatchNextPending($log->id);
                }
            }
        }, $log->entity_id);
    }

    public function failed(Throwable $e): void
    {
        $log = ImportLog::withoutEntityScope()->find($this->importLogId);
        if (!$log || $log->status === 'completed') {
            return;
        }
        $this->runWithEntityContext(function () use ($log, $e) {
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'failed_at' => now(),
                'completed_at' => now(), 'last_heartbeat_at' => now()]);
            app(ImportQueueService::class)->dispatchNextPending($log->id);
        }, $log->entity_id);
    }
}
