<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToEntity;
use OwenIt\Auditing\Contracts\Auditable;

class OrderItemRemark extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\OrderItemRemarkFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable, BelongsToEntity;

    protected $fillable = [
        'user_id',
        'store_order_item_id',
        'action',
        'remarks'
    ];
}
