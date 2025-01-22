<?php

namespace App\Http\Controllers;

use App\Exports\BOMListExport;
use App\Imports\MenusImport;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\ProductInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class MenuController extends Controller
{
    public function index()
    {
        $search = request('search');

        $query = Menu::query()->with('category');

        if ($query)
            $query->whereAny(['product_id', 'name'], 'like', "%{$search}%");
        $menus = $query
            ->latest()
            ->paginate(10)
            ->through(function ($menu) {
                return [
                    'id' => $menu->id,
                    'product_id' => $menu->product_id,
                    'name' => $menu->name,
                    'price' => $menu->price,
                    'category' => $menu->category->name,
                ];
            });
        return Inertia::render('Menu/Index', [
            'menus' => $menus,
            'filters' => request()->only(['search'])
        ]);
    }

    public function export()
    {
        $search = request('search');

        return Excel::download(
            new BOMListExport($search),
            'bom-list-' . now()->format('Y-m-d') . '.xlsx'
        );
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
            'product_id' => ['required', 'unique:menus,product_id'],
            'price' => ['required', 'numeric'],
            'category_id' => ['required', 'exists:menu_categories,id'],
            'remarks' => ['nullable'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.id' => ['required', 'exists:product_inventories,id'],
            'ingredients.*.quantity' => ['required', 'numeric', 'min:0.1'],
            'ingredients.*.unit' => ['required'],
        ]);

        $ingredients = $validated['ingredients'];
        DB::beginTransaction();
        $menu = Menu::create($validated);

        foreach ($ingredients as $ingredient) {
            $menu->product_inventories()->attach($ingredient['id'], [
                'quantity' => $ingredient['quantity'],
                'unit' => $ingredient['unit']
            ]);
        }
        DB::commit();

        return redirect()->route('menu-list.index');
    }
    public  function import(Request $request)
    {

        $request->validate([
            'menu_file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv',
            ]
        ]);
        Excel::import(new MenusImport, $request->file('menu_file'));

        return to_route('menu-list.index');
    }

    public function destroy($id)
    {
        $menu = Menu::with('usage_record_items')->findOrFail($id);

        if ($menu->usage_record_items->count() > 0) {
            return back()->withErrors([
                'message' => "Can't delete this menu because there are data associated with it."
            ]);
        }

        $menu->delete();
        return to_route('menu-list.index');
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
                'uom' => $ingredient->pivot->unit ?? $ingredient->unit_of_measurement->name,
            ];
        });
        return Inertia::render('Menu/Show', [
            'menu' => $menu,
            'ingredients' => $ingredients,
        ]);
    }

    public function edit($id)
    {
        $categories = MenuCategory::options();
        $products = ProductInventory::options();
        $menu = Menu::with(['product_inventories', 'product_inventories.unit_of_measurement'])->findOrFail($id);

        $ingredients = $menu->product_inventories->map(function ($ingredient) {
            return [
                'id' => $ingredient->id,
                'inventory_code' => $ingredient->inventory_code,
                'name' => $ingredient->name,
                'quantity' => $ingredient->pivot->quantity,
                'uom' => $ingredient->pivot->unit ?? $ingredient->unit_of_measurement->name,
            ];
        });
        return Inertia::render('Menu/Edit', [
            'menu' => $menu,
            'ingredients' => $ingredients,
            'categories' => $categories,
            'products' => $products,
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => ['required'],
            'product_id' => ['required', 'unique:menus,product_id,' . $id],
            'price' => ['required', 'numeric'],
            'category_id' => ['required', 'exists:menu_categories,id'],
            'remarks' => ['nullable'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.id' => ['required', 'exists:product_inventories,id'],
            'ingredients.*.quantity' => ['required', 'numeric', 'min:0.1'],
        ]);

        DB::beginTransaction();
        try {
            $menu = Menu::findOrFail($id);

            $menu->update([
                'name' => $validated['name'],
                'product_id' => $validated['product_id'],
                'price' => $validated['price'],
                'category_id' => $validated['category_id'],
                'remarks' => $validated['remarks'],
            ]);

            $menu->product_inventories()->detach();

            foreach ($validated['ingredients'] as $ingredient) {
                $menu->product_inventories()->attach($ingredient['id'], [
                    'quantity' => $ingredient['quantity'],
                ]);
            }

            DB::commit();

            return redirect()->route('menu-list.index');
        } catch (\Exception $e) {
            DB::rollback();
            return back()->withErrors(['error' => 'Failed to update menu. ' . $e->getMessage()]);
        }
    }
}
