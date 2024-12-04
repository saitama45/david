<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAssignedStoreBranch extends Model
{
    /** @use HasFactory<\Database\Factories\UserAssignedStoreBranchFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'store_branch_id',
    ];
}
