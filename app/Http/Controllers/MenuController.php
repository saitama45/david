<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\ProductInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function create()
    {
        $categories = MenuCategory::options();
        $products = ProductInventory::options();
        return Inertia::render('Menu/Create', [
            'categories' => $categories,
            'products' => $products,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required'],
            'price' => ['required', 'numeric'],
            'category_id' => ['required', 'exists:menu_categories,id'],
            'remarks' => ['nullable'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.id' => ['required', 'exists:product_inventories,id'],
            'ingredients.*.quantity' => ['required', 'numeric', 'min:0.1'],
        ]);

        $ingredients = $validated['ingredients'];
        DB::beginTransaction();
        $menu = Menu::create($validated);

        foreach ($ingredients as $ingredient) {
            $menu->product_inventories()->attach(['product_inventory_id' => $ingredient['id']], [
                'quantity' => $ingredient['quantity'],
            ]);
        }
        DB::commit();

        return redirect()->route('menu-list.index');
    }

    public function show($id)
    {
        $menu = Menu::with(['product_inventories', 'product_inventories.unit_of_measurement'])->findOrFail($id);

        $ingredients = $menu->product_inventories->map(function ($ingredient) {
            return [
                'id' => $ingredient->id,
                'inventory_code' => $ingredient->inventory_code,
                'name' => $ingredient->name,
                'quantity' => $ingredient->pivot->quantity,
                'uom' => $ingredient->unit_of_measurement->name,
            ];
        });
        return Inertia::render('Menu/Show', [
            'menu' => $menu,
            'ingredients' => $ingredients,
        ]);
    }

    public function edit($id)
    {
        return Inertia::render('Menu/Edit');
    }
}
