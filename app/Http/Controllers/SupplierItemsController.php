<?php

namespace App\Http\Controllers;

use App\Exports\SupplierItemsExport;
use App\Imports\SupplierItemsImport; // Make sure this is imported
use App\Models\SupplierItems;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Auth;
use App\Models\User; // Ensure User model is imported
use App\Models\Supplier; // Ensure Supplier model is imported
use App\Models\SAPMasterfile; // Ensure SAPMasterfile is imported

class SupplierItemsController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        if (!$user) {
            return redirect('/login')->with('error', 'Please log in to view supplier items.');
        }

        // Get the SupplierCodes assigned to the current user using the existing relationship
        $assignedSupplierCodes = $user->suppliers->pluck('supplier_code')->toArray();

        // If the user has no assigned suppliers, return an empty list
        if (empty($assignedSupplierCodes)) {
            Log::info('SupplierItemsController: No assigned supplier codes for user ' . $user->id);
            return Inertia::render('SupplierItems/Index', [
                'items' => SupplierItems::whereRaw('1 = 0')->paginate(10), // Return empty pagination
                'filters' => request()->only(['search', 'filter']),
                'assignedSupplierCodes' => $assignedSupplierCodes, // Pass to frontend
            ])->with('info', 'You have no assigned suppliers.');
        }

        $search = request('search');
        $filter = request('filter');

        $query = SupplierItems::query()
            // Eager load the sapMasterfiles (plural) relationship.
            // The accessor `sap_masterfile` will then filter this loaded collection.
            ->with(['sapMasterfiles' => function($q) {
                // IMPORTANT FIX: Include 'BaseQty' in the select statement
                $q->select('id', 'ItemCode', 'BaseUOM', 'AltUOM', 'BaseQty');
            }])
            // Filter SupplierItems to only include those assigned to the current user
            ->whereIn('SupplierCode', $assignedSupplierCodes);

        if ($filter === 'inactive') {
            $query->where('is_active', '=', 0);
        }

        if ($filter === 'is_active') {
            $query->where('is_active', '=', 1);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('ItemCode', 'like', "%$search%")
                  ->orWhere('item_name', 'like', "%$search%")
                  ->orWhere('SupplierCode', 'like', "%$search%")
                  ->orWhere('category', 'like', "%$search%")
                  ->orWhere('brand', 'like', "%$search%")
                  ->orWhere('classification', 'like', "%$search%")
                  ->orWhere('packaging_config', 'like', "%$search%")
                  ->orWhere('uom', 'like', "%$search%");
            });
        }

        Log::info('SupplierItemsController: Before pagination - Query SQL: ' . $query->toSql());
        Log::info('SupplierItemsController: Before pagination - Query Bindings: ' . json_encode($query->getBindings()));

        $items = $query->latest()->paginate(10)->withQueryString();

        // Log the items collection before transformation
        Log::info('SupplierItemsController: Items fetched from DB (before transform):', ['count' => $items->count(), 'data_sample' => $items->getCollection()->take(2)->toArray()]);


        // Explicitly transform the collection to force accessor execution and attach BaseUOM
        $items->getCollection()->transform(function ($item) {
            // Access the accessor to get the matching SAPMasterfile model
            $sapMasterfile = $item->sap_masterfile;

            // Attach the BaseUOM to a new property on the item for frontend access
            $item->base_uom_display = $sapMasterfile ? $sapMasterfile->BaseUOM : null;
            $item->category_2 = $item->category2;

            // Calculate and attach actual cost using BaseQty * Cost
            $baseQty = $sapMasterfile ? $sapMasterfile->BaseQTY : 1; // Default to 1 if no BaseQty
            $cost = $item->cost ?? 0;
            $item->actual_cost = ($baseQty * $cost);

            // Also attach the base_qty for potential frontend use
            $item->base_qty = $sapMasterfile ? $sapMasterfile->BaseQTY : null;

            // Optionally, unset the 'sap_masterfiles' relationship to reduce payload size
            // if only 'base_uom_display' is needed in the frontend.
            unset($item->sapMasterfiles);

            return $item;
        });

        // Log the items collection after transformation (and accessor execution)
        Log::info('SupplierItemsController: Items after transform (accessor should have run and base_uom_display attached):', ['count' => $items->count(), 'data_sample' => $items->getCollection()->take(2)->toArray()]);


        return Inertia::render('SupplierItems/Index', [
            'items' => $items,
            'filters' => request()->only(['search', 'filter']),
            'assignedSupplierCodes' => $assignedSupplierCodes, // Pass to frontend
        ])->with('success', true);
    }

    // The 'create' method is removed as per the requirement that users only manage assigned items.
    // If you need a form for creating new SupplierItems, it should be restricted to admin roles.
    // public function create()
    // {
    //     return Inertia::render('SupplierItems/Create', []);
    // }

    public function export()
    {
        $user = Auth::user();
        if (!$user) {
            return redirect('/login')->with('error', 'Please log in to export supplier items.');
        }
        // Pluck 'supplier_code' from the related Supplier models
        $assignedSupplierCodes = $user->suppliers->pluck('supplier_code')->toArray();

        // If the user has no assigned suppliers, return an empty export or error
        if (empty($assignedSupplierCodes)) {
             return back()->with('error', 'You have no assigned suppliers to export items from.');
        }

        $search = request('search');
        $filter = request('filter');

        // Pass assignedSupplierCodes to the export class
        return Excel::download(
            new SupplierItemsExport($search, $filter, $assignedSupplierCodes),
            'SupplierItems-list-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function edit($id)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect('/login')->with('error', 'Please log in to edit supplier items.');
        }

        $item = SupplierItems::findOrFail($id);

        // Authorization check: Ensure the user is assigned to this supplier item's SupplierCode
        // Pluck 'supplier_code' from the related Supplier models
        $assignedSupplierCodes = $user->suppliers->pluck('supplier_code')->toArray();
        if (!in_array($item->SupplierCode, $assignedSupplierCodes)) {
            return back()->with('error', 'You are not authorized to edit this supplier item.');
        }

        return Inertia::render('SupplierItems/Edit', [
            'item' => $item
        ]);
    }

    public function show($id)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect('/login')->with('error', 'Please log in to view supplier items.');
        }

        $item = SupplierItems::findOrFail($id);

        // Authorization check: Ensure the user is assigned to this supplier item's SupplierCode
        // Pluck 'supplier_code' from the related Supplier models
        $assignedSupplierCodes = $user->suppliers->pluck('supplier_code')->toArray();
        if (!in_array($item->SupplierCode, $assignedSupplierCodes)) {
            return back()->with('error', 'You are not authorized to view this supplier item.');
        }

        return Inertia::render('SupplierItems/Show', [
            'item' => $item
        ]);
    }

    public function destroy($id)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect('/login')->with('error', 'Please log in to delete supplier items.');
        }

        $item = SupplierItems::findOrFail($id);

        // Authorization check: Ensure the user is assigned to this supplier item's SupplierCode
        // Pluck 'supplier_code' from the related Supplier models
        $assignedSupplierCodes = $user->suppliers->pluck('supplier_code')->toArray();
        if (!in_array($item->SupplierCode, $assignedSupplierCodes)) {
            return back()->with('error', 'You are not authorized to delete this supplier item.');
        }

        $item->delete();
        return to_route('SupplierItems.index')->with('success', 'Supplier item deleted successfully.');
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect('/login')->with('error', 'Please log in to update supplier items.');
        }

        $item = SupplierItems::findOrFail($id);

        // Authorization check: Ensure the user is assigned to this supplier item's SupplierCode
        // Pluck 'supplier_code' from the related Supplier models
        $assignedSupplierCodes = $user->suppliers->pluck('supplier_code')->toArray();
        if (!in_array($item->SupplierCode, $assignedSupplierCodes)) {
            return back()->with('error', 'You are not authorized to update this supplier item.');
        }

        // Trim values from the request before validation
        $request->merge([
            'ItemCode' => trim($request->input('ItemCode')),
            'item_name' => trim($request->input('item_name') ?? ''), // Re-included item_name
            'SupplierCode' => trim($request->input('SupplierCode')),
            'category' => trim($request->input('category') ?? ''),
            'brand' => trim($request->input('brand') ?? ''),
            'classification' => trim($request->input('classification') ?? ''),
            'packaging_config' => trim($request->input('packaging_config') ?? ''),
            'uom' => trim($request->input('uom') ?? ''),
        ]);

        $validated = $request->validate([ 
           'ItemCode' => ['nullable', 'string', 'max:255'],
           'SupplierCode' => ['nullable', 'string', 'max:255'],
           'category' => ['nullable', 'string', 'max:255'],
           'brand' => ['nullable', 'string', 'max:255'],
           'classification' => ['nullable', 'string', 'max:255'],
           'packaging_config' => ['nullable', 'string', 'max:255'],
           'config' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
           'uom' => ['nullable', 'string', 'max:255'],
           'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
           'srp' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
           'is_active' => ['nullable', 'boolean'],
           'item_name' => ['nullable', 'string', 'max:255'], // Re-included item_name
        ]);

        $item->update($validated);
        return to_route("SupplierItems.index")->with('success', 'Supplier item updated successfully.');
    }

    public function import(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect('/login')->with('error', 'Please log in to import supplier items.');
        }
        
        $assignedSupplierCodes = $user->suppliers->pluck('supplier_code')->toArray();

        if (empty($assignedSupplierCodes)) {
            return back()->with('error', 'You have no assigned suppliers, so you cannot import items.');
        }

        set_time_limit(10000000000); 
        
        $request->validate([
            'products_file' => 'required|mimes:xlsx,xls,csv'
        ]);

        try {
            // Pass the assignedSupplierCodes to the import class
            $import = new SupplierItemsImport($assignedSupplierCodes);
            Excel::import($import, $request->file('products_file'));

            $processedCount = $import->getProcessedCount();
            $skippedEmptyKeysCount = $import->getSkippedEmptyKeysCount();
            $skippedBySapValidationCount = $import->getSkippedBySapValidationCount();
            $skippedUnauthorizedCount = $import->getSkippedUnauthorizedCount();
            $skippedDetails = $import->getSkippedDetails();

            session()->flash('import_summary', [
                'success_message' => 'Import successful!',
                'processed_count' => $processedCount,
                'skipped_empty_keys_count' => $skippedEmptyKeysCount,
                'skipped_sap_validation_count' => $skippedBySapValidationCount,
                'skipped_unauthorized_count' => $skippedUnauthorizedCount,
                'skipped_details_present' => !empty($skippedDetails),
            ]);

            // Store skipped details in a session flash for download (or you could store in Storage here)
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

    public function downloadSkippedImportLog(Request $request)
    {
        // Retrieve skipped details from session
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

        return Response::make($content, 200, [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    public function getDetailsByItemCodeAndSupplierCode($itemCode, $supplierCode)
    {
        // Eager load sapMasterfiles (plural) so the accessor can filter it
        $item = SupplierItems::where('ItemCode', $itemCode)
                            ->where('SupplierCode', $supplierCode)
                            ->with(['sapMasterfiles' => function($q) {
                                // IMPORTANT FIX: Include 'BaseQty' in the select statement
                                $q->select('id', 'ItemCode', 'BaseUOM', 'AltUOM', 'BaseQty');
                            }])
                            ->first();

        if (!$item) {
            return response()->json(['message' => 'Item not found.'], 404);
        }

        // The BaseUOM will now be directly accessible via $item->sap_masterfile->BaseUOM
        // because the accessor will find the correct related SAPMasterfile from the eager loaded collection.
        return response()->json(['item' => $item]);
    }
}
