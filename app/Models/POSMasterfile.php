<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use OwenIt\Auditing\Contracts\Auditable;

class POSMasterfile extends Model implements Auditable
{
    use HasFactory, \OwenIt\Auditing\Auditable;
    
    protected $table = 'pos_masterfiles';

    protected $fillable = [
        'ItemCode',
        'ItemDescription',
        'Category',
        'SubCategory',
        'SRP',
        'is_active'
    ];

    protected $casts = [
        'SRP' => 'decimal:4', // Cast to decimal with 4 decimal places
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Ensure 'id' is used as the unique key for upsert
    protected $primaryKey = 'id';
    public $incrementing = true; // Assuming id is not auto-incrementing
}
