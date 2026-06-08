<?php

use App\Console\Commands\RequeueStuckImports;
use App\Jobs\SAPMasterfileImportJob;
use App\Jobs\StoreTransactionImportJob;

function invokeRequeueCommandMethod(string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod(RequeueStuckImports::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(new RequeueStuckImports(), ...$arguments);
}

it('extracts import log ids from queued job payloads', function () {
    $serializedCommand = 'O:33:"App\\Jobs\\StoreTransactionImportJob":2:{s:14:"' . "\0*\0" . 'importLogId";i:456;}';
    $payload = json_encode([
        'data' => [
            'command' => $serializedCommand,
        ],
    ]);

    expect(invokeRequeueCommandMethod('extractImportLogId', $payload))->toBe(456);
});

it('maps supported import types to their queue jobs', function () {
    expect(invokeRequeueCommandMethod('jobClassForType', 'store_transaction'))
        ->toBe(StoreTransactionImportJob::class)
        ->and(invokeRequeueCommandMethod('jobClassForType', 'sap_masterfile'))
        ->toBe(SAPMasterfileImportJob::class)
        ->and(invokeRequeueCommandMethod('jobClassForType', 'unknown'))
        ->toBeNull();
});

it('documents the include failed option on the recovery command', function () {
    $definition = (new RequeueStuckImports())->getDefinition();

    expect($definition->hasOption('include-failed'))->toBeTrue();
});
