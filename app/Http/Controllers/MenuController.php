<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MenuController extends Controller
{
    public function index()
    {
        $menus = Menu::with('category')
            ->paginate(10)
            ->through(function ($menu) {
                return [
                    'id' => $menu->id,
                    'name' => $menu->name,
                    'price' => $menu->price,
                    'category' => $menu->category->name,
                ];
            });


        return Inertia::render('Menu/Index', [
            'menus' => $menus
        ]);
    }
}
