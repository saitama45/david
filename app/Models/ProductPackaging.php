<?php

namespace App\Models;

use App\Traits\Traits\ProductInventoryReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductPackaging extends Model
{
    /** @use HasFactory<\Database\Factories\ProductPackagingFactory> */
    use HasFactory, ProductInventoryReference;
}
