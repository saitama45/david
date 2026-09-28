<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A Level 1 approver returning one branch's count on one schedule to the store
 * for re-upload. Outlives the rejected count rows, which the re-upload replaces.
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
        'rejected_by',
    ];

    protected $casts = [
        'item_count' => 'integer',
    ];

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
