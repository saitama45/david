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

    public const INVENTORIABLE = 'INVENTORIABLE';

    public const NON_INVENTORIABLE = 'NON-INVENTORIABLE';

    /** The first is the default: of a new type, and of a report's Inventory Class filter. */
    public const INVENTORY_CLASSES = [self::INVENTORIABLE, self::NON_INVENTORIABLE];

    protected $fillable = ['entity_id', 'name', 'inventory_class', 'counts_in_totals', 'is_active'];

    protected $casts = ['counts_in_totals' => 'boolean', 'is_active' => 'boolean'];

    /** The list a report's Inventory Class filter asks for; anything else is the default list. */
    public static function inventoryClassOrDefault(?string $class): string
    {
        return in_array($class, self::INVENTORY_CLASSES, true) ? $class : self::INVENTORIABLE;
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SapItemTypeAssignment::class, 'sap_item_type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
