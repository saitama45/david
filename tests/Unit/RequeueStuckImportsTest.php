<?php

use App\Console\Commands\RequeueStuckImports;
use App\Console\Commands\RecoverIncompleteImportJobs;
use App\Console\Commands\ReconcileImports;
use App\Jobs\SAPMasterfileImportJob;
use App\Jobs\StoreTransactionImportJob;
use App\Models\ImportLog;
use App\Services\ImportQueueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

function invokeImportQueueServiceMethod(string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod(ImportQueueService::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(new ImportQueueService(), ...$arguments);
}

function useImportQueueInMemoryDatabase(): void
{
    config()->set('database.connections.import_queue_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    config()->set('database.default', 'import_queue_test');
    DB::purge('import_queue_test');

    Schema::create('import_logs', function ($table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('type');
        $table->string('original_filename');
        $table->string('source_file_path')->nullable();
        $table->string('status')->default('pending');
        $table->unsignedInteger('processed_count')->nullable();
        $table->unsignedInteger('skipped_count')->nullable();
        $table->string('skipped_file_path')->nullable();
        $table->text('error_message')->nullable();
        $table->timestamp('processing_started_at')->nullable();
        $table->timestamp('last_heartbeat_at')->nullable();
        $table->timestamp('failed_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamps();
    });

    Schema::create('jobs', function ($table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Schema::create('failed_jobs', function ($table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
}

function importJobPayloadForLog(int $importLogId): string
{
    $serializedCommand = 'O:33:"App\\Jobs\\StoreTransactionImportJob":2:{s:14:"' . "\0*\0" . 'importLogId";i:' . $importLogId . ';}';

    return json_encode([
        'data' => [
            'command' => $serializedCommand,
        ],
    ]);
}

function createFailedImportJobForLog(int $importLogId, string $exception = 'Illuminate\Queue\MaxAttemptsExceededException: App\Jobs\StoreTransactionImportJob has been attempted too many times.'): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'imports',
        'payload' => importJobPayloadForLog($importLogId),
        'exception' => $exception,
        'failed_at' => now(),
    ]);
}

it('extracts import log ids from queued job payloads', function () {
    $serializedCommand = 'O:33:"App\\Jobs\\StoreTransactionImportJob":2:{s:14:"' . "\0*\0" . 'importLogId";i:456;}';
    $payload = json_encode([
        'data' => [
            'command' => $serializedCommand,
        ],
    ]);

    expect(invokeImportQueueServiceMethod('extractImportLogId', $payload))->toBe(456);
});

it('maps supported import types to their queue jobs', function () {
    expect(invokeImportQueueServiceMethod('jobClassForType', 'store_transaction'))
        ->toBe(StoreTransactionImportJob::class)
        ->and(invokeImportQueueServiceMethod('jobClassForType', 'sap_masterfile'))
        ->toBe(SAPMasterfileImportJob::class)
        ->and(invokeImportQueueServiceMethod('jobClassForType', 'unknown'))
        ->toBeNull();
});

it('documents the include failed option on the recovery command', function () {
    $definition = (new RequeueStuckImports())->getDefinition();

    expect($definition->hasOption('include-failed'))->toBeTrue();
});

it('detects incomplete queue job failures as recoverable infrastructure failures', function () {
    $message = 'Exception: Job is incomplete class: {"__PHP_Incomplete_Class_Name":"App\\Jobs\\StoreTransactionImportJob"}';

    expect(invokeImportQueueServiceMethod('isIncompleteClassFailure', $message))->toBeTrue()
        ->and(invokeImportQueueServiceMethod('isIncompleteClassFailure', 'Import file not found'))->toBeFalse();
});

it('documents the apply option on the incomplete job recovery command', function () {
    $definition = (new RecoverIncompleteImportJobs())->getDefinition();

    expect($definition->hasOption('apply'))->toBeTrue();
});

it('documents the reconciler command options', function () {
    $definition = (new ReconcileImports())->getDefinition();

    expect($definition->hasOption('apply'))->toBeTrue()
        ->and($definition->hasOption('stale-minutes'))->toBeTrue();
});

it('clears stale failed job artifacts for pending logs with an existing source file', function () {
    useImportQueueInMemoryDatabase();
    config()->set('filesystems.default', 'local');
    Storage::fake('local');

    $path = 'imports/store-transactions/pending.xlsx';
    Storage::put($path, 'xlsx-content');

    $log = ImportLog::create([
        'user_id' => 1,
        'type' => 'store_transaction',
        'original_filename' => 'pending.xlsx',
        'source_file_path' => $path,
        'status' => 'pending',
    ]);
    createFailedImportJobForLog($log->id);

    $failureReason = invokeImportQueueServiceMethod('blockingFailureReason', $log->fresh());

    expect($failureReason)->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($log->fresh()->status)->toBe('pending');
});

it('does not clear failed job artifacts when the pending source file is missing', function () {
    useImportQueueInMemoryDatabase();
    config()->set('filesystems.default', 'local');
    Storage::fake('local');

    $log = ImportLog::create([
        'user_id' => 1,
        'type' => 'store_transaction',
        'original_filename' => 'missing.xlsx',
        'source_file_path' => 'imports/store-transactions/missing.xlsx',
        'status' => 'pending',
    ]);
    createFailedImportJobForLog($log->id);

    $failureReason = invokeImportQueueServiceMethod('blockingFailureReason', $log->fresh());

    expect($failureReason)->toContain('Import source file does not exist')
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('preserves an existing real import error when the queue failure hook runs', function () {
    useImportQueueInMemoryDatabase();

    $log = ImportLog::create([
        'user_id' => 1,
        'type' => 'store_transaction',
        'original_filename' => 'failed.xlsx',
        'source_file_path' => 'imports/store-transactions/failed.xlsx',
        'status' => 'processing',
        'error_message' => 'Real import error from the importer.',
    ]);

    (new StoreTransactionImportJob('imports/store-transactions/failed.xlsx', $log->id))
        ->failed(new \RuntimeException('Illuminate\Queue\MaxAttemptsExceededException: attempted too many times.'));

    expect($log->fresh()->status)->toBe('failed')
        ->and($log->fresh()->error_message)->toBe('Real import error from the importer.');
});
