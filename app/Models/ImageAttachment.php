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
     *
     * FIX: Reverted to the standard and correct Laravel method for generating storage URLs.
     * Storage::disk('public')->url() correctly uses the symbolic link to create a
     * URL like '/storage/filename.jpg', which is the proper way to access files
     * from the public directory. This resolves the 403/404 errors.
     */
    public function getImageUrlAttribute()
    {
        if (empty($this->file_path)) {
            return null;
        }
        
        // Use direct URL construction for SmarterASP
        return url('storage/app/public/' . $this->file_path);
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
