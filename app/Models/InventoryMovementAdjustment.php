<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * The adjustment entered against one item's Variance on the Inventory Movement Report,
 * for one store and one month, with the reason for it. It explains the variance on the
 * report; it posts no stock.
 */
class InventoryMovementAdjustment extends Model implements Auditable
{
    use BelongsToEntity, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'entity_id',
        'store_branch_id',
        'item_code',
        'year',
        'month',
        'quantity',
        'uom',
        'reason',
        'adjusted_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'quantity' => 'float',
    ];

    public function branch()
    {
        return $this->belongsTo(StoreBranch::class, 'store_branch_id');
    }

    public function adjuster()
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
