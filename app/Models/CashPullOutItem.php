<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashPullOutItem extends Model
{
    /** @use HasFactory<\Database\Factories\CashPullOutItemFactory> */
    use HasFactory;

    protected $fillable = [
        'cash_pull_out_id',
        'product_inventory_id',
        'quantity_ordered',
        'quantity_approved',
        'cost',
        'total_cost',
        'remarks',
    ];

    public function cash_pull_out()
    {
        return $this->belongsTo(CashPullOut::class);
    }

    public function product_inventory()
    {
        return $this->belongsTo(ProductInventory::class);
    }
}
