<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One store's Incident Report on one count: the transactions it still had unfinished
 * after the MEC Scheduled Date, and - once filed - why. Filed reports are final.
 */
class MonthEndCountIncidentReport extends Model implements Auditable
{
    use BelongsToEntity, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'entity_id',
        'month_end_schedule_id',
        'branch_id',
        'required_at',
        'pendings',
        'action_taken',
        'target_date',
        'filed_by',
        'filed_at',
    ];

    protected $casts = [
        'pendings' => 'array',
        'required_at' => 'datetime',
        'target_date' => 'date:Y-m-d',
        'filed_at' => 'datetime',
    ];

    public function isFiled(): bool
    {
        return $this->filed_at !== null;
    }

    /** The number printed on the report. */
    public function getNumberAttribute(): string
    {
        return 'MEC-IR-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function schedule()
    {
        return $this->belongsTo(MonthEndSchedule::class, 'month_end_schedule_id');
    }

    public function branch()
    {
        return $this->belongsTo(StoreBranch::class, 'branch_id');
    }

    public function filer()
    {
        return $this->belongsTo(User::class, 'filed_by');
    }
}
