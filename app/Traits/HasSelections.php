<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasSelections
{
    public function scopeOptions(Builder $query)
    {
        return $query->pluck('name', 'id');
    }
}
