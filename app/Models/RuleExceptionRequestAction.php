<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One immutable entry in a business-rule exception request's audit trail.
 * Rows can only be inserted: updating or deleting one throws.
 */
class RuleExceptionRequestAction extends Model
{
    use BelongsToEntity;

    public const UPDATED_AT = null;

    protected $fillable = [
        'entity_id',
        'rule_exception_request_id',
        'action',
        'from_status',
        'to_status',
        'user_id',
        'remarks',
        'snapshot',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rule exception audit entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Rule exception audit entries are append-only.'));
    }

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(RuleExceptionRequest::class, 'rule_exception_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
