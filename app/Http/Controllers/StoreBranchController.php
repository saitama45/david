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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_code' => ['required'],
            'name' => ['required'],
            'store_status' => ['required']
        ]);

        StoreBranch::create($validated);
        return to_route("store-branches.index");
    }

    public function update(Request $request, $id)
    {
        $branch = StoreBranch::findOrFail($id);
        $validated = $request->validate([
            'branch_code' => ['required'],
            'name' => ['required'],
            'store_status' => ['required']
        ]);
        $branch->update($validated);
        return to_route("store-branches.index");
    }
}
