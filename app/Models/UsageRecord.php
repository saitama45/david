<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsageRecord extends Model
{
    /** @use HasFactory<\Database\Factories\UsageRecordFactory> */
    use HasFactory;

    protected $fillable = [
        'encoder_id',
        'store_branch_id',
        'usage_date',
    ];

    public function encoder()
    {
        return $this->belongsTo(User::class, 'encoder_id');
    }

    public function usage_record_items()
    {
        return $this->hasMany(UsageRecordItem::class);
    }

    public function branch()
    {
        return $this->belongsTo(StoreBranch::class, 'store_branch_id');
    }
}
