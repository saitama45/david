<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use Illuminate\Support\Facades\DB; 
use Illuminate\Support\Facades\Log; // Import Log facade

class SupplierItems extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ProductInventoryFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable, BelongsToEntity;
    
    protected $table = 'supplier_items';

    protected $fillable = [
        'ItemCode',
        'item_name',
        'SupplierCode',
        'category',
        'brand',
        'classification',
        'packaging_config',
        'config',
        'uom',
        'area',
        'category2',
        'cost',
        'srp',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'cost' => 'float', // CRITICAL FIX: Explicitly cast 'cost' to float
    ];

    protected $primaryKey = 'id';
    public $incrementing = true;

    // Append the 'sap_master_file' accessor to the model's array/JSON form
    // This ensures that when SupplierItems are serialized (e.g., for Inertia),
    // the result of the getSapMasterfileAttribute() method is automatically included.
    protected $appends = ['sap_master_file'];

   // Define the options scope to return ItemCode as value and a concatenated string as label
    public function scopeOptions(Builder $query)
    {
        // Select ItemCode as the value, and a concatenated string for the label
        return $query->select(
                'ItemCode',
                DB::raw("CONCAT(item_name, ' (', ItemCode, ') ', uom) as name")
            )
            ->pluck('name', 'ItemCode'); // Pluck the 'name' (concatenated string) as value and 'ItemCode' as key
    }

    // relationship back to Supplier
    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'SupplierCode', 'supplier_code');
    }

    /**
     * A subquery holding at most ONE row per (ItemCode, SupplierCode, uom) — the
     * key every report joins this catalog on — for use with `leftJoinSub`.
     *
     * Joining `supplier_items` directly is unsafe: the key is not unique in the
     * table. Legacy rows imported before SupplierItemsImport stamped entity_id
     * sit alongside the entity-owned row for the same item, so a raw join matches
     * BOTH and fans the driving row out. Anything counted afterwards is then
     * multiplied by the number of catalog rows — a SUM of ordered quantities
     * silently reports 2x, and a detail list repeats every affected line.
     *
     * Eloquent reads never saw this (EntityScope filters to one entity), which is
     * why the same order looks correct on a model-driven screen and doubled on a
     * raw-query one.
     *
     * The surviving row is the active entity's, preferring an active item, then
     * the most recent — a deterministic pick, so a row cannot alternate between
     * page loads. With no entity context (console, queued work) the entity tie-
     * break is dropped and the newest active row wins.
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

        $ranked = DB::table('supplier_items')
            ->select([
                'id', 'ItemCode', 'SupplierCode', 'uom', 'item_name', 'category',
                'category2', 'classification', 'brand', 'area', 'packaging_config',
                'cost', 'srp', 'sort_order', 'is_active', 'entity_id',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY [ItemCode], [SupplierCode], [uom] ORDER BY ' . $tieBreak . ') as rn',
                $bindings
            );

        return DB::query()->fromSub($ranked, 'ranked_supplier_items')->where('rn', 1);
    }

    /**
     * Define a hasMany relationship to SAPMasterfile based on ItemCode.
     * This relationship will fetch ALL SAPMasterfile entries that share the same ItemCode.
     * The specific entry matching the 'uom' will be retrieved via the sap_master_file accessor.
     */
    public function sapMasterfiles()
    {
        return $this->hasMany(SAPMasterfile::class, 'ItemCode', 'ItemCode');
    }

    /**
     * Custom accessor to get the specific SAPMasterfile entry that matches
     * both the ItemCode and the SupplierItem's 'uom' (AltUOM).
     * Optimized to prevent N+1 queries by preferring loaded relationships.
     */
    public function getSapMasterfileAttribute()
    {
        if (!$this->uom) {
            return null;
        }

        $supplierItemUomUpper = strtoupper($this->uom);

        // Check if the 'sapMasterfiles' relationship has already been loaded
        if ($this->relationLoaded('sapMasterfiles')) {
            return $this->sapMasterfiles->first(function ($sapMasterfile) use ($supplierItemUomUpper) {
                return strtoupper($sapMasterfile->AltUOM) === $supplierItemUomUpper;
            });
        }

        // Fallback: perform a single optimized query
        return SAPMasterfile::where('ItemCode', $this->ItemCode)
            ->where(DB::raw('UPPER(AltUOM)'), $supplierItemUomUpper)
            ->first();
    }
}

