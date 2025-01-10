<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierController extends Controller
{
    public function index()
    {
        $search = request('search');
        $query = Supplier::query();

        if ($search)
            $query->where('name', 'like', "%$search%");

        $suppliers = $query->paginate(10);
        return Inertia::render('Supplier/Index', [
            'data' => $suppliers,
            'filters' => request()->only(['search'])
        ]);
    }

    public function edit()
    {
        return Inertia::render('Supplier/Edit');
    }

    public function update() {}

    public function create()
    {
        return Inertia::render('Supplier/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required'],
            'supplier_code' => ['required'],
            'remarks' => ['nullable']
        ]);

        Supplier::create($validated);
        return redirect()->route('suppliers.index');
    }
}
