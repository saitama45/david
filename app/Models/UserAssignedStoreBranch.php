<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class UserAssignedStoreBranch extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\UserAssignedStoreBranchFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'user_id',
        'store_branch_id',
    ];
}
