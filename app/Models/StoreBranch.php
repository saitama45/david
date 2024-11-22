<?php

namespace App\Models;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreBranch extends Model
{
    /** @use HasFactory<\Database\Factories\StoreBranchFactory> */
    use HasFactory, HasSelections;

    protected $fillable = [
        'id',
        'branch_code',
        'name',
        'status'
    ];

    public function store_orders()
    {
        return $this->hasMany(StoreOrder::class);
    }
}
