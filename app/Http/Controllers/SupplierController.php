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

        $suppliers = $query->latest()->paginate(10)->withQueryString();
        return Inertia::render('Supplier/Index', [
            'data' => $suppliers,
            'filters' => request()->only(['search'])
        ]);
    }

    public function edit($id)
    {
        $supplier = Supplier::findOrFail($id);
        return Inertia::render('Supplier/Edit', [
            'supplier' => $supplier
        ]);
    }

    public function destroy($id)
    {
        $category = Supplier::with('store_orders')->findOrFail($id);

        if ($category->store_orders->count() > 0) {
            return back()->withErrors([
                'message' => "Can't delete this supplier because there are data associated with it."
            ]);
        }

        $category->delete();
        return to_route('suppliers.index');
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => ['required'],
            'supplier_code' => ['required'],
            'remarks' => ['nullable']
        ]);
        $supplier = Supplier::findOrFail($id);
        $supplier->update($validated);
        return redirect()->route('suppliers.index');
    }

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
