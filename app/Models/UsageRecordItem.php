<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsageRecordItem extends Model
{
    /** @use HasFactory<\Database\Factories\UsageRecordItemFactory> */
    use HasFactory, BelongsToEntity;

    protected $fillable = [
        'usage_record_id',
        'menu_id',
        'quantity'
    ];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function usage_record()
    {
        return $this->belongsTo(UsageRecord::class);
    }
}
