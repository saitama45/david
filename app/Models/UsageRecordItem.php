<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsageRecordItem extends Model
{
    /** @use HasFactory<\Database\Factories\UsageRecordItemFactory> */
    use HasFactory;

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
