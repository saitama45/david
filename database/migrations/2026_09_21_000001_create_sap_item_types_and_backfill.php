<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A managed Item Type for SAP items (FOOD, OPERATING SUPPLIES, ...).
 *
 * The type belongs to the ItemCode, not to a sap_masterfiles row: the masterfile
 * holds one row per AltUOM, and a per-row value drifts between siblings (the
 * BaseUOM conflicts are that failure). Assignments are therefore keyed by
 * (entity_id, item_code), so every UOM row - including one a later SAP upload
 * adds - shares one type, and the SAP upsert never touches it.
 *
 * Seeded with the vocabulary month_end_count_templates.category_2 already uses,
 * and backfilled from it. Safe to re-run: tables are created only if missing
 * and both inserts skip what is already there. Migrations have no bound entity,
 * so every entity_id is written explicitly.
 */
return new class extends Migration
{
    private const TYPES = ['FOOD', 'BEVERAGE', 'OPERATING SUPPLIES', 'RETAIL', 'CLEANING SUPPLIES', 'FOOD - OIL'];

    public function up(): void
    {
        if (! Schema::hasTable('sap_item_types')) {
            Schema::create('sap_item_types', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entity_id')->constrained('entities');
                $table->string('name', 100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['entity_id', 'name']);
            });
        }

        if (! Schema::hasTable('sap_item_type_assignments')) {
            Schema::create('sap_item_type_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('entity_id')->constrained('entities');
                $table->string('item_code');
                // NULL means "Uncategorised"; clearing a type never deletes the row.
                // No cascades: SQL Server rejects multiple cascade paths to entities.
                $table->foreignId('sap_item_type_id')->nullable()->constrained('sap_item_types');
                $table->timestamps();
                $table->unique(['entity_id', 'item_code']);
                $table->index('sap_item_type_id');
            });
        }

        $now = now();
        $values = implode(',', array_fill(0, count(self::TYPES), '(?)'));

        DB::insert("
            INSERT INTO sap_item_types (entity_id, name, is_active, created_at, updated_at)
            SELECT e.entity_id, v.name, 1, ?, ?
            FROM (SELECT DISTINCT entity_id FROM sap_masterfiles WHERE entity_id IS NOT NULL) e
            CROSS JOIN (VALUES {$values}) v(name)
            WHERE NOT EXISTS (
                SELECT 1 FROM sap_item_types t WHERE t.entity_id = e.entity_id AND t.name = v.name
            )", array_merge([$now, $now], self::TYPES));

        // One type per item code: codes whose count templates disagree are left
        // Uncategorised rather than guessed. None did when this was written.
        $assigned = DB::affectingStatement("
            INSERT INTO sap_item_type_assignments (entity_id, item_code, sap_item_type_id, created_at, updated_at)
            SELECT src.entity_id, src.item_code, t.id, ?, ?
            FROM (
                SELECT entity_id, item_code, MIN(UPPER(LTRIM(RTRIM(category_2)))) AS type_name
                FROM month_end_count_templates
                WHERE entity_id IS NOT NULL AND NULLIF(LTRIM(RTRIM(category_2)), '') IS NOT NULL
                GROUP BY entity_id, item_code
                HAVING COUNT(DISTINCT UPPER(LTRIM(RTRIM(category_2)))) = 1
            ) src
            JOIN sap_item_types t ON t.entity_id = src.entity_id AND t.name = src.type_name
            WHERE EXISTS (
                SELECT 1 FROM sap_masterfiles s WHERE s.entity_id = src.entity_id AND s.ItemCode = src.item_code
            )
            AND NOT EXISTS (
                SELECT 1 FROM sap_item_type_assignments a WHERE a.entity_id = src.entity_id AND a.item_code = src.item_code
            )", [$now, $now]);

        Log::info("sap_item_types backfill: {$assigned} item codes assigned a type from month end count templates.");
    }

    /** Discards every type and assignment, including ones set by hand since. */
    public function down(): void
    {
        Schema::dropIfExists('sap_item_type_assignments');
        Schema::dropIfExists('sap_item_types');
    }
};
