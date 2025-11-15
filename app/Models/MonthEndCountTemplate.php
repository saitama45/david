<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonthEndCountTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_code',
        'item_name',
        'area',
        'category_2',
        'category',
        'brand',
        'packaging_config',
        'config',
        'uom',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Scopes for common queries
    public function scopeSearch($query, $search)
    {
        if ($search) {
            return $query->where('item_code', 'like', "%$search%")
                        ->orWhere('item_name', 'like', "%$search%")
                        ->orWhere('area', 'like', "%$search%")
                        ->orWhere('category_2', 'like', "%$search%")
                        ->orWhere('category', 'like', "%$search%")
                        ->orWhere('brand', 'like', "%$search%")
                        ->orWhere('packaging_config', 'like', "%$search%")
                        ->orWhere('config', 'like', "%$search%")
                        ->orWhere('uom', 'like', "%$search%");
        }
        return $query;
    }
}
