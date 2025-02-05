<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class StoreOrder extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\StoreOrderFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;
    // Ordering -> Store Order
    //NNSSR-00001

    protected $fillable = [
        'encoder_id',
        'supplier_id',
        'store_branch_id',
        'approver_id',
        'commiter_id', // New
        'order_number',
        'order_date',
        'order_status',
        'remarks',
        'variant',
        'approval_action_date',
        'commited_action_date', // New

    ];

    protected $casts = [
        'created_at' => 'date:F d, Y',
        // 'order_date' => 'date:F d, Y',
        'order_approved_date' => 'date:F d, Y',
        'approval_action_date' => 'date:F d, Y h:i a',
    ];

    public function encoder()
    {
        return $this->belongsTo(User::class, 'encoder_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
    public function commiter()
    {
        return $this->belongsTo(User::class, 'commiter_id');
    }

    public function store_branch()
    {
        return $this->belongsTo(StoreBranch::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function store_order_items()
    {
        return $this->hasMany(StoreOrderItem::class);
    }

    public function ordered_item_receive_dates()
    {
        return $this->hasManyThrough(OrderedItemReceiveDate::class, StoreOrderItem::class);
    }

    public function delivery_receipts()
    {
        return $this->hasMany(DeliveryReceipt::class);
    }

    public function store_order_remarks()
    {
        return $this->hasMany(StoreOrderRemark::class);
    }

    public function image_attachments()
    {
        return $this->hasMany(ImageAttachment::class);
    }
}
