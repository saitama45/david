<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreOrderRemark extends Model
{
    /** @use HasFactory<\Database\Factories\StoreOrderRemarkFactory> */
    use HasFactory;
    protected $fillable = [
        'user_id',
        'order_id',
        'action',
        'remarks'
    ];

    public function store_order()
    {
        return $this->belongsTo(StoreOrder::class);
    }
}
