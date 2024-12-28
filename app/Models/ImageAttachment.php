<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImageAttachment extends Model
{
    /** @use HasFactory<\Database\Factories\ImageAttachmentFactory> */
    use HasFactory;

    protected $fillable = ['store_order_id','file_path', 'mime_type'];
}
