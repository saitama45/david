<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WIP extends Model
{
    /** @use HasFactory<\Database\Factories\WIPFactory> */
    use HasFactory;

    protected $table = 'wips';

    protected $fillable = [
        'sap_code',
        'name',
        'remarks'
    ];

    public function wip_ingredients()
    {
        return $this->hasMany(WipIngredient::class, 'wip_id');
    }
}
