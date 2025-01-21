<?php

namespace App\Http\Controllers;

use App\Exports\MenuCategoriesExport;
use App\Models\MenuCategory;
use App\Traits\traits\HasReferenceStoreAction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class MenuCategoryController extends Controller
{
    use HasReferenceStoreAction;
    public function index()
    {
        $search = request('search');
        $query = MenuCategory::query();

        if ($search)
            $query->where('name', 'like', "%$search%");
        $categories = $query->latest()->paginate(10)->withQueryString();
        return Inertia::render('MenuCategory/Index', [
            'categories' => $categories,
            'filters' => request()->only(['search'])
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => ['required', 'unique:menu_categories,name,' . $id],
        ]);

        $category = MenuCategory::findOrFail($id);
        $category->update($validated);


        return to_route('menu-categories.index');
    }

    public function export(Request $request)
    {
        $search = $request->input('search');

        return Excel::download(
            new MenuCategoriesExport($search),
            'menu-categories-' . now()->format('Y-m-d') . '.xlsx'
        );
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

    protected function getTableName()
    {
        return 'menu_categories';
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
