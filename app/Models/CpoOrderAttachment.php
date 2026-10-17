<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An invoice or receipt file of a CPO order, kept on the private disk and opened through
 * CPO Orders or Mass Orders Approval only.
 */
class CpoOrderAttachment extends Model
{
    public const DISK = 'local';

    public const DIRECTORY = 'cpo-invoices';

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
