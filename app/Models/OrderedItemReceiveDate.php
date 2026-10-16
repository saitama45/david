<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToEntity;
use OwenIt\Auditing\Contracts\Auditable;

class OrderedItemReceiveDate extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\OrderedItemReceiveDateFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable, BelongsToEntity;

    protected $fillable = [
        'store_order_item_id',
        'received_by_user_id',
        'approval_action_by',
        'quantity_received',
        'received_date',
        'expiry_date',
        'remarks',
        'status',
        'confirmation_requested_at',
        'sender_decision',
        'sender_decided_by',
        'sender_decided_at',
        'sender_remarks',
    ];

    protected $casts = [
        'received_date' => 'date:F d, Y h:i a',
        'confirmation_requested_at' => 'datetime',
        'sender_decided_at' => 'datetime',
        // 'expiry_date' => 'date:F d, Y'
    ];

    /**
     * The sending store's user who confirmed or re-sent an interco receipt that differed
     * from what was sent.
     */
    public function sender_decided_by_user()
    {
        return $this->belongsTo(User::class, 'sender_decided_by');
    }

    /**
     * Defines the relationship to the User model for the user who received the item.
     * It uses the 'received_by_user_id' foreign key.
     */
    public function received_by_user()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /**
     * Defines the relationship to the User model for the user who approved the action.
     * It uses the 'approval_action_by' foreign key.
     */
    public function approval_action_by_user()
    {
        return $this->belongsTo(User::class, 'approval_action_by');
    }

    /**
     * Defines the relationship to the parent StoreOrderItem.
     */
    public function store_order_item()
    {
        return $this->belongsTo(StoreOrderItem::class);
    }
}
