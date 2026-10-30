<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An invoice or receipt file of a CPO order, kept on the private disk. The store uploads it in
 * Inbound Orders when it receives the order (OrderReceivingController::attachInvoice); CPO
 * Orders, Mass Orders Approval and Inbound Orders open it.
 */
class CpoOrderAttachment extends Model
{
    public const DISK = 'local';

    public const DIRECTORY = 'cpo-invoices';

    /** The most invoice / receipt files an order takes, and the size of each (KB). */
    public const MAX_FILES = 5;

    public const MAX_KB = 5120;

    protected $fillable = [
        'store_order_id',
        'file_path',
        'original_name',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    protected $appends = ['url'];

    public function store_order()
    {
        return $this->belongsTo(StoreOrder::class);
    }

    public function getUrlAttribute(): string
    {
        return route('cpo-orders.attachments.show', $this->id);
    }
}
