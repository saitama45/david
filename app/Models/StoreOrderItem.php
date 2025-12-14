<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use App\Models\SAPMasterfile;

class StoreOrderItem extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\StoreOrderItemFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $touches = ['store_order'];

    // Removed automatic eager loading to prevent N+1 queries when explicitly eager loading

    // Append computed attributes to JSON
    protected $appends = [
        'item_description',
        'item_uom'
    ];

    protected $fillable = [
        'store_order_id',
        'item_code',
        'sap_masterfile_id',
        'quantity_ordered',
        'quantity_approved',
        'quantity_commited',
        'quantity_received',
        'cost_per_quantity',
        'total_cost',
        'uom',
        'remarks',
        'committed_by',
        'committed_date',
    ];

    protected $casts = [
        'total_cost' => 'decimal:2'
    ];


    public function store_order()
    {
        return $this->belongsTo(StoreOrder::class);
    }

    public function committedBy()
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    public function supplierItem()
    {
        // CRITICAL FIX: Link 'item_code' (on this model) to 'ItemCode' (on SupplierItems)
        return $this->belongsTo(SupplierItems::class, 'item_code', 'ItemCode');
    }

    public function sapMasterfile()
    {
        // Always return a valid relationship for eager loading compatibility
        // Use sap_masterfile_id when available, otherwise use item_code
        if ($this->sap_masterfile_id) {
            return $this->belongsTo(SAPMasterfile::class, 'sap_masterfile_id');
        }

        // Fallback to item_code relationship for eager loading
        return $this->belongsTo(SAPMasterfile::class, 'item_code', 'ItemCode');
    }

    /**
     * Get the sap masterfile with intelligent duplicate handling
     */
    protected function getSapMasterfileAttribute()
    {
        // First try the relationship
        $sapMasterfile = $this->getRelationValue('sapMasterfile');

        if ($sapMasterfile) {
            // If we got a relationship result and we have sap_masterfile_id, use it
            if ($this->sap_masterfile_id) {
                return $sapMasterfile;
            }

            // If we're using item_code fallback, check for duplicates and find best match
            return $this->findBestSapMasterfile($sapMasterfile);
        }

        return null;
    }

    /**
     * Find the best SAP masterfile among duplicates for this item
     */
    private function findBestSapMasterfile($currentMatch = null)
    {
        if (!$this->item_code) {
            return $currentMatch;
        }

        // Get all active SAP masterfiles with this ItemCode
        $candidates = SAPMasterfile::where('ItemCode', $this->item_code)
            ->where('is_active', true)
            ->get();

        if ($candidates->isEmpty()) {
            return $currentMatch;
        }

        // If only one candidate, return it
        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // Multiple candidates - use intelligent selection
        $bestMatch = null;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            $score = 0;

            // Priority 1: Exact UOM match
            if ($this->uom && $candidate->AltUOM === $this->uom) {
                $score += 100;
            } elseif ($this->uom && $candidate->BaseUOM === $this->uom) {
                $score += 80;
            }

            // Priority 2: Common UOM preferences (BAG, CASE, etc.)
            if ($candidate->AltUOM && in_array($candidate->AltUOM, ['BAG(1)', 'CASE(12)', 'CASE(24)'])) {
                $score += 20;
            }

            // Priority 3: Most recently updated
            $score += $candidate->updated_at->timestamp / 1000000000; // Small weight for recency

            // Priority 4: Higher BaseQty (usually indicates primary unit)
            if ($candidate->BaseQty > 1) {
                $score += 10;
            }

            // Priority 5: Prefer the current match if it has a decent score
            if ($currentMatch && $candidate->id === $currentMatch->id) {
                $score += 5; // Small bonus for current match
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $candidate;
            }
        }

        return $bestMatch ?: ($currentMatch ?: $candidates->first());
    }

    /**
     * Scope to find items with intelligent SAP masterfile resolution
     */
    public function scopeWithBestSapMasterfile($query)
    {
        return $query->with(['sapMasterfile' => function ($relationQuery) {
            // When using item_code relationship, order by preferences
            $relationQuery->where('is_active', true)
                ->orderByRaw("
                    CASE
                        WHEN AltUOM = ? THEN 1
                        WHEN BaseUOM = ? THEN 2
                        WHEN AltUOM IN ('BAG(1)', 'CASE(12)', 'CASE(24)') THEN 3
                        ELSE 4
                    END
                ", [$this->uom ?? '', $this->uom ?? ''])
                ->orderBy('updated_at', 'desc')
                ->orderBy('BaseQty', 'desc');
        }]);
    }

    public function cash_pull_out()
    {
        return $this->belongsTo(CashPullOut::class);
    }

    public function ordered_item_receive_dates()
    {
        return $this->hasMany(OrderedItemReceiveDate::class);
    }

    public function purchase_item_batch()
    {
        return $this->hasMany(PurchaseItemBatch::class);
    }

    /**
     * Get the item description attribute for JSON serialization
     */
    public function getItemDescriptionAttribute()
    {
        if (!$this->sapMasterfile) {
            return 'Description not available';
        }

        return $this->sapMasterfile->ItemDescription ?:
               $this->sapMasterfile->ItemName ?:
               'Description not available';
    }

    /**
     * Get the item UOM attribute for JSON serialization
     */
    public function getItemUomAttribute()
    {
        // Use the actual UOM field from store_order_items table
        return $this->uom ?: '';
    }

    /**
     * Check if this item is committed by a specific user
     */
    public function isCommittedBy($userId)
    {
        return $this->committed_by === $userId;
    }

    /**
     * Mark this item as committed by a user
     */
    public function markAsCommittedBy($userId)
    {
        $this->committed_by = $userId;
        $this->committed_date = now();
        $this->save();
    }

    /**
     * Remove commit status from this item
     */
    public function removeCommitStatus()
    {
        $this->committed_by = null;
        $this->committed_date = null;
        $this->save();
    }

    /**
     * Check if this item can be committed by the current user based on permissions
     */
    public function canBeCommittedBy($user)
    {
        if (!$user) {
            return false;
        }

        // If item is already committed by this user, they can modify it
        if ($this->isCommittedBy($user->id)) {
            return true;
        }

        // Check supplierItem relationship first (primary source for category)
        $itemCategory = null;
        if ($this->supplierItem) {
            $itemCategory = $this->supplierItem->category;
        } elseif ($this->sapMasterfile) {
            // Fallback to SAP masterfile if supplierItem not available
            $itemCategory = $this->sapMasterfile->Category;
        }

        if ($itemCategory) {
            $isFinishedGood = in_array($itemCategory, ['FINISHED GOODS', 'FG', 'FINISHED GOOD']);

            // User can commit if they have the appropriate permission for the item category
            if ($isFinishedGood) {
                return $user->can('edit finished good commits');
            } else {
                return $user->can('edit other commits');
            }
        }

        // If no category found, require at least one permission
        return $user->can('edit finished good commits') || $user->can('edit other commits');
    }
}
