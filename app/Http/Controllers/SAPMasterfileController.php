<?php

namespace App\Http\Controllers;

use App\Exports\SAPMasterfileExport;
use App\Imports\SAPMasterfileImport;
use App\Models\SAPMasterfile;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;

class SAPMasterfileController extends Controller
{
    //
    
    public function index()
    {
        $search = request('search');
        $filter = request('filter');

        $query = SAPMasterfile::query();
        // ->with(['inventory_category', 'unit_of_measurement', 'product_categories']);

        if ($filter === 'inactive')
            $query->where('is_active', '=', 0);

        if ($filter === 'is_active')
            $query->where('is_active', '=', 1);

        if ($search)
            $query->whereAny(['ItemNo', 'ItemDescription'], 'like', "%$search%");

        $items = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('SAPMasterfileItem/Index', [
            'items' => $items,
            'filters' => request()->only(['search', 'filter'])
        ])->with('success', true);
    }

    public function create()
    {
        return Inertia::render('SAPMasterfileItem/Create', [
        ]);
    }

    public function export()
    {
        $search = request('search');
        $filter = request('filter');

        return Excel::download(
            new SAPMasterfileExport($search, $filter),
            'sapitems-list-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function edit($id)
    {
        $item = SAPMasterfile::findOrFail($id);
        return Inertia::render('SAPMasterfileItem/Edit', [
            'item' => $item
        ]);
    }

    public function show($id)
    {
        $items = SAPMasterfile::findOrFail($id);
        return Inertia::render('SAPMasterfileItem/Show', [
            'item' => $items
        ]);
    }

    public function store(Request $request)
    {

        $validated = $request->validate([
            'ItemNo' => ['nullable'],
            'ItemDescription' => ['nullable'],
            'AltQty' => ['nullable'],
            'BaseQty' => ['nullable'],
            'AltUOM' => ['nullable'],
            'BaseUOM' => ['required'],
            'is_active' => ['nullable'],
        ]);

        SAPMasterfile::create($validated);
        return to_route("items.index");
    }

    public function destroy($id)
    {
        $items = SAPMasterfile::findOrFail($id);

        $items->delete();
        return to_route('items.index');
    }

    public function update(Request $request, $id)
    {
        $item = SAPMasterfile::findOrFail($id);
        $validated = $request->validate([         
           'ItemNo' => ['nullable'],
            'ItemDescription' => ['nullable'],
            'AltQty' => ['nullable'],
            'BaseQty' => ['nullable'],
            'AltUOM' => ['nullable'],
            'BaseUOM' => ['required'],
            'is_active' => ['nullable'],
        ]);
        $item->update($validated);
        return to_route("items.index");
    }

    public function import(Request $request)
    {
        set_time_limit(10000000000); // Be cautious with such high limits in production
        $request->validate([
            'products_file' => 'required|mimes:xlsx,xls,csv'
        ]);

        try {
            Excel::import(new SAPMasterfileImport, $request->file('products_file'));
            return redirect()->route('items.index')->with('success', 'Import successful');
        } catch (\Exception $e) {
            // Log the exact error for debugging
            Log::error('SAPMasterfile Import Error: ' . $e->getMessage(), [
                'file_name' => $request->file('products_file')->getClientOriginalName(),
                'trace' => $e->getTraceAsString(), // Get the full stack trace
            ]);

            // Return to the previous page with a more specific error message if possible
            return back()->with('error', 'Import failed: ' . $e->getMessage() . '. Please check logs for details.');
        }
    }
}
