<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A banner for one module (sidebar page), written in Sidebar Management > Announcements.
 *
 * Not BelongsToEntity on purpose: entity_id null means every entity, which the entity scope
 * would hide. showingOn() filters by entity itself.
 */
class Announcement extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    public const TONES = ['info', 'warning', 'success'];

    protected $fillable = [
        'menu_key',
        'entity_id',
        'title',
        'message',
        'tone',
        'starts_on',
        'ends_on',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'starts_on' => 'date:Y-m-d',
        'ends_on' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    /** Manila wall-clock time, as the rest of the app serializes dates. */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Active announcements of a module, for every entity or this one, inside their dates on $today. */
    public function scopeShowingOn(Builder $query, string $menuKey, ?int $entityId, string $today): Builder
    {
        return $query->where('menu_key', $menuKey)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('entity_id')->when($entityId, fn ($w) => $w->orWhere('entity_id', $entityId)))
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->orderByDesc('updated_at');
    }
}
