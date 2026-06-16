<?php

namespace App\Models\Scopes;

use App\Support\EntityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class EntityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(EntityContext::class);

        if ($context->has()) {
            $builder->where($model->getTable() . '.entity_id', $context->id());
        }
    }
}
