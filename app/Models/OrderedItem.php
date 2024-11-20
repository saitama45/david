<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderedItem extends Model
{
    protected $table = 'transactiondetails';

    protected $fillable = [
        'order_id',
        'product_id',
        'quantity_ordered',
        'quantity_received',
        'remarks',
    ];

    public function order()
    {
        $this->belongsTo(Order::class, 'SONumber');
    }

    public function storeManyOrderItems(Order $order, array $items)
    {
        $itemsToInsert = collect($items)->map(function ($item) use ($order) {
            return [
                'TransactionHeaderID' => $order->id,
                'ItemCode' => $item['product_id'],
                'Cost' => $item['quantity'],
                'REC_QTY' => $item['price'],
                'PO_QTY' => now(),
                'COST' => now(),
            ];
        })->toArray();

        return OrderedItem::insert($itemsToInsert);
    }
}
