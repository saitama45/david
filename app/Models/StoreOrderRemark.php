<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class StoreOrderRemark extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\StoreOrderRemarkFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;
    protected $fillable = [
        'user_id',
        'store_order_id',
        'action',
        'remarks'
    ];

    public function store_order()
    {
        return $this->belongsTo(StoreOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
