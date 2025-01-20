<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreTransactionItem extends Model
{
    /** @use HasFactory<\Database\Factories\StoreTransactionItemFactory> */
    use HasFactory;

    protected $fillable = [
        'store_transaction_id',
        'product_id',
        'base_quantity',
        'quantity',
        'price',
        'discount', // Default 0
        'line_total',
        'net_total',
        'remarks'
    ];

    public function menu()
    {
        return $this->belongsTo(Menu::class, 'product_id', 'product_id');
    }

    public function store_transaction()
    {
        return $this->belongsTo(StoreTransaction::class);
    }
}
