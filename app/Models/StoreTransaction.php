<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class StoreTransaction extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\StoreTransactionFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'store_branch_id',
        'order_date',
        'posted',
        'tim_number',
        'receipt_number',
        'lot_serial', // Nullable
        'customer_id', // Nullable
        'customer', // Nullable,
        'cancel_reason', // Nullable,
        'reference_number', // Nullable
        'remarks', // Nullable
    ];

    public function store_branch()
    {
        return $this->belongsTo(StoreBranch::class);
    }

    public function store_transaction_items()
    {
        return $this->hasMany(StoreTransactionItem::class);
    }
}
