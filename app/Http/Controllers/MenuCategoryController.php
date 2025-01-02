<?php

namespace App\Http\Controllers;

use App\Models\MenuCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MenuCategoryController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = MenuCategory::query();

        if ($search)
            $query->where('name', 'like', "%$search%");
        $categories = $query->paginate(10);
        return Inertia::render('MenuCategory/Index', [
            'categories' => $categories,
            'filters' => request()->only(['search'])
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required',
        ]);

        $category = MenuCategory::findOrFail($id);
        $category->update($validated);


        return to_route('menu-categories.index');
    }
}
