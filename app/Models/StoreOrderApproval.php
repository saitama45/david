<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One level's decision on a mass order in Mass Orders Approval: who approved or rejected it,
 * at which level, and when. Read through its order, which carries the entity.
 */
class StoreOrderApproval extends Model
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'store_order_id',
        'level',
        'action',
        'user_id',
        'acted_at',
    ];

    protected $casts = [
        'level' => 'integer',
        'acted_at' => 'datetime',
    ];

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function storeOrder(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
