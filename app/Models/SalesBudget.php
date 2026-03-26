<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesBudget extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'year' => 'integer',
        'jan' => 'double',
        'feb' => 'double',
        'mar' => 'double',
        'apr' => 'double',
        'may' => 'double',
        'jun' => 'double',
        'jul' => 'double',
        'aug' => 'double',
        'sep' => 'double',
        'oct' => 'double',
        'nov' => 'double',
        'dec' => 'double',
    ];
}
