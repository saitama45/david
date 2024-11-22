<?php

namespace App\Http\Controllers;

use App\Models\InventoryCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InvetoryCategoryController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = InventoryCategory::query();

        if ($search)
            $query->where('name', 'like', "%$search%");

        $categories = $query->paginate(10);
        return Inertia::render('InvetoryCategory/Index', [
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

        $category = InventoryCategory::findOrFail($id);
        $category->update($validated);

        return to_route('categories.index');
    }
}
