<?php

namespace App\Http\Controllers;

use App\Exports\POSMasterfileExport;
use App\Http\Controllers\Controller;
use App\Imports\POSMasterfileImport;
use App\Models\POSMasterfile;
use App\Models\MenuCategory;
use App\Models\SAPMasterfile;
use App\Models\POSMasterfileBOM; // Import POSMasterfileBOM
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
            $query->whereAny(['POSCode', 'POSDescription', 'POSName'], 'like', "%$search%"); // Include POSName in search

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
            'POSCode' => ['nullable'],
            'POSDescription' => ['nullable'], // Corrected: Validating POSDescription
            'Category' => ['nullable'],
            'SubCategory' => ['nullable'],
            'SRP' => ['nullable'],
            'is_active' => ['nullable'],
        ]);

        POSMasterfile::create($validated);
        return to_route("POSMasterfile.index");
    }

    /**
     * Display the specified resource.
     * This method now also fetches and passes the related BOM ingredients.
     */
    public function show(string $id)
    {
        $item = POSMasterfile::findOrFail($id);

        // Get BOM rows directly from pos_masterfiles_bom (one row per BOM entry).
        $bomRows = POSMasterfileBOM::where('POSCode', $item->POSCode)
            ->orderBy('id')
            ->get();

        // Map each BOM row to the structure the Vue page expects for display.
        $existingIngredients = $bomRows->map(function ($bom) {
            // Find corresponding SAP product (if exists) by ItemCode
            $sap = SAPMasterfile::where('ItemCode', $bom->ItemCode)->first();

            return [
                'id' => $bom->id, // BOM primary key (one-to-one with the BOM row)
                'assembly' => $bom->Assembly,
                'sap_masterfile_id' => $sap ? $sap->id : null, // link to SAP product if found
                'inventory_code' => $bom->ItemCode,
                'name' => $bom->ItemDescription, // Correct: This is the ingredient's description from BOM
                'quantity' => $bom->BOMQty,
                'uom' => $bom->BOMUOM,
                'unit_cost' => $bom->UnitCost,
                'total_cost' => $bom->TotalCost,
            ];
        })->values()->all();

        return Inertia::render('POSMasterfile/Show', [
            'item' => $item,
            'existingIngredients' => $existingIngredients, // Pass the fetched ingredients
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * IMPORTANT: This version reads BOM rows from pos_masterfiles_bom first (no aggregate join),
     * then attaches SAP product info per BOM row so each BOM row maps 1:1 to the frontend.
     */
    public function edit(string $id)
    {
        $item = POSMasterfile::findOrFail($id);

        // Fetch menu categories for the dropdown
        $categories = MenuCategory::options();

        // Fetch products from SAPMasterfile for the ingredients dropdown
        $products = SAPMasterfile::options();

        // Get BOM rows directly from pos_masterfiles_bom (one row per BOM entry).
        $bomRows = POSMasterfileBOM::where('POSCode', $item->POSCode)
            ->orderBy('id')
            ->get();

        // Map each BOM row to the structure the Vue page expects.
        $existingIngredients = $bomRows->map(function ($bom) {
            // Find corresponding SAP product (if exists) by ItemCode
            $sap = SAPMasterfile::where('ItemCode', $bom->ItemCode)->first();

            return [
                'id' => $bom->id, // BOM primary key (one-to-one with the BOM row)
                'assembly' => $bom->Assembly,
                'sap_masterfile_id' => $sap ? $sap->id : null, // link to SAP product if found
                'inventory_code' => $bom->ItemCode,
                'name' => $bom->ItemDescription, // Correct: This is the ingredient's description from BOM
                'quantity' => $bom->BOMQty,
                'uom' => $bom->BOMUOM,
                'unit_cost' => $bom->UnitCost,
                'total_cost' => $bom->TotalCost,
            ];
        })->values()->all();

        return Inertia::render('POSMasterfile/Edit', [
            'item' => $item,
            'categories' => $categories,
            'products' => $products,
            'existingIngredients' => $existingIngredients,
        ]);
    }

    /**
     * Update the specified resource in storage.
     * This method now ONLY updates the main POSMasterfile details.
     * It does NOT expect or process ingredient list data.
     */
    public function update(Request $request, string $id)
    {
        $item = POSMasterfile::findOrFail($id);

        $validated = $request->validate([
            'POSCode' => ['nullable'],
            'POSDescription' => ['nullable'], // Corrected: Validating POSDescription
            'Category' => ['nullable'],
            'SubCategory' => ['nullable'],
            'SRP' => ['nullable'],
            'is_active' => ['nullable'],
            // Removed 'ingredients' validation as it's no longer updated here.
        ]);

        // Update the main POSMasterfile item
        $item->update($validated);

        // Removed all BOM update logic (DB::beginTransaction, delete, create/update loops)
        // as ingredient updates are no longer handled by this method.

        return to_route("POSMasterfile.index")->with('success', 'Product FG Details successfully updated!');
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

    /**
     * Import method
     */
    public function import(Request $request)
    {
        set_time_limit(0);
        Log::debug('POSMasterfileController: Import method started.');

        $request->validate([
            'products_file' => 'required|mimes:xlsx,xls,csv'
        ]);

        POSMasterfileImport::resetSeenCombinations();

        try {
            $import = new POSMasterfileImport();
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

            return redirect()->route('POSMasterfile.index');

        } catch (\Exception $e) {
            Log::error('POSMasterfile Import Error: ' . $e->getMessage(), [
                'file_name' => $request->file('products_file')->getClientOriginalName(),
                'trace' => $e->getTraceAsString(),
            ]);

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