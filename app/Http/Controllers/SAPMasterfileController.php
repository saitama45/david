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
           'ItemNo' => ['nullable'],
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
        set_time_limit(300); // 300 seconds (5 minutes). Adjust as needed.

        $request->validate([
            'products_file' => 'required|mimes:xlsx,xls,csv'
        ]);

        // IMPORTANT: Reset the static tracker in the importer class before starting a new import.
        // This ensures that the duplicate checking starts fresh for each import operation.
        SAPMasterfileImport::resetSeenCombinations();

        DB::beginTransaction(); // Start a database transaction

        try {
            // Step 1: Delete all existing records from the table.
            SAPMasterfile::query()->delete();

            // Step 2: Reseed the ID for SQL Server.
            // As per your request, RESEED to 0 means the NEXT ID inserted will be 1.
            DB::statement("DBCC CHECKIDENT('sap_masterfiles', RESEED, 0);");

            // Step 3: Perform the import. The de-duplication logic is now handled internally
            // by SAPMasterfileImport using chunk reading and batch inserts.
            Excel::import(new SAPMasterfileImport, $request->file('products_file'));

            DB::commit(); // Commit the transaction if everything is successful

            return redirect()->route('sapitems.index')->with('success', 'Import successful. Duplicates within the file were skipped. All records updated.');

        } catch (\Exception $e) {
            DB::rollBack(); // Rollback the transaction if any error occurs
            Log::error('SAPMasterfile Import Error: ' . $e->getMessage(), [
                'file_name' => $request->file('products_file')->getClientOriginalName(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Import failed: ' . $e->getMessage() . '. Please check logs for details.');
        }
    }
}
