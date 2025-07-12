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

        if ($search) {
            // Update search to use 'ItemCode' and potentially new string columns
            $query->whereAny([
                'ItemCode',        // Renamed from ItemNo
                'item_name',
                'SupplierCode',
                'category',        // New
                'brand',           // New
                'classification',  // New
                'packaging_config',// New
                'uom'              // New
            ], 'like', "%$search%");
        }

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

        // Trim values from the request before validation
        $request->merge([
            'ItemCode' => trim($request->input('ItemCode')), // Renamed from ItemNo
            'item_name' => trim($request->input('item_name')), 
            'SupplierCode' => trim($request->input('SupplierCode')),
            'category' => trim($request->input('category') ?? ''),
            'brand' => trim($request->input('brand') ?? ''),
            'classification' => trim($request->input('classification') ?? ''),
            'packaging_config' => trim($request->input('packaging_config') ?? ''),
            'uom' => trim($request->input('uom') ?? ''),
        ]);

        $validated = $request->validate([         
           'ItemCode' => ['nullable', 'string', 'max:255'], // Renamed from ItemNo
            'SupplierCode' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],        // New
            'brand' => ['nullable', 'string', 'max:255'],           // New
            'classification' => ['nullable', 'string', 'max:255'],  // New
            'packaging_config' => ['nullable', 'string', 'max:255'],// New
            'config' => ['nullable', 'numeric', 'min:0', 'max:999999.99'], // Max based on decimal(8,2)
            'uom' => ['nullable', 'string', 'max:255'],             // New
            'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'], // Max based on decimal(10,2)
            'srp' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],  // Max based on decimal(10,2)
            'is_active' => ['nullable', 'boolean'],
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
