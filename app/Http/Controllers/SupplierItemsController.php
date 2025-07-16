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
use Illuminate\Support\Facades\Response;

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
            $import = new SupplierItemsImport();
            Excel::import($import, $request->file('products_file'));

            // Get skipped details and counts from the import instance
            $processedCount = $import->getProcessedCount();
            $skippedEmptyKeysCount = $import->getSkippedEmptyKeysCount();
            $skippedBySapValidationCount = $import->getSkippedBySapValidationCount();
            $skippedDetails = $import->getSkippedDetails();

            // Store skipped details in session flash for the next request (for Inertia)
            session()->flash('import_summary', [
                'success_message' => 'Import successful!',
                'processed_count' => $processedCount,
                'skipped_empty_keys_count' => $skippedEmptyKeysCount,
                'skipped_sap_validation_count' => $skippedBySapValidationCount,
                'skipped_details_present' => !empty($skippedDetails), // Indicate if details exist
            ]);

            // Store actual skipped details in session for potential download later
            // IMPORTANT: Be mindful of session size for very large number of skipped details.
            // If details are too large, consider storing in cache or temporary file.
            session(['last_import_skipped_details' => $skippedDetails]);

            return redirect()->route('SupplierItems.index');

        } catch (\Exception $e) {
            Log::error('SupplierItems Import Error: ' . $e->getMessage(), [
                'file_name' => $request->file('products_file')->getClientOriginalName(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Import failed: ' . $e->getMessage() . '. Please check logs for details.');
        }
    }

    // NEW METHOD: For downloading skipped details
    public function downloadSkippedImportLog(Request $request)
    {
        $skippedDetails = session('last_import_skipped_details', []);

        if (empty($skippedDetails)) {
            return back()->with('error', 'No skipped import details found to download.');
        }

        $fileName = 'skipped_supplier_items_log_' . now()->format('Y-m-d_His') . '.txt';
        $content = "Skipped Supplier Items Import Log\n";
        $content .= "Generated on: " . now()->toDateTimeString() . "\n\n";

        foreach ($skippedDetails as $index => $detail) {
            $content .= "--- Skipped Row " . ($index + 1) . " ---\n";
            $content .= "Reason: " . ($detail['reason'] ?? 'N/A') . "\n";
            if (!empty($detail['details'])) {
                $content .= "Specific Details: " . json_encode($detail['details'], JSON_PRETTY_PRINT) . "\n";
            }
            if (!empty($detail['original_row'])) {
                $content .= "Original Row Data: " . json_encode($detail['original_row'], JSON_PRETTY_PRINT) . "\n";
            }
            $content .= "\n";
        }

        // Return as a downloadable text file
        return Response::make($content, 200, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    public function getDetailsJson(SupplierItems $supplierItem) // Use route model binding directly
    {

        // Eager load the sapMasterfile relationship
        // This will make the related SAPMasterfile data available on the $supplierItem object
        $supplierItem->load('sapMasterfile');

        // Now, when $supplierItem is returned as JSON, it will include the sap_masterfile relationship
        // and its attributes, including 'BaseUOM'.
        return response()->json($supplierItem);
    }
}
