<?php

namespace App\Models;

use App\Traits\HasSelections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory, HasSelections;

    protected $table = 'branch';

    protected $fillable = [
        'branch_code',
        'name',
        'status'
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
