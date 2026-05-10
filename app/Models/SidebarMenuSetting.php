<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SidebarMenuSetting extends Model
{
    protected $fillable = [
        'menu_key',
        'parent_key',
        'custom_label',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Returns all settings keyed by menu_key for O(1) lookup in frontend/middleware.
     */
    public static function getSettingsMap(): array
    {
        return static::all()->keyBy('menu_key')->map(fn($s) => [
            'is_active'    => $s->is_active,
            'custom_label' => $s->custom_label,
            'sort_order'   => $s->sort_order,
            'parent_key'   => $s->parent_key,
        ])->toArray();
    }
}
