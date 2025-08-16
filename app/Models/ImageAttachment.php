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
     * This has been modified to include 'app/public/' in the URL path as requested.
     *
     * IMPORTANT NOTE: Standard Laravel setups using 'public/storage' symbolic links
     * do NOT expose 'storage/app/public' directly in the URL.
     * This change might lead to a '404 Not Found' error on production servers
     * unless your web server is explicitly configured to serve files from
     * `your-app-root/public/storage/app/public/` when it sees `/storage/app/public/` in the URL.
     *
     * The file_path stored in the database is typically relative to the 'public' disk root (e.g., 'order_attachments/image.jpg').
     * We are now prepending 'app/public/' to that path for URL generation.
     */
    public function getImageUrlAttribute()
    {
        if (empty($this->file_path)) {
            return null;
        }
        
        // This constructs the URL to explicitly include 'storage/app/public/'
        // Example: if file_path is 'order_attachments/image.jpg', URL becomes '/storage/app/public/order_attachments/image.jpg'
        return asset('storage/' . $this->file_path);
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
