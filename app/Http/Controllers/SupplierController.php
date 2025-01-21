<?php

namespace App\Http\Controllers;

use App\Exports\SuppliersExport;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

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

    public function export(Request $request)
    {
        $search = $request->input('search');

        return Excel::download(
            new SuppliersExport($search),
            'suppliers-' . now()->format('Y-m-d') . '.xlsx'
        );
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
            'name' => ['required', 'unique:suppliers,name,' . $id],
            'supplier_code' => ['required', 'unique:suppliers,supplier_code,' . $id],
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
            'name' => ['required', 'unique:suppliers,name'],
            'supplier_code' => ['required', 'unique:suppliers,supplier_code'],
            'remarks' => ['nullable']
        ]);

        Supplier::create($validated);
        return redirect()->route('suppliers.index');
    }
}
