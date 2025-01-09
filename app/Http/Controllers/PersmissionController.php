<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;

class PersmissionController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = Permission::query();

        if ($search)
            $query->where('name', 'like', "%$search%");
        $permissions = $query->latest()->paginate(10);

        return Inertia::render('Permission/Index', [
            'permissions' => $permissions,
            'filters' => request()->only(['search'])
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required'],
        ]);
        Permission::create(['name' => $validated['name']]);

        return redirect()->route('permissions.index');
    }


    public function edit()
    {
        return Inertia::render('Permission/Edit');
    }

    public function create()
    {
        return Inertia::render('Permission/Create');
    }
}
