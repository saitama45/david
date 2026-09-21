<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;
use Illuminate\Database\Eloquent\Builder; // Import Builder for scopeOptions

class SAPMasterfile extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable, BelongsToEntity;
    
    protected $table = 'sap_masterfiles';

    protected $fillable = [
        'ItemCode',
        'ItemDescription',
        'AltQty',
        'BaseQty',
        'AltUOM',
        'BaseUOM',
        'is_active'
    ];

    protected $casts = [
        'AltQty' => 'decimal:4', // Cast to decimal with 4 decimal places
        'BaseQty' => 'decimal:4', // Cast to decimal with 4 decimal places
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Ensure 'id' is used as the unique key for upsert
    protected $primaryKey = 'id';
    public $incrementing = true; // Assuming id is not auto-incrementing

    /**
     * Scope a query to return options for select dropdowns.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return array
     */
    public function scopeOptions(Builder $query)
    {
        // Return an array of objects with 'label' (ItemCode - ItemDescription (AltUOM)) and 'value' (id)
        // This is suitable for PrimeVue Select components.
        return $query->select(['id', 'ItemCode', 'ItemDescription', 'BaseUOM', 'AltUOM']) // Ensure AltUOM is selected
                     ->where('is_active', 1) // Only active products
                     ->get()
                     ->map(function ($item) {
                         return [
                             // Format the label as "ItemCode - ItemDescription (AltUOM)"
                             'label' => $item->ItemCode . ' - ' . $item->ItemDescription . ' (' . $item->AltUOM . ')',
                             'value' => $item->id,
                             'inventory_code' => $item->ItemCode,
                             'unit_of_measurement' => $item->BaseUOM, // Still using BaseUOM for the actual UOM field in the general context
                             'alt_unit_of_measurement' => $item->AltUOM, // Add AltUOM explicitly for the dropdown data
                         ];
                     })->all(); // Convert the collection to a plain array
    }

    /**
     * Adds sap_item_type_id and sap_item_type_name to each row.
     *
     * The type is held per ItemCode in sap_item_type_assignments, not on this
     * row, so every UOM row of a code reports the same type. Correlated
     * subqueries rather than a join: they cannot fan rows out, and they leave
     * the unqualified columns other callers filter on unambiguous.
     */
    public function scopeWithItemType(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('sap_masterfiles.*');
        }

        return $query->addSelect([
            'sap_item_type_id' => $this->itemTypeAssignment()->select('sita.sap_item_type_id'),
            'sap_item_type_name' => $this->itemTypeAssignment()
                ->join('sap_item_types as sit', 'sit.id', '=', 'sita.sap_item_type_id')
                ->select('sit.name'),
        ]);
    }

    /** Filter by Item Type: a type id, 'uncategorised', or null / 'all' for everything. */
    public function scopeWhereItemType(Builder $query, $type): Builder
    {
        if ($type === null || $type === '' || $type === 'all') {
            return $query;
        }

        if ($type === 'uncategorised') {
            return $query->whereNotExists(fn ($q) => $this->correlateItemType($q->selectRaw('1'))
                ->whereNotNull('sita.sap_item_type_id'));
        }

        return $query->whereExists(fn ($q) => $this->correlateItemType($q->selectRaw('1'))
            ->where('sita.sap_item_type_id', (int) $type));
    }

    /** This row's ItemCode assignment, correlated on the outer sap_masterfiles row. */
    private function itemTypeAssignment(): \Illuminate\Database\Query\Builder
    {
        return $this->correlateItemType(\Illuminate\Support\Facades\DB::query());
    }

    private function correlateItemType(\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder
    {
        return $query->from('sap_item_type_assignments as sita')
            ->whereColumn('sita.entity_id', 'sap_masterfiles.entity_id')
            ->whereColumn('sita.item_code', 'sap_masterfiles.ItemCode');
    }

    public function getBaseQTYAttribute()
    {
        return $this->attributes['BaseQty'];
    }

    /**
     * A subquery holding at most ONE row per (ItemCode, AltUOM) — the key reports
     * join this masterfile on — for use with `leftJoinSub`.
     *
     * The pair is not enforced unique in the table, and a repeat of it fans the
     * driving row out: every joined row is duplicated and any SUM over it is
     * multiplied. See SupplierItems::singleRowPerJoinKey() for the same guard on
     * the supplier catalog, where legacy NULL-entity rows made this concrete.
     */
    public static function singleRowPerJoinKey(?int $entityId = null): \Illuminate\Database\Query\Builder
    {
        $entityId = $entityId ?? app(\App\Support\EntityContext::class)->id();

        $tieBreak = '[is_active] DESC, [id] DESC';
        $bindings = [];

        if ($entityId !== null) {
            $tieBreak = 'CASE WHEN [entity_id] = ? THEN 0 ELSE 1 END, ' . $tieBreak;
            $bindings[] = $entityId;
        }

        $ranked = \Illuminate\Support\Facades\DB::table('sap_masterfiles')
            ->select([
                'id', 'ItemCode', 'ItemDescription', 'AltUOM', 'BaseUOM',
                'AltQty', 'BaseQty', 'is_active', 'entity_id',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY [ItemCode], [AltUOM] ORDER BY ' . $tieBreak . ') as rn',
                $bindings
            );

        return \Illuminate\Support\Facades\DB::query()->fromSub($ranked, 'ranked_sap_masterfiles')->where('rn', 1);
    }
}
