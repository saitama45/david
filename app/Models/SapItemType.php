<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/** A managed Item Type for SAP items. Deactivated, never deleted - items point at it. */
class SapItemType extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, BelongsToEntity;

    protected $fillable = ['entity_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function assignments(): HasMany
    {
        return $this->hasMany(SapItemTypeAssignment::class, 'sap_item_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
