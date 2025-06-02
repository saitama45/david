<?php

namespace App\Http\Controllers;

use App\Exports\BOMListExport;
use App\Imports\BOMIngredientImport;
use App\Imports\BOMListImport;
use App\Imports\MenusImport;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\ProductInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class MenuController extends Controller
{
    public function index()
    {
        $search = request('search');

        $query = Menu::query()->with('menu_ingredients.product');


        if ($query)
            $query->whereAny(['product_id', 'name'], 'like', "%{$search}%");
        $menus = $query
            ->latest()
            ->paginate(10)
            ->through(function ($menu) {
                return [
                    'id' => $menu->id,
                    'product_id' => $menu->product_id,
                    'remarks' => $menu->remarks,
                    'name' => $menu->name,
                    'total' =>  0,
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
            'product_id' => ['required', 'unique:menus,product_id'],
            'name' => ['required'],
            'remarks' => ['nullable'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.id' => ['required', 'exists:product_inventories,id'],
            'ingredients.*.quantity' => ['required', 'numeric', 'min:0'],
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
        $menu = Menu::findOrFail($id);

        try {
            $menu->delete();
            return back();
        } catch (\Exception $e) {
            return back()->withErrors([
                'message' => "Can't delete this menu because there are related records in other tables."
            ]);
        }
    }

    public function show($id)
    {
        $menu = Menu::with(['product_inventories', 'product_inventories.unit_of_measurement', 'menu_ingredients.wip', 'menu_ingredients.product'])->findOrFail($id);

        $ingredients = $menu->menu_ingredients->map(function ($ingredient) {
            $wip = $ingredient->wip;
            $product = $ingredient->product;

            if ($wip) {
                return [
                    'id' => $ingredient->id,
                    'inventory_code' => $wip->sap_code,
                    'name' => $wip->name,
                    'remarks' => $wip->remarks,
                    'quantity' => $ingredient->quantity,
                    'uom' => $ingredient->unit ?? $ingredient->unit_of_measurement->name,
                    'cost' => 0
                ];
            } else {
                return [
                    'id' => $product->id,
                    'inventory_code' => $product->inventory_code,
                    'name' => $product->name,
                    'quantity' => $ingredient->quantity,
                    'cost' => number_format($product->cost * $ingredient->quantity, 2, '.', ","),
                    'uom' => $ingredient->unit ?? $product->unit_of_measurement->name,
                ];
            }
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
            'product_id' => ['required', 'unique:menus,product_id,' . $id],
            'name' => ['required'],
            'remarks' => ['nullable'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.id' => ['required', 'exists:product_inventories,id'],
            'ingredients.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);

        DB::beginTransaction();
        try {
            $menu = Menu::findOrFail($id);

            $menu->update([
                'product_id' => $validated['product_id'],
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
            dd($e);
            DB::rollback();
            return back()->withErrors(['error' => 'Failed to update menu. ' . $e->getMessage()]);
        }
    }

    public function importBomList(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv'
        ]);

        Excel::import(new BOMListImport, $request->file('file'));

        return back();
    }

    public function importBomIngredients(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv'
        ]);

        $import = new BOMIngredientImport();

        try {
            Excel::import($import, $request->file('file'));

            $processedCount = $import->getProcessedCount();

            Log::info('WIP Ingredient Import Completed Successfully', [
                'processed_rows' => $processedCount,
                'file_name' => $request->file('file')->getClientOriginalName()
            ]);

            return redirect()->back()->with('success', "BOM ingredients imported successfully! {$processedCount} rows processed.");
        } catch (\Exception $e) {
            Log::error('WIP Import Failed', [
                'error' => $e->getMessage(),
                'file_name' => $request->file('file')->getClientOriginalName(),
                'trace' => $e->getTraceAsString()
            ]);

            // Check if it's a validation error (contains our custom validation messages)
            if (strpos($e->getMessage(), 'Validation failed:') === 0) {
                $errorMessage = str_replace('Validation failed: ', '', $e->getMessage());
                $errors = explode('; ', $errorMessage);

                return redirect()->back()->withErrors([
                    'validation_errors' => $errors
                ])->with('error', 'Import cancelled due to validation errors. Please fix the following issues:');
            }

            // For other types of errors
            return redirect()->back()->withErrors([
                'message' => 'Import failed: ' . $e->getMessage()
            ])->with('error', 'Import was cancelled due to an error.');
        }
    }
}
