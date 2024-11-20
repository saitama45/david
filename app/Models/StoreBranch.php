<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoreBranch extends Model
{
    /** @use HasFactory<\Database\Factories\StoreBranchFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'branch_code',
        'brand_name',
        'brand_code',
        'phone_number',
        'email',
        'is_active',
        'tin',
        'point_of_contact',
        'store_representative_email',
        'head_chef_email',
        'dir_ops_email',
        'vp_ops_email',
        'address',
    ];
}
