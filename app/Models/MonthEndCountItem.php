<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonthEndCountItem extends Model
{
    use HasFactory;

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