<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    /** @use HasFactory<\Database\Factories\TemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'template',
        'user_id'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
