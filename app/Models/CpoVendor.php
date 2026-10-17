<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * An outside vendor a store buys from through CPO Orders. Added and edited from the CPO
 * Orders form itself; never deleted.
 */
class CpoVendor extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, BelongsToEntity;

    /** The picker lists nothing until this many characters are typed. */
    public const MIN_SEARCH_CHARS = 3;

    /** The most matches the picker shows at a time. */
    public const SEARCH_LIMIT = 8;

    protected $fillable = [
        'name',
        'tin',
        'address',
        'created_by',
        'updated_by',
    ];

    public function store_orders()
    {
        return $this->hasMany(StoreOrder::class);
    }

    /**
     * Vendors whose name or TIN contains the term, best first. A term shorter than
     * MIN_SEARCH_CHARS only finds a vendor whose name is exactly that.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_SEARCH_CHARS) {
            return $query->where('name', $term);
        }

        // SQL Server LIKE has no escape character by default; brackets make a wildcard literal.
        $literal = str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $term);

        return $query
            ->where(fn ($q) => $q->where('name', 'like', "%{$literal}%")->orWhere('tin', 'like', "%{$literal}%"))
            ->orderByRaw('CASE WHEN [name] LIKE ? THEN 0 ELSE 1 END', ["{$literal}%"])
            ->orderBy('name');
    }

    public function toOption(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tin' => $this->tin,
            'address' => $this->address,
        ];
    }
}
