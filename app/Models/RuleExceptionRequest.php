<?php

namespace App\Models;

use App\Enums\RuleExceptionStatus;
use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A request to be excepted from a business rule or deadline.
 *
 * State changes go through RuleExceptionService only, which records each one
 * in rule_exception_request_actions. Audited as a second trail because every
 * row represents a business rule being overridden.
 */
class RuleExceptionRequest extends Model implements Auditable
{
    use BelongsToEntity, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'entity_id',
        'store_branch_id',
        'rule_key',
        'module',
        'type',
        'subject_key',
        'context',
        'reason_code',
        'justification',
        'attachment_path',
        'status',
        'requested_by',
        'requested_at',
        'decided_by',
        'decided_at',
        'decision_remarks',
        'valid_until',
        'consumed_by',
        'consumed_at',
        'consumed_ref_type',
        'consumed_ref_id',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'context' => 'array',
        'status' => RuleExceptionStatus::class,
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
        'valid_until' => 'datetime',
        'consumed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function isUnlock(): bool
    {
        return $this->type === 'unlock';
    }

    public function storeBranch(): BelongsTo
    {
        return $this->belongsTo(StoreBranch::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumed_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(RuleExceptionRequestAction::class)->orderBy('created_at')->orderBy('id');
    }
}
