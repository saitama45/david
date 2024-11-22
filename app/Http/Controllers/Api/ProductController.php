<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductInventory;
use Illuminate\Http\Request;


class ProductController extends Controller
{
    public function show($id)
    {

        $item = ProductInventory::with('unit_of_measurement')->find($id);
        $item = [
            'id' => $item->id,
            'name' => $item->name,
            'inventory_code' => $item->inventory_code,
            'unit_of_measurement' => $item->unit_of_measurement->name,
            'cost' => $item->cost
        ];

        return response()->json($item);
    }
}
