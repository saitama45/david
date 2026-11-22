<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A store's "No Wastage for the Day": it had no wastage on `wastage_date`, so no wastage record may
 * be saved for that day. Unmarked (withdrawn_at / withdrawn_by) - never deleted - when made by mistake.
 */
class NoWastageDay extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, BelongsToEntity;

    protected $fillable = [
        'store_branch_id',
        'wastage_date',
        'declared_by',
        'declared_at',
        'withdrawn_at',
        'withdrawn_by',
    ];

    protected $casts = [
        'wastage_date' => 'date:Y-m-d',
        'declared_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

    /** Manila wall-clock time, as the rest of the app serializes dates. */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    /** Marks still standing: not unmarked. */
    public function scopeStanding(Builder $query): Builder
    {
        return $query->whereNull('withdrawn_at');
    }

    public function storeBranch()
    {
        return $this->belongsTo(StoreBranch::class);
    }

    public function declarer()
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    /** Who unmarked it, when it was unmarked. */
    public function withdrawer()
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }
}
