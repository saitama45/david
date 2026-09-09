<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One keyed-in week of helpdesk ticket counts for the Success Rate dashboard.
 *
 * Only the hand-keyed ticket counts live here. Transaction volume is read live
 * from the Adoption Rate datasets, and success rate, close rate, the ticket-type
 * split and every total are derived in SuccessRateService, so stored data can
 * never drift from the workbook formulas or from actual system activity.
 */
class SuccessRateWeeklyTicket extends Model
{
    use BelongsToEntity, HasFactory;

    /**
     * Module concerns, keyed by the module slug used for the ticket columns.
     * Five of the six map onto an Adoption Rate indicator and therefore carry a
     * transaction volume; MEC has no indicator, matching the workbook, whose MEC
     * transactions column is empty for every week.
     */
    public const MODULES = [
        'order' => 'Order',
        'commit' => 'Commit',
        'receiving' => 'Receiving',
        'wastage' => 'Wastage',
        'mec' => 'MEC',
        'sales_upload' => 'Sales Upload',
    ];

    protected $fillable = [
        'entity_id',
        'week_start',
        'week_end',
        'iso_year',
        'week_no',
        'order_incoming', 'order_closed',
        'commit_incoming', 'commit_closed',
        'receiving_incoming', 'receiving_closed',
        'wastage_incoming', 'wastage_closed',
        'mec_incoming', 'mec_closed',
        'sales_upload_incoming', 'sales_upload_closed',
        'admin_incoming', 'admin_closed',
        'adoption_rate_override',
        'remarks',
        'updated_by',
    ];

    protected $casts = [
        // Date-only casts: without the explicit format these serialize as UTC
        // datetimes and render as the previous day in the Manila-timezone UI.
        'week_start' => 'date:Y-m-d',
        'week_end' => 'date:Y-m-d',
        'iso_year' => 'integer',
        'week_no' => 'integer',
        'adoption_rate_override' => 'decimal:2',
    ];

    /**
     * Every hand-keyed ticket column, in workbook column order. Transactions are
     * deliberately absent - they are derived, never entered.
     */
    public static function countColumns(): array
    {
        $columns = [];

        foreach (array_keys(self::MODULES) as $module) {
            $columns[] = $module.'_incoming';
            $columns[] = $module.'_closed';
        }

        $columns[] = 'admin_incoming';
        $columns[] = 'admin_closed';

        return $columns;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
