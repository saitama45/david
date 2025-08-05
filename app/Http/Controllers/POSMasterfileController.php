<?php

namespace App\Http\Controllers;

use App\Exports\POSMasterfileExport;
use App\Http\Controllers\Controller;
use App\Imports\POSMasterfileImport;
use App\Models\POSMasterfile;
use App\Models\MenuCategory;
use App\Models\SAPMasterfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;

class POSMasterfileController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $search = request('search');
        $filter = request('filter');

        $query = POSMasterfile::query();

        if ($filter === 'inactive')
            $query->where('is_active', '=', 0);

        if ($filter === 'is_active')
            $query->where('is_active', '=', 1);

        if ($search)
            $query->whereAny(['ItemCode', 'ItemDescription'], 'like', "%$search%");

        $items = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('POSMasterfile/Index', [
            'items' => $items,
            'filters' => request()->only(['search', 'filter'])
        ])->with('success', true);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('POSMasterfile/Create', []);
    }

    public function export()
    {
        $search = request('search');
        $filter = request('filter');

        return Excel::download(
            new POSMasterfileExport($search, $filter),
            'POSMasterfile-list-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'ItemCode' => ['nullable'],
            'ItemDescription' => ['nullable'],
            'is_active' => ['nullable'],
        ]);

        POSMasterfile::create($validated);
        return to_route("POSMasterfile.index");
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $items = POSMasterfile::findOrFail($id);
        return Inertia::render('POSMasterfile/Show', [
            'item' => $items
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $item = POSMasterfile::findOrFail($id);
        // Fetch menu categories for the dropdown
        $categories = MenuCategory::options();
        // Fetch products from SAPMasterfile for the ingredients dropdown
        $products = SAPMasterfile::options();

        return Inertia::render('POSMasterfile/Edit', [
            'item' => $item,
            'categories' => $categories,
            'products' => $products,
            'existingIngredients' => [],
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $item = POSMasterfile::findOrFail($id);
        $validated = $request->validate([
            'ItemCode' => ['nullable'],
            'ItemDescription' => ['nullable'],
            'Category' => ['nullable'],
            'SubCategory' => ['nullable'],
            'SRP' => ['nullable'],
            'is_active' => ['nullable'],
            'ingredients' => ['array', 'nullable'],
            'ingredients.*.id' => ['required', 'exists:sap_masterfiles,id'],
            'ingredients.*.quantity' => ['required', 'numeric', 'min:0.1'],
        ]);
        $item->update($validated);

        return to_route("POSMasterfile.index");
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $items = POSMasterfile::findOrFail($id);

        $items->delete();
        return to_route('POSMasterfile.index');
    }

    public function import(Request $request)
    {
        set_time_limit(300); // 300 seconds (5 minutes). Adjust as needed.
        Log::debug('POSMasterfileController: Import method started.');

        $request->validate([
            'products_file' => 'required|mimes:xlsx,xls,csv'
        ]);

        // IMPORTANT: Reset the static tracker in the importer class before starting a new import.
        POSMasterfileImport::resetSeenCombinations();

        DB::beginTransaction(); // Start a database transaction
        Log::debug('POSMasterfileController: Database transaction started.');

        try {
            // Removed: Step 1: Delete all existing records from the table.
            // Removed: Step 2: Reseed the ID for SQL Server.
            Log::debug('POSMasterfileController: Existing records will be updated/inserted, not deleted.');

            // Step 3: Perform the import.
            $import = new POSMasterfileImport(); // Instantiate the importer
            Excel::import($import, $request->file('products_file'));
            Log::debug('POSMasterfileController: Excel import process initiated.');

            $skippedItems = $import->getSkippedItems(); // Get skipped items
            Log::debug('POSMasterfileController: Skipped items count: ' . count($skippedItems));
            Log::debug('POSMasterfileController: Skipped items: ' . json_encode($skippedItems));

            DB::commit(); // Commit the transaction if everything is successful
            Log::debug('POSMasterfileController: Database transaction committed successfully.');

            $message = 'Import successful.';
            if (!empty($skippedItems)) {
                $message .= ' Some rows were skipped due to validation errors.';
                session()->flash('skippedItems', $skippedItems); // Use session()->flash() directly for skippedItems
            }

            return redirect()->route('POSMasterfile.index')->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack(); // Rollback the transaction if any error occurs
            Log::error('POSMasterfile Import Error: ' . $e->getMessage(), [
                'file_name' => $request->file('products_file')->getClientOriginalName(),
                'trace' => $e->getTraceAsString(),
            ]);
            Log::debug('POSMasterfileController: Database transaction rolled back due to error.');

            return back()->with('error', 'Import failed: ' . $e->getMessage() . '. Please check logs for details.');
        }
    }

    // New method to fetch a single product's details for the ingredient dropdown
    public function getProductDetails(string $id)
    {
        $product = SAPMasterfile::select('id', 'ItemCode', 'ItemDescription', 'BaseUOM', 'AltUOM')
                                 ->findOrFail($id);
        return response()->json([
            'id' => $product->id,
            'inventory_code' => $product->ItemCode,
            'name' => $product->ItemDescription,
            'unit_of_measurement' => $product->BaseUOM,
            'alt_unit_of_measurement' => $product->AltUOM,
        ]);
    }
}
