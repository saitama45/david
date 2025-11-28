<?php

namespace App\Http\Controllers;

use App\Exports\SAPMasterfileExport;
use App\Imports\SAPMasterfileImport;
use App\Models\SAPMasterfile;
use Illuminate\Http\Request;
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

        if ($filter === 'inactive')
            $query->where('is_active', '=', 0);

        if ($filter === 'is_active')
            $query->where('is_active', '=', 1);

        if ($search)
            $query->whereAny(['ItemCode', 'ItemDescription'], 'like', "%$search%");

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
            'ItemCode' => ['nullable'],
            'ItemDescription' => ['nullable'],
            'AltQty' => ['nullable'],
            'BaseQty' => ['nullable'],
            'AltUOM' => ['nullable'],
            'BaseUOM' => ['required'],
            'is_active' => ['nullable'],
        ]);

        SAPMasterfile::create($validated);
        return to_route("sapitems.index");
    }

    public function destroy($id)
    {
        $items = SAPMasterfile::findOrFail($id);

        $items->delete();
        return to_route('sapitems.index');
    }

    public function update(Request $request, $id)
    {
        $item = SAPMasterfile::findOrFail($id);
        $validated = $request->validate([         
           'ItemCode' => ['nullable'],
            'ItemDescription' => ['nullable'],
            'AltQty' => ['nullable'],
            'BaseQty' => ['nullable'],
            'AltUOM' => ['nullable'],
            'BaseUOM' => ['required'],
            'is_active' => ['nullable'],
        ]);
        $item->update($validated);
        return to_route("sapitems.index");
    }

    public function import(Request $request)
    {
        set_time_limit(0);
        Log::debug('SAPMasterfile Import: Import method started.');

        $request->validate([
            'products_file' => 'required|mimes:xlsx,xls,csv'
        ]);

        SAPMasterfileImport::resetSeenCombinations();

        try {
            $import = new SAPMasterfileImport();
            Excel::import($import, $request->file('products_file'));
            $skippedItems = $import->getSkippedItems();
            $processedCount = $import->getProcessedCount();
            $skippedCount = $import->getSkippedCount();

            if ($processedCount > 0) {
                $message = 'Import successful. Processed ' . $processedCount . ' items.';
                
                if ($skippedCount > 0) {
                    $message .= ' ' . $skippedCount . ' rows were skipped due to validation errors or duplicates.';
                    session()->flash('skippedItems', $skippedItems);
                    session()->flash('warning', $message);
                } else {
                    session()->flash('success', $message);
                }
            } else if ($skippedCount > 0) {
                $message = 'No items were imported. ' . $skippedCount . ' rows were skipped due to validation errors or duplicates.';
                session()->flash('skippedItems', $skippedItems);
                session()->flash('warning', $message);
            } else {
                session()->flash('warning', 'No valid items found in the import file.');
            }

            return redirect()->route('sapitems.index');

        } catch (\Exception $e) {
            Log::error('SAPMasterfile Import Error: ' . $e->getMessage(), [
                'file_name' => $request->file('products_file')->getClientOriginalName(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Import failed: ' . $e->getMessage() . '. Please check logs for details.');
        }
    }
}
