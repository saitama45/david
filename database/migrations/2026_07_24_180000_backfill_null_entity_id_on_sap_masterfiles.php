<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 * Anything left unmatched is only assigned when exactly one entity has ever run
 * this import; otherwise it is left NULL and logged for manual assignment.
 *
 * Runs unattended: startup.sh calls `migrate --force` on every App Service boot.
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
            ->get(['entity_id', 'processing_started_at', 'completed_at', 'failed_at', 'last_heartbeat_at', 'updated_at']);

        foreach ($logs as $log) {
            // An interrupted import has no completed_at. Falling back to now() would
            // give it an open-ended window and — since logs are applied oldest-first —
            // let it claim every later orphan, including another entity's rows.
            $windowEnd = $log->completed_at ?? $log->failed_at ?? $log->last_heartbeat_at ?? $log->updated_at;

            if ($windowEnd === null) {
                continue;
            }

            DB::table('sap_masterfiles')
                ->whereNull('entity_id')
                ->where('created_at', '>=', $log->processing_started_at)
                ->where('created_at', '<=', $windowEnd)
                ->update(['entity_id' => $log->entity_id]);
        }

        // Anything still unattributed is only safe to assign when a single entity has
        // ever imported SAP masterfiles. With more than one, guessing would file one
        // entity's items under another — worse than leaving them for a human, since a
        // still-NULL row is merely invisible and can be corrected later.
        $importEntities = DB::table('import_logs')
            ->where('type', 'sap_masterfile')
            ->whereNotNull('entity_id')
            ->distinct()
            ->pluck('entity_id');

        if ($importEntities->count() === 1) {
            DB::table('sap_masterfiles')
                ->whereNull('entity_id')
                ->update(['entity_id' => $importEntities->first()]);
        }

        $unresolved = DB::table('sap_masterfiles')->whereNull('entity_id')->count();

        if ($unresolved > 0) {
            Log::warning("Backfill left {$unresolved} sap_masterfiles row(s) with a NULL entity_id; "
                . 'they could not be attributed to a single import and need manual assignment.');
        }
    }

    public function down(): void
    {
        // Irreversible: the original NULLs carried no information to restore.
    }
};
