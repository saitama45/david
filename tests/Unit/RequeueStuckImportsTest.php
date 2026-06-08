<?php

use App\Console\Commands\RequeueStuckImports;
use App\Console\Commands\RecoverIncompleteImportJobs;
use App\Console\Commands\ReconcileImports;
use App\Jobs\SAPMasterfileImportJob;
use App\Jobs\StoreTransactionImportJob;
use App\Services\ImportQueueService;

function invokeImportQueueServiceMethod(string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod(ImportQueueService::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(new ImportQueueService(), ...$arguments);
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
