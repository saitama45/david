<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImageAttachment extends Model
{
    /** @use HasFactory<\Database\Factories\ImageAttachmentFactory> */
    use HasFactory;

    protected $fillable = ['file_path', 'mime_type'];
}
