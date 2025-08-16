<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use Illuminate\Support\Facades\Storage;

class ImageAttachment extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\ImageAttachmentFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    /**
     * The fields that are mass assignable, matching your database schema.
     */
    protected $fillable = [
        'store_order_id',
        'file_path',
        'mime_type',
        'is_approved',
        'uploaded_by_user_id'
    ];

    /**
     * Appends the 'image_url' accessor to model arrays/JSON.
     */
    protected $appends = ['image_url'];

    /**
     * Accessor to generate the full public URL for the image.
     * This now uses the 'public' disk configuration which points to public/uploads.
     */
    public function getImageUrlAttribute()
    {
        if (empty($this->file_path)) {
            return null;
        }
        
        // This will now correctly generate URLs like 'http://yourdomain.com/uploads/order_attachments/filename.jpg'
        return Storage::disk('public')->url($this->file_path);
    }

    /**
     * Get the order that this image belongs to.
     */
    public function store_order()
    {
        return $this->belongsTo(StoreOrder::class);
    }

    /**
     * Get the user who uploaded the image.
     */
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
