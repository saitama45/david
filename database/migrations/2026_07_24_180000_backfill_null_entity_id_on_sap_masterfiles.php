<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs rows written with a NULL entity_id by the SAP masterfile importer.
 *
 * SAPMasterfileImport used a bulk upsert, which bypasses Eloquent model events —
 * so BelongsToEntity's `creating` hook never stamped entity_id. The rows landed
 * with NULL and were invisible to every entity-scoped read (the item existed in
 * the table but never appeared on /sapitems-list).
 *
 * Each orphaned row is attributed to the import that created it, by matching its
 * created_at against the processing window of the sap_masterfile ImportLog.
 * Anything left unmatched falls back to Nonos, matching the convention of the
 * Phase 4 backfill in 2026_06_15_000014_add_entity_id_to_reference_modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('sap_masterfiles')->whereNull('entity_id')->doesntExist()) {
            return;
        }

        $logs = DB::table('import_logs')
            ->where('type', 'sap_masterfile')
            ->whereNotNull('entity_id')
            ->whereNotNull('processing_started_at')
            ->orderBy('processing_started_at')
            ->get(['entity_id', 'processing_started_at', 'completed_at']);

        foreach ($logs as $log) {
            DB::table('sap_masterfiles')
                ->whereNull('entity_id')
                ->where('created_at', '>=', $log->processing_started_at)
                ->where('created_at', '<=', $log->completed_at ?? now())
                ->update(['entity_id' => $log->entity_id]);
        }

        $nonosId = DB::table('entities')->where('code', 'NONOS')->value('id');
        if ($nonosId) {
            DB::table('sap_masterfiles')
                ->whereNull('entity_id')
                ->update(['entity_id' => $nonosId]);
        }
    }

    public function down(): void
    {
        // Irreversible: the original NULLs carried no information to restore.
    }
};
