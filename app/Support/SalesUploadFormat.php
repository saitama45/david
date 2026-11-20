<?php

namespace App\Support;

use App\Imports\StoreTransactionImport;
use App\Imports\TcSalesSummaryImport;
use App\Models\Entity;
use App\Models\User;
use App\Services\StoreTransactionReceiptProcessor;

/**
 * Which file layout the manual sales upload (/store-transactions/summary) reads, by entity.
 *
 * The TC entity uploads a daily sales summary (TcSalesSummaryImport); every other entity uploads
 * the POS receipt report (StoreTransactionImport). Both post through the shared receipt processor.
 */
class SalesUploadFormat
{
    /** Code of the entity whose manual upload is the daily sales summary. */
    public const TC_ENTITY_CODE = 'TC';

    public const DAILY_SUMMARY = 'daily_summary';

    public const POS_RECEIPTS = 'pos_receipts';

    public static function forEntity(?int $entityId): string
    {
        $code = $entityId ? Entity::whereKey($entityId)->value('code') : null;

        return strtoupper(trim((string) $code)) === self::TC_ENTITY_CODE ? self::DAILY_SUMMARY : self::POS_RECEIPTS;
    }

    public static function active(): string
    {
        return self::forEntity(app(EntityContext::class)->id());
    }

    /** The importer for the active entity's manual upload. */
    public static function importer(?User $owner, ?int $importLogId = null): StoreTransactionReceiptProcessor
    {
        return self::active() === self::DAILY_SUMMARY
            ? new TcSalesSummaryImport($owner, 'manual', null, $importLogId)
            : new StoreTransactionImport($owner, 'manual', null, $importLogId);
    }
}
