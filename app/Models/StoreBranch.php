<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreBranch extends Model
{
    /** @use HasFactory<\Database\Factories\StoreBranchFactory> */
    use HasFactory;

    protected $fillable = [
        'id',
        'branch_code',
        'name',
        'status'
    ];
}
