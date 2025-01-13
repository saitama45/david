<?php

namespace App\Http\Controllers;

use App\Models\MenuCategory;
use App\Traits\traits\HasReferenceStoreAction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MenuCategoryController extends Controller
{
    use HasReferenceStoreAction;
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

    public function destroy($id)
    {
        $category = MenuCategory::with('menus')->findOrFail($id);

        if ($category->menus->count() > 0) {
            return back()->withErrors([
                'message' => "Can't delete this menu category because there are menus associated with it."
            ]);
        }

        $category->delete();
        return to_route('menu-categories.index');
    }



    protected function getModel()
    {
        return MenuCategory::class;
    }

    protected function getRouteName()
    {
        return "menu-categories.index";
    }
}
