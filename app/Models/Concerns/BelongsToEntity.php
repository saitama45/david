<?php

namespace App\Models\Concerns;

use App\Models\Entity;
use App\Models\Scopes\EntityScope;
use App\Support\EntityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adds automatic entity scoping to a model:
 *  - every query is filtered by the active entity (EntityScope)
 *  - new records are stamped with the active entity on create
 *  - withoutEntityScope() bypasses the filter for cross-entity work
 *
 * Models using this trait must have an `entity_id` column.
 */
trait BelongsToEntity
{
    public static function bootBelongsToEntity(): void
    {
        static::addGlobalScope(new EntityScope);

        static::creating(function ($model) {
            if (empty($model->entity_id)) {
                $context = app(EntityContext::class);
                if ($context->has()) {
                    $model->entity_id = $context->id();
                }
            }
        });
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function scopeWithoutEntityScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(EntityScope::class);
    }
}
