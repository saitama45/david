<?php

namespace App\Http\Controllers;

use App\Models\Classification;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Traits\traits\HasReferenceStoreAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CategoryController extends Controller
{
    use HasReferenceStoreAction;
    public function index()
    {
        $search = request('search');
        $query = ProductCategory::query();

        if ($search)
            $query->where('name', 'like', "%$search%");

        $categories = $query->latest()->paginate(10)->withQueryString();
        return Inertia::render('Category/Index', [
            'categories' => $categories,
            'filters' => request()->only(['search'])
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required',
            'remarks' => 'required',
        ]);

        $category = ProductCategory::findOrFail($id);
        $category->update($validated);


        return to_route('categories.index');
    }

    protected function getModel()
    {
        return ProductCategory::class;
    }

    protected function getRouteName()
    {
        return "categories.index";
    }
}
