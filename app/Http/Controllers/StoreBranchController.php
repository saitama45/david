<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StoreBranchController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = StoreBranch::query();

        if ($search)
            $query->whereAny(['name', 'location_code'], 'like', "%$search%");

        $branches = $query->paginate(10);
        return Inertia::render('StoreBranch/Index', [
            'data' => $branches,
            'filters' => request()->only(['search'])
        ]);
    }

    public function show($id)
    {
        $branch = StoreBranch::findOrFail($id);
        return Inertia::render('StoreBranch/Show', [
            'branch' => $branch
        ]);
    }

    public function edit($id)
    {
        $branch = StoreBranch::findOrFail($id);

        return Inertia::render('StoreBranch/Edit', [
            'branch' => $branch
        ]);
    }

    public function create()
    {
        return Inertia::render('StoreBranch/Create');
    }

    public function destroy($id)
    {
        $category = StoreBranch::with(['store_orders', 'usage_records', 'users', 'inventory_stock', 'inventory_stock_used'])->findOrFail($id);

        if ($category->store_orders->count() > 0 || $category->usage_records->count() > 0 || $category->users->count()) {
            return back()->withErrors([
                'message' => "Can't delete this store branch because there are data associated with it."
            ]);
        }

        $category->delete();
        return to_route('store-branches.index');
    }

    public function store(Request $request)
    {

        $validated = $request->validate([
            'branch_code' => ['required', 'unique:store_branches,branch_code'],
            'name' => ['required', 'unique:store_branches,name'],
            'brand_name' => ['nullable'],
            'brand_code' => ['nullable', 'unique:store_branches,brand_code'],
            'location_code' => ['nullable', 'unique:store_branches,location_code'],
            'store_status' => ['required'],
            'tin' => ['nullable'],
            'complete_address' => ['nullable'],
            'head_chef' => ['nullable'],
            'director_operations' => ['nullable'],
            'vp_operations' => ['nullable'],
            'store_representative' => ['nullable'],
            'aom' => ['nullable'],
            'point_of_contact' => ['nullable'],
            'contact_number' => ['nullable'],
            'is_active' => ['nullable'],
        ]);

        StoreBranch::create($validated);
        return to_route("store-branches.index");
    }

    public function update(Request $request, $id)
    {
        $branch = StoreBranch::findOrFail($id);
        $validated = $request->validate([
            'branch_code' => ['required', 'unique:store_branches,branch_code,' . $id],
            'name' => ['required', 'unique:store_branches,name,' . $id],
            'store_status' => ['required']
        ]);
        $branch->update($validated);
        return to_route("store-branches.index");
    }
}
