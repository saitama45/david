<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Collapses duplicate supplier_items rows and locks the catalog key unique.
 *
 * Rows imported before SupplierItemsImport stamped entity_id landed with a NULL
 * one. A later import DID carry an entity, so its MERGE could not match those
 * rows and inserted a second one for the same ItemCode/SupplierCode/uom. Every
 * report that joins the catalog raw then matched BOTH and doubled the item's
 * quantities — the same ordered qty listed twice on an order, and summed twice
 * on CS Mass Commits.
 *
 * The read side is already immune (SupplierItems::singleRowPerJoinKey), and both
 * importers now refuse to run without an entity. This removes the bad rows that
 * are still on disk and stops the shape recurring.
 *
 * The surviving row is chosen with the SAME tie-break the read-side helper uses
 * (active first, then newest), so nothing on screen changes when the loser goes.
 *
 * Runs unattended: startup.sh calls `migrate --force` on every App Service boot.
 */
return new class extends Migration
{
    private const INDEX = 'supplier_items_itemcode_suppliercode_uom_entity_id_unique';

    public function up(): void
    {
        $this->collapseDuplicates();
        $this->addUniqueKey();
    }

    private function collapseDuplicates(): void
    {
        // Listed before deleting: a raw delete bypasses the model's audit trail,
        // so the log is the only record of what went.
        $doomed = DB::select('
            SELECT [id], [ItemCode], [SupplierCode], [uom], [entity_id], [is_active]
            FROM (
                SELECT [id], [ItemCode], [SupplierCode], [uom], [entity_id], [is_active],
                       ROW_NUMBER() OVER (
                           PARTITION BY [ItemCode], [SupplierCode], [uom], [entity_id]
                           ORDER BY [is_active] DESC, [id] DESC
                       ) AS rn
                FROM [supplier_items]
            ) ranked
            WHERE rn > 1
        ');

        if (empty($doomed)) {
            return;
        }

        foreach ($doomed as $row) {
            Log::info('Removing duplicate supplier_items row', [
                'id' => $row->id,
                'ItemCode' => $row->ItemCode,
                'SupplierCode' => $row->SupplierCode,
                'uom' => $row->uom,
                'entity_id' => $row->entity_id,
                'is_active' => $row->is_active,
            ]);
        }

        DB::table('supplier_items')
            ->whereIn('id', array_column($doomed, 'id'))
            ->delete();

        Log::info('Collapsed ' . count($doomed) . ' duplicate supplier_items row(s).');
    }

    private function addUniqueKey(): void
    {
        if ($this->indexExists()) {
            return;
        }

        // The four columns total ~1538 bytes of key. That is inside SQL Server's
        // 1700-byte nonclustered limit but over the 900-byte clustered one, so on
        // an older engine this can be refused. A missing index is not worth
        // failing a boot over — the read-side dedupe and the importer guards
        // already prevent the doubling — so it is logged and skipped instead.
        try {
            Schema::table('supplier_items', function (Blueprint $table) {
                $table->unique(['ItemCode', 'SupplierCode', 'uom', 'entity_id'], self::INDEX);
            });
        } catch (\Throwable $e) {
            Log::warning('Could not create ' . self::INDEX . ' on supplier_items: ' . $e->getMessage()
                . ' Duplicates are still prevented in code, but not by the database.');
        }
    }

    private function indexExists(): bool
    {
        return DB::table('sys.indexes')
            ->where('object_id', DB::raw("OBJECT_ID('supplier_items')"))
            ->where('name', self::INDEX)
            ->exists();
    }

    public function down(): void
    {
        if ($this->indexExists()) {
            Schema::table('supplier_items', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        }

        // The collapsed rows are not restored: they were duplicates of a row that
        // is still present, and they carried no information of their own.
    }
};
