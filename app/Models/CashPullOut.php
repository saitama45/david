<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashPullOut extends Model
{
    /** @use HasFactory<\Database\Factories\CashPullOutFactory> */
    use HasFactory;

    protected $fillable = [
        'store_branch_id',
        'vendor',
        'date_needed',
        'vendor_address',
        'status',
        'remarks',
        'category'
    ];

    public function branch()
    {
        return $this->belongsTo(StoreBranch::class);
    }

    public function cash_pull_out_items()
    {
        return $this->hasMany(CashPullOutItem::class);
    }
}
