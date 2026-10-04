<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A Level 1 or Level 2 approver returning one branch's count on one schedule to the
 * store for re-upload. Outlives the rejected count rows, which the re-upload replaces.
 */
class MonthEndCountRejection extends Model implements Auditable
{
    use BelongsToEntity, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'entity_id',
        'month_end_schedule_id',
        'branch_id',
        'reason',
        'item_count',
        'level',
        'rejected_by',
    ];

    protected $casts = [
        'item_count' => 'integer',
        'level' => 'integer',
    ];

    /** Who returned the count, from which level, when and why - for the pages that explain it. */
    public function toNotice(): array
    {
        return [
            'reason' => $this->reason,
            'level' => $this->level ?? 1,
            'rejected_by' => $this->rejecter ? trim($this->rejecter->first_name.' '.$this->rejecter->last_name) : null,
            'rejected_at' => $this->created_at->timezone('Asia/Manila')->format('M j, Y g:i A'),
        ];
    }

    public function schedule()
    {
        return $this->belongsTo(MonthEndSchedule::class, 'month_end_schedule_id');
    }

    public function branch()
    {
        return $this->belongsTo(StoreBranch::class, 'branch_id');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
