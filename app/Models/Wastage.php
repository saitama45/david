<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use App\Enums\WastageStatus;

class Wastage extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\WastageFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'store_branch_id',
        'wastage_status',
        'wastage_no',
        'sap_masterfile_id',
        'wastage_qty',
        'approverlvl1_qty',
        'approverlvl2_qty',
        'cost',
        'reason',
        'remarks',
        'image_url',
        'created_by',
        'updated_by',
        'approved_level1_by',
        'approved_level1_date',
        'approved_level2_by',
        'approved_level2_date',
        'cancelled_by',
        'cancelled_date',
    ];

    protected $casts = [
        'wastage_qty' => 'decimal:2',
        'approverlvl1_qty' => 'decimal:2',
        'approverlvl2_qty' => 'decimal:2',
        'cost' => 'decimal:2',
        'wastage_status' => WastageStatus::class,
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'approved_level1_date' => 'datetime',
        'approved_level2_date' => 'datetime',
        'cancelled_date' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($wastage) {
            if (empty($wastage->wastage_status)) {
                $wastage->wastage_status = WastageStatus::PENDING;
            }
        });
    }

    /**
     * Relationships
     */
    public function storeBranch()
    {
        return $this->belongsTo(StoreBranch::class);
    }

    public function sapMasterfile()
    {
        return $this->belongsTo(SAPMasterfile::class);
    }

    public function encoder()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approver1()
    {
        return $this->belongsTo(User::class, 'approved_level1_by');
    }

    public function approver2()
    {
        return $this->belongsTo(User::class, 'approved_level2_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Scopes
     */
    public function scopeForStore($query, $storeId)
    {
        return $query->where('store_branch_id', $storeId);
    }

    public function scopeForStores($query, array $storeIds)
    {
        return $query->whereIn('store_branch_id', $storeIds);
    }

    public function scopeWithStatus($query, $status)
    {
        if ($status instanceof WastageStatus) {
            return $query->where('wastage_status', $status);
        }
        return $query->where('wastage_status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('wastage_status', WastageStatus::PENDING);
    }

    public function scopeApprovedLevel1($query)
    {
        return $query->where('wastage_status', WastageStatus::APPROVED_LVL1);
    }

    public function scopeApprovedLevel2($query)
    {
        return $query->where('wastage_status', WastageStatus::APPROVED_LVL2);
    }

    public function scopeCancelled($query)
    {
        return $query->where('wastage_status', WastageStatus::CANCELLED);
    }

    public function scopeApproved($query)
    {
        return $query->whereIn('wastage_status', [WastageStatus::APPROVED_LVL1, WastageStatus::APPROVED_LVL2]);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [
            $startDate->startOfDay(),
            $endDate->endOfDay()
        ]);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('wastage_no', 'like', "%{$search}%")
              ->orWhere('reason', 'like', "%{$search}%")
              ->orWhere('remarks', 'like', "%{$search}%")
              ->orWhereHas('sapMasterfile', function($sq) use ($search) {
                  $sq->where('ItemCode', 'like', "%{$search}%")
                    ->orWhere('ItemDescription', 'like', "%{$search}%");
              });
        });
    }

    /**
     * Accessors
     */
    public function getFormattedWastageQtyAttribute()
    {
        return number_format($this->wastage_qty, 2);
    }

    public function getFormattedCostAttribute()
    {
        return number_format($this->cost, 2);
    }

    public function getFormattedTotalCostAttribute()
    {
        $totalCost = $this->wastage_qty * $this->cost;
        return number_format($totalCost, 2);
    }

    public function getTotalCostAttribute()
    {
        return $this->wastage_qty * $this->cost;
    }

    public function getWastageReasonAttribute()
    {
        return $this->reason;
    }

    public function setWastageReasonAttribute($value)
    {
        $this->attributes['reason'] = $value;
    }

    public function getEncoderIdAttribute()
    {
        return $this->created_by;
    }

    public function setEncoderIdAttribute($value)
    {
        $this->attributes['created_by'] = $value;
    }

    public function getEncodedDateAttribute()
    {
        return $this->created_at;
    }

    public function setEncodedDateAttribute($value)
    {
        $this->attributes['created_at'] = $value;
    }

    public function getStatusLabelAttribute()
    {
        return $this->wastage_status?->getLabel() ?? $this->wastage_status;
    }

    public function getStatusColorAttribute()
    {
        return $this->wastage_status?->getColor() ?? 'text-gray-600';
    }

    public function getStatusBackgroundColorAttribute()
    {
        return $this->wastage_status?->getBackgroundColor() ?? 'bg-gray-100';
    }

    /**
     * Business Logic Methods
     */
    public function canBeEditedByUser($user): bool
    {
        return $this->created_by == $user->id && $this->wastage_status->canBeEdited();
    }

    public function canBeApprovedByUser($user): bool
    {
        if ($this->wastage_status->canBeApprovedLevel1()) {
            return $user->hasPermissionTo('approve wastage level 1');
        } elseif ($this->wastage_status->canBeApprovedLevel2()) {
            return $user->hasPermissionTo('approve wastage level 2');
        }
        return false;
    }

    public function canBeCancelledByUser($user): bool
    {
        return $this->wastage_status->canBeCancelled() &&
               ($user->hasPermissionTo('cancel wastage record') || $this->created_by == $user->id);
    }

    public function isPending(): bool
    {
        return $this->wastage_status === WastageStatus::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->wastage_status->isApproved();
    }

    public function isFinal(): bool
    {
        return $this->wastage_status->isFinal();
    }

    public function getWorkflowSummary(): array
    {
        return [
            'current_status' => $this->wastage_status->getLabel(),
            'is_editable' => $this->wastage_status->canBeEdited(),
            'can_approve_level1' => $this->wastage_status->canBeApprovedLevel1(),
            'can_approve_level2' => $this->wastage_status->canBeApprovedLevel2(),
            'can_cancel' => $this->wastage_status->canBeCancelled(),
            'is_final' => $this->wastage_status->isFinal(),
            'next_status' => $this->wastage_status->getNextApprovalStatus()?->getLabel(),
        ];
    }

    /**
     * Audit trail configuration
     */
    public function transformAudit(array $data): array
    {
        if (isset($data['new_values']['wastage_status'])) {
            $oldStatus = $data['old_values']['wastage_status'] ?? 'N/A';
            $newStatus = $data['new_values']['wastage_status'];
            $data['new_values']['wastage_status'] = $newStatus;
            $data['old_values']['wastage_status'] = $oldStatus;
        }

        return $data;
    }

    /**
     * Attributes that should be included in audit trail
     */
    public function getAuditInclude(): array
    {
        return [
            'store_branch_id',
            'sap_masterfile_id',
            'wastage_qty',
            'cost',
            'reason',
            'remarks',
            'image_url',
            'wastage_status',
        ];
    }
}
