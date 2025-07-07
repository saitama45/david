<?php

namespace App\Http\Controllers;

use App\Exports\SupplierItemsExport;
use App\Imports\SupplierItemsImport;
use App\Models\SupplierItems;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;

class SupplierItemsController extends Controller
{
    //
    
    public function index()
    {
        $search = request('search');
        $filter = request('filter');

        $query = SupplierItems::query();
        // ->with(['inventory_category', 'unit_of_measurement', 'product_categories']);

        if ($filter === 'inactive')
            $query->where('is_active', '=', 0);

        if ($filter === 'is_active')
            $query->where('is_active', '=', 1);

        if ($search)
            $query->whereAny(['ItemNo', 'SupplierCode'], 'like', "%$search%");

        $items = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('SupplierItems/Index', [
            'items' => $items,
            'filters' => request()->only(['search', 'filter'])
        ])->with('success', true);
    }

    public function create()
    {
        return Inertia::render('SupplierItems/Create', [
        ]);
    }

    public function export()
    {
        $search = request('search');
        $filter = request('filter');

        return Excel::download(
            new SupplierItemsExport($search, $filter),
            'SupplierItems-list-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function edit($id)
    {
        $item = SupplierItems::findOrFail($id);
        return Inertia::render('SupplierItems/Edit', [
            'item' => $item
        ]);
    }

    public function show($id)
    {
        $items = SupplierItems::findOrFail($id);
        return Inertia::render('SupplierItems/Show', [
            'item' => $items
        ]);
    }

    public function destroy($id)
    {
        $items = SupplierItems::findOrFail($id);

        $items->delete();
        return to_route('SupplierItems.index');
    }

    public function update(Request $request, $id)
    {
        $item = SupplierItems::findOrFail($id);
        $validated = $request->validate([         
           'ItemNo' => ['nullable'],
            'SupplierCode' => ['nullable'],
            'is_active' => ['nullable'],
        ]);
        $item->update($validated);
        return to_route("SupplierItems.index");
    }

    public function import(Request $request)
    {
        set_time_limit(10000000000); // Be cautious with such high limits in production
        $request->validate([
            'products_file' => 'required|mimes:xlsx,xls,csv'
        ]);

        try {
            Excel::import(new SupplierItemsImport, $request->file('products_file'));
            return redirect()->route('SupplierItems.index')->with('success', 'Import successful');
        } catch (\Exception $e) {
            // Log the exact error for debugging
            Log::error('SupplierItems Import Error: ' . $e->getMessage(), [
                'file_name' => $request->file('products_file')->getClientOriginalName(),
                'trace' => $e->getTraceAsString(), // Get the full stack trace
            ]);

            // Return to the previous page with a more specific error message if possible
            return back()->with('error', 'Import failed: ' . $e->getMessage() . '. Please check logs for details.');
        }
    }
}
