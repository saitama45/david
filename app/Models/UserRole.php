<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class UserRole extends Model implements Auditable
{
    /** @use HasFactory<\Database\Factories\UserRoleFactory> */
    use HasFactory, \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'user_id',
        'role'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
