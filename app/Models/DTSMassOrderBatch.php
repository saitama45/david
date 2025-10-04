<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DTSMassOrderBatch extends Model
{
    use HasFactory;

    protected $table = 'dts_mass_order_batches';

    protected $fillable = [
        'batch_number',
        'encoder_id',
        'variant',
        'date_from',
        'date_to',
        'total_orders',
        'total_quantity',
        'status',
        'remarks',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'total_quantity' => 'decimal:2',
    ];

    // Relationships
    public function encoder()
    {
        return $this->belongsTo(User::class, 'encoder_id');
    }

    public function storeOrders()
    {
        return $this->hasMany(StoreOrder::class, 'batch_reference', 'batch_number');
    }
}
