<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use App\Enums\IntercoStatus;

class StoreOrder extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\StoreOrderFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;
    // Ordering -> Store Order
    //NNSSR-00001

    // Ensure relationships are always loaded for JSON serialization
    protected $with = ['sendingStore', 'store_branch', 'encoder'];

    // Append computed attributes to JSON
    protected $appends = [
        'from_store_name',
        'to_store_name'
    ];

    protected $fillable = [
        'encoder_id',
        'supplier_id',
        'store_branch_id',
        'approver_id',
        'commiter_id', // New
        'order_number',
        'order_date',
        'order_status',
        'remarks',
        'variant',
        'approval_action_date',
        'commited_action_date', // New
        'batch_reference',
        // Interco fields
        'interco_number',
        'sending_store_branch_id',
        'interco_reason',
        'interco_status',
        'transfer_date',
    ];

    protected $casts = [
        // 'order_date' => 'date:F d, Y',
        'order_approved_date' => 'date:F d, Y',
        'approval_action_date' => 'date:F d, Y h:i a',
        'transfer_date' => 'date',
        'interco_status' => IntercoStatus::class,
    ];

    /**
     * Prepare a date for array / JSON serialization.
     *
     * @param  \DateTimeInterface  $date
     * @return string
     */
    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function storeOrderItems()
    {
        return $this->hasMany(StoreOrderItem::class);
    }

    
    public function encoder()
    {
        return $this->belongsTo(User::class, 'encoder_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
    public function commiter()
    {
        return $this->belongsTo(User::class, 'commiter_id');
    }

    public function store_branch()
    {
        return $this->belongsTo(StoreBranch::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    // Interco relationship for sending store
    public function sendingStore()
    {
        return $this->belongsTo(StoreBranch::class, 'sending_store_branch_id');
    }

    public function store_order_items()
    {
        return $this->hasMany(StoreOrderItem::class);
    }

    public function ordered_item_receive_dates()
    {
        return $this->hasManyThrough(OrderedItemReceiveDate::class, StoreOrderItem::class);
    }

    public function delivery_receipts()
    {
        return $this->hasMany(DeliveryReceipt::class);
    }

    public function store_order_remarks()
    {
        return $this->hasMany(StoreOrderRemark::class);
    }

    public function image_attachments()
    {
        return $this->hasMany(ImageAttachment::class);
    }

    // Interco-specific methods
    public function isInterco()
    {
        return !is_null($this->interco_number) && !is_null($this->sending_store_branch_id);
    }

    public function getIntercoNumberDisplayAttribute()
    {
        if ($this->isInterco()) {
            return $this->interco_number;
        }
        return null;
    }

    public function getIntercoStatusLabelAttribute()
    {
        return $this->interco_status?->getLabel() ?? 'N/A';
    }

    public function getIntercoStatusColorAttribute()
    {
        return $this->interco_status?->getColor() ?? 'gray';
    }

    public function canBeEditedByUser($user)
    {
        if (!$this->isInterco()) {
            return false;
        }

        return $this->interco_status?->canBeEdited() &&
               ($this->encoder_id === $user->id ||
                $this->store_branch_id === $user->store_branch_id ||
                $user->hasPermissionTo('edit interco requests'));
    }

    public function canBeApprovedByUser($user)
    {
        if (!$this->isInterco()) {
            return false;
        }

        return $this->interco_status?->canBeApproved() &&
               $user->hasPermissionTo('approve interco requests');
    }

    public function canBeCommittedByUser($user)
    {
        if (!$this->isInterco()) {
            return false;
        }

        return $this->interco_status?->canBeCommitted() &&
               $user->hasPermissionTo('commit interco requests');
    }

    public function canBeReceivedByUser($user)
    {
        if (!$this->isInterco()) {
            return false;
        }

        return $this->interco_status?->canBeReceived() &&
               $this->store_branch_id === $user->store_branch_id &&
               $user->hasPermissionTo('receive orders');
    }

    /**
     * Get the from store name attribute for JSON serialization
     */
    public function getFromStoreNameAttribute()
    {
        if (!$this->sendingStore) {
            return 'Unknown Sending Store';
        }

        return $this->sendingStore->name ?:
               $this->sendingStore->branch_name ?:
               $this->sendingStore->brand_name ?:
               'Unknown Sending Store';
    }

    /**
     * Get the to store name attribute for JSON serialization
     */
    public function getToStoreNameAttribute()
    {
        if (!$this->store_branch) {
            return 'Unknown Receiving Store';
        }

        return $this->store_branch->name ?:
               $this->store_branch->branch_name ?:
               'Unknown Receiving Store';
    }

    /**
     * Check if this order has any committed items
     */
    public function hasCommittedItems()
    {
        return $this->store_order_items()->whereNotNull('committed_by')->exists();
    }

    /**
     * Get the count of committed items
     */
    public function getCommittedItemsCount()
    {
        return $this->store_order_items()->whereNotNull('committed_by')->count();
    }

    /**
     * Get the count of total items
     */
    public function getTotalItemsCount()
    {
        return $this->store_order_items()->count();
    }

    /**
     * Check if all items in this order are committed
     */
    public function isFullyCommitted()
    {
        $totalItems = $this->getTotalItemsCount();
        $committedItems = $this->getCommittedItemsCount();

        return $totalItems > 0 && $totalItems === $committedItems;
    }

    /**
     * Check if this order is partially committed (some but not all items)
     */
    public function isPartiallyCommitted()
    {
        $totalItems = $this->getTotalItemsCount();
        $committedItems = $this->getCommittedItemsCount();

        return $totalItems > 0 && $committedItems > 0 && $committedItems < $totalItems;
    }

    /**
     * Get items that can be committed by the given user based on permissions
     */
    public function getCommittableItemsByUser($user)
    {
        if (!$user) {
            return collect();
        }

        return $this->store_order_items->filter(function ($item) use ($user) {
            return $item->canBeCommittedBy($user);
        });
    }

    /**
     * Get items that are already committed by the given user
     */
    public function getItemsCommittedByUser($user)
    {
        if (!$user) {
            return collect();
        }

        return $this->store_order_items->filter(function ($item) use ($user) {
            return $item->isCommittedBy($user->id);
        });
    }

    /**
     * Get items that are committed by other users
     */
    public function getItemsCommittedByOthers($user)
    {
        if (!$user) {
            return collect();
        }

        return $this->store_order_items->filter(function ($item) use ($user) {
            return $item->committed_by && $item->committed_by !== $user->id;
        });
    }

    /**
     * Get items that are not yet committed by anyone
     */
    public function getUncommittedItems()
    {
        return $this->store_order_items->filter(function ($item) {
            return is_null($item->committed_by);
        });
    }

    /**
     * Update order status based on commit status of items
     */
    public function updateOrderStatusBasedOnCommits()
    {
        $totalItems = $this->getTotalItemsCount();
        $committedItems = $this->getCommittedItemsCount();

        \Illuminate\Support\Facades\Log::info('StoreOrder - updateOrderStatusBasedOnCommits', [
            'order_id' => $this->id,
            'totalItems' => $totalItems,
            'committedItems' => $committedItems,
            'isFullyCommitted' => $totalItems > 0 && $totalItems === $committedItems,
            'isPartiallyCommitted' => $totalItems > 0 && $committedItems > 0 && $committedItems < $totalItems
        ]);

        if ($this->isFullyCommitted()) {
            $this->order_status = \App\Enum\OrderStatus::COMMITTED->value;
        } elseif ($this->isPartiallyCommitted()) {
            $this->order_status = \App\Enum\OrderStatus::PARTIAL_COMMITTED->value;
        }

        $this->save();
    }
}
