<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A support-granted extension of the upload window for one branch on one
 * month end schedule. Audited, because it overrides a business rule.
 */
class MonthEndCountReopen extends Model implements Auditable
{
    use BelongsToEntity, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'entity_id',
        'month_end_schedule_id',
        'branch_id',
        'reopened_until',
        'reopened_by',
    ];

    protected $casts = [
        'reopened_until' => 'datetime',
    ];

    public function schedule()
    {
        return $this->belongsTo(MonthEndSchedule::class, 'month_end_schedule_id');
    }

    public function branch()
    {
        return $this->belongsTo(StoreBranch::class, 'branch_id');
    }

    public function reopener()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    /**
     * Reopens that have not yet expired.
     *
     * The app timezone is UTC but the whole Month End Count flow works in
     * Asia/Manila, so the cut-off is anchored explicitly rather than via now().
     */
    public function scopeActive($query, ?\Illuminate\Support\Carbon $now = null)
    {
        return $query->where('reopened_until', '>=', ($now ?? \Illuminate\Support\Carbon::now('Asia/Manila'))->format('Y-m-d H:i:s'));
    }
}
