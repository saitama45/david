<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function show($id)
    {

        $item = Menu::find($id);
        $item = [
            'id' => $item->id,
            'name' => $item->name,
            'price' => $item->price,
        ];

        return response()->json($item);
    }
}
