<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class RolesController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = Role::query();

        if ($search)
            $query->where('name', 'like', "%$search%");
        $roles = $query->paginate(10);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'filters' => request()->only(['search'])
        ]);
    }


    public function edit()
    {
        return Inertia::render('Roles/Edit');
    }

    public function create()
    {
        return Inertia::render('Roles/Create');
    }
}
