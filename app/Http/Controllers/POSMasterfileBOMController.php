<?php

namespace App\Http\Controllers;

use App\Models\POSMasterfileBOM;
use App\Models\User; // Assuming User model is needed for created_by/updated_by
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Exception;
use Illuminate\Support\Facades\Response; // Import Response facade

use App\Imports\POSMasterfileBOMImport; // Import the new import class
use App\Exports\POSMasterfileBOMExport; // Import the new export class

class POSMasterfileBOMController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect('/login')->with('error', 'Please log in to view POS BOMs.');
        }

        $search = $request->input('search');
        $query = POSMasterfileBOM::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('POSCode', 'like', "%{$search}%")
                    ->orWhere('POSDescription', 'like', "%{$search}%")
                    ->orWhere('ItemCode', 'like', "%{$search}%")
                    ->orWhere('ItemDescription', 'like', "%{$search}%")
                    ->orWhere('RecipeUOM', 'like', "%{$search}%")
                    ->orWhere('BOMUOM', 'like', "%{$search}%");
            });
        }

        $boms = $query->latest()->paginate(10);

        return Inertia::render('POSMasterfileBOM/Index', [
            'boms' => $boms,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // You might pass dropdown options here, e.g., POS items, UOMs
        return Inertia::render('POSMasterfileBOM/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) // Replace Request with StorePOSMasterfileBOMRequest for validation
    {
        $user = Auth::user();
        if (!$user) {
            return back()->with('error', 'Authentication required to store POS BOM.');
        }

        // Basic validation - replace with a Form Request for complex validation
        $validatedData = $request->validate([
            'POSCode' => 'required|string|max:255',
            'POSDescription' => 'nullable|string|max:255',
            'Assembly' => 'nullable|string|max:255',
            'ItemCode' => 'required|string|max:255',
            'ItemDescription' => 'nullable|string|max:255',
            'RecPercent' => 'nullable|numeric',
            'RecipeQty' => 'nullable|numeric',
            'RecipeUOM' => 'nullable|string|max:50',
            'BOMQty' => 'nullable|numeric',
            'BOMUOM' => 'nullable|string|max:50',
            'UnitCost' => 'nullable|numeric',
            'TotalCost' => 'nullable|numeric',
        ]);

        try {
            POSMasterfileBOM::create(array_merge($validatedData, [
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]));

            return redirect()->route('pos-bom.index')->with('success', 'POS BOM created successfully.');
        } catch (Exception $e) {
            Log::error("Error creating POS BOM: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to create POS BOM: ' . $e->getMessage()]);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(POSMasterfileBOM $posBom) // Using route model binding
    {
        return Inertia::render('POSMasterfileBOM/Show', [
            'bom' => $posBom->load(['creator', 'updater']), // Load related users
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(POSMasterfileBOM $posBom) // Using route model binding
    {
        return Inertia::render('POSMasterfileBOM/Edit', [
            'bom' => $posBom,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, POSMasterfileBOM $posBom) // Replace Request with UpdatePOSMasterfileBOMRequest for validation
    {
        $user = Auth::user();
        if (!$user) {
            return back()->with('error', 'Authentication required to update POS BOM.');
        }

        // Basic validation - replace with a Form Request for complex validation
        $validatedData = $request->validate([
            'POSCode' => 'required|string|max:255',
            'POSDescription' => 'nullable|string|max:255',
            'Assembly' => 'nullable|string|max:255',
            'ItemCode' => 'required|string|max:255',
            'ItemDescription' => 'nullable|string|max:255',
            'RecPercent' => 'nullable|numeric',
            'RecipeQty' => 'nullable|numeric',
            'RecipeUOM' => 'nullable|string|max:50',
            'BOMQty' => 'nullable|numeric',
            'BOMUOM' => 'nullable|string|max:50',
            'UnitCost' => 'nullable|numeric',
            'TotalCost' => 'nullable|numeric',
        ]);

        try {
            $posBom->update(array_merge($validatedData, [
                'updated_by' => $user->id,
            ]));

            return redirect()->route('pos-bom.index')->with('success', 'POS BOM updated successfully.');
        } catch (Exception $e) {
            Log::error("Error updating POS BOM: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to update POS BOM: ' . $e->getMessage()]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(POSMasterfileBOM $posBom) // Using route model binding
    {
        $user = Auth::user();
        if (!$user) {
            return back()->with('error', 'Authentication required to delete POS BOM.');
        }

        try {
            $posBom->delete();
            return redirect()->route('pos-bom.index')->with('success', 'POS BOM deleted successfully.');
        } catch (Exception $e) {
            Log::error("Error deleting POS BOM: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to delete POS BOM: ' . $e->getMessage()]);
        }
    }

    /**
     * Export POS Masterfile BOM data.
     */
    public function export(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect('/login')->with('error', 'Please log in to export POS BOMs.');
        }

        $search = $request->input('search');
        $filter = $request->input('filter');

        // CRITICAL FIX: Load creator and updater relationships for the export
        return Excel::download(
            new POSMasterfileBOMExport($search, $filter),
            'pos_masterfile_bom_list-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    /**
     * Import POS Masterfile BOM data.
     */
    public function import(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return redirect('/login')->with('error', 'Please log in to import POS BOMs.');
        }

        $request->validate([
            'pos_bom_file' => 'required|mimes:xlsx,xls,csv',
        ]);

        try {
            // Use the POSMasterfileBOMImport class
            $import = new POSMasterfileBOMImport();
            Excel::import($import, $request->file('pos_bom_file'));

            // Retrieve import summary details
            $processedCount = $import->getProcessedCount();
            $skippedEmptyKeysCount = $import->getSkippedEmptyKeysCount();
            $skippedByPosMasterfileValidationCount = $import->getSkippedByPosMasterfileValidationCount();
            $skippedBySapMasterfileValidationCount = $import->getSkippedBySapMasterfileValidationCount();
            $skippedDetails = $import->getSkippedDetails();

            // Flash session data for frontend display
            session()->flash('import_summary', [
                'success_message' => 'POS BOM import successful!',
                'processed_count' => $processedCount,
                'skipped_empty_keys_count' => $skippedEmptyKeysCount,
                'skipped_pos_masterfile_validation_count' => $skippedByPosMasterfileValidationCount,
                'skipped_sap_masterfile_validation_count' => $skippedBySapMasterfileValidationCount,
                'skipped_details_present' => !empty($skippedDetails),
            ]);

            // Store skipped details in a session flash for download
            session(['last_import_skipped_details' => $skippedDetails]);

            return redirect()->route('pos-bom.index'); // Redirect to the index page
        } catch (Exception $e) {
            Log::error("Error importing POS BOM: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to import POS BOM: ' . $e->getMessage()]);
        }
    }

    /**
     * Download a log file of skipped import details.
     */
    public function downloadSkippedImportLog(Request $request)
    {
        // Retrieve skipped details from session
        $skippedDetails = session('last_import_skipped_details', []);

        if (empty($skippedDetails)) {
            return back()->with('error', 'No skipped import details found to download.');
        }

        $fileName = 'skipped_pos_bom_log_' . now()->format('Y-m-d_His') . '.txt';
        $content = "Skipped POS Masterfile BOM Import Log\n";
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
}
