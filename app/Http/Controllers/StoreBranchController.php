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
            $query->where('name', 'like', "%$search%");

        $branches = $query->paginate(10);
        return Inertia::render('StoreBranch/Index', [
            'data' => $branches,
            'filters' => request()->only(['search'])
        ]);
    }

    public function show($id)
    {

        $branch = StoreBranch::findOrFail($id);
        return Inertia::render('StoreBranch/Edit', [
            'branch' => $branch
        ]);
    }
}
