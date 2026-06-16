<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WIP extends Model
{
    /** @use HasFactory<\Database\Factories\WIPFactory> */
    use HasFactory, BelongsToEntity;

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


    public function menus()
    {
        return $this->hasMany(Menu::class, 'wip_id');
    }
}
