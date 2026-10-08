<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonthEndCountItem extends Model
{
    use HasFactory, BelongsToEntity;

    protected $fillable = [
        'month_end_schedule_id',
        'branch_id',
        'sap_masterfile_id',
        'item_code',
        'item_name',
        'area',
        'category2',
        'category',
        'brand',
        'packaging_config',
        'config',
        'uom',
        'current_soh',
        'bulk_qty',
        'loose_qty',
        'loose_uom',
        'remarks',
        'total_qty',
        'level1_approved_by',
        'level1_approved_at',
        'level2_approved_by',
        'level2_approved_at',
        'status',
        'created_by',
    ];

    protected $casts = [
        'bulk_qty' => 'decimal:4',
        'loose_qty' => 'decimal:4',
        'total_qty' => 'decimal:4',
        'level1_approved_at' => 'datetime',
        'level2_approved_at' => 'datetime',
    ];

    // The decimal columns, all four decimals wide.
    private const QUANTITIES = ['current_soh', 'bulk_qty', 'loose_qty', 'total_qty'];

    // Quantities go to SQL Server as fixed-point text. A PHP float below 0.0001 (1 Gm loose of
    // an 18,720 Gm Case) is otherwise sent as "5.3E-5", which SQL Server cannot read as a
    // decimal; that error rolls back the whole transaction, so the upload only ever reported
    // "Cannot roll back trans3".
    public function setAttribute($key, $value)
    {
        if (in_array($key, self::QUANTITIES, true) && is_numeric($value)) {
            $value = number_format((float) $value, 4, '.', '');
        }

        return parent::setAttribute($key, $value);
    }

    public function schedule()
    {
        return $this->belongsTo(MonthEndSchedule::class, 'month_end_schedule_id');
    }

    public function branch()
    {
        return $this->belongsTo(StoreBranch::class);
    }

    public function sapMasterfile()
    {
        return $this->belongsTo(SAPMasterfile::class, 'sap_masterfile_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function level1Approver()
    {
        return $this->belongsTo(User::class, 'level1_approved_by');
    }

    public function level2Approver()
    {
        return $this->belongsTo(User::class, 'level2_approved_by');
    }
}