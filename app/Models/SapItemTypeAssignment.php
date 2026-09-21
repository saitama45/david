<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * The Item Type of one ItemCode. Keyed by (entity_id, item_code) rather than by
 * sap_masterfiles row, so every UOM row of the code shares it.
 */
class SapItemTypeAssignment extends Model implements Auditable
{
    use BelongsToEntity, \OwenIt\Auditing\Auditable;

    protected $fillable = ['entity_id', 'item_code', 'sap_item_type_id'];

    public function type(): BelongsTo
    {
        return $this->belongsTo(SapItemType::class, 'sap_item_type_id');
    }
}
