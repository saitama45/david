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

        if (! $context->has()) {
            return;
        }

        $builder->where($this->qualifier($builder, $model).'.entity_id', $context->id());
    }

    /**
     * Qualify entity_id with the alias the model is queried under, if any
     * (e.g. `Model::from('table as alias')`); the table name otherwise.
     * Without this, an aliased query produces an unbindable identifier.
     */
    protected function qualifier(Builder $builder, Model $model): string
    {
        $from = $builder->getQuery()->from;

        if (is_string($from) && preg_match('/\s+as\s+(\S+)\s*$/i', $from, $matches)) {
            return trim($matches[1], '`"[]');
        }

        return $model->getTable();
    }
}
