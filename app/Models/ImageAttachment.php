<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class ImageAttachment extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ImageAttachmentFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = ['store_order_id', 'file_path', 'mime_type', 'is_approved'];

    public function store_order()
    {
        return $this->belongsTo(StoreOrder::class);
    }
}
