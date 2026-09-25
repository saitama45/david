<?php

namespace App\Http\Controllers;

use App\Models\POSMasterfileBOM;
use App\Models\User; // Assuming User model is needed for created_by/updated_by
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $filter = $request->input('filter', 'all'); // Get filter with a default

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

        // Apply filter logic
        if ($filter === 'is_active') {
            // Assuming there's an 'is_active' column or similar for active items
            // You'll need to adjust this based on how 'active' is defined for POSMasterfileBOM
            // For now, let's assume a placeholder for 'active'
            // If POSMasterfileBOM has no 'is_active' column, this condition will need re-evaluation
            // For example, if 'active' is determined by some other field's value
            $query->where('is_active', 1); // Placeholder: Adjust if your model has a different way to signify 'active'
        } elseif ($filter === 'inactive') {
            $query->where('is_active', 0); // Placeholder: Adjust if your model has a different way to signify 'inactive'
        }


        $boms = $query->latest()->paginate(10)->withQueryString(); // CRITICAL FIX: Added withQueryString()

        return Inertia::render('POSMasterfileBOM/Index', [
            'boms' => $boms,
            'filters' => $request->only(['search', 'filter']), // Pass filter back to frontend
            'filter' => $filter, // Pass current filter to the frontend
            'pendingDuplicates' => session($this->pendingKey(), []),
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
        set_time_limit(0);
        Log::debug('POSMasterfileBOM Import: Import method started.');

        $request->validate([
            'pos_bom_file' => 'required|mimes:xlsx,xls,csv'
        ]);

        POSMasterfileBOMImport::resetSeenCombinations();

        try {
            $import = new POSMasterfileBOMImport(app(\App\Support\EntityContext::class)->id());
            Excel::import($import, $request->file('pos_bom_file'));
            
            $skippedItems = $import->getSkippedItems();
            $processedCount = $import->getProcessedCount();
            $skippedCount = $import->getSkippedCount();
            $emptyCount = $import->getEmptyCount();

            // Rows repeating a line with a different BOM Qty wait for the user to allow them.
            $pending = $import->getPendingDuplicates();
            session()->put($this->pendingKey(), $pending);

            $parts = [$processedCount > 0 ? "Import successful. Processed {$processedCount} items." : 'No items were imported.'];
            if ($skippedCount > 0) {
                $parts[] = "{$skippedCount} rows were skipped due to validation errors or duplicates.";
                session()->flash('skippedItems', $skippedItems);
            }
            if ($pending) {
                $parts[] = count($pending) . ' rows repeat an existing BOM line with a different BOM Qty. Review them above the list.';
            }
            if ($processedCount === 0 && $skippedCount === 0 && !$pending) {
                $parts = ['No valid items found in the import file.'];
            }
            if ($emptyCount > 0) {
                $parts[] = "{$emptyCount} empty rows were ignored.";
            }
            if ($processedCount > 0) {
                // Pass success count to flash for the frontend to display
                session()->flash('success_count', $processedCount);
            }

            $clean = $processedCount > 0 && $skippedCount === 0 && !$pending && $emptyCount === 0;
            session()->flash($clean ? 'success' : 'warning', implode(' ', $parts));

            return redirect()->route('pos-bom.index');

        } catch (\Exception $e) {
            Log::error('POSMasterfileBOM Import Error: ' . $e->getMessage(), [
                'file_name' => $request->file('pos_bom_file')->getClientOriginalName(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Import failed: ' . $e->getMessage() . '. Please check logs for details.');
        }
    }

    /**
     * Add the selected rows the import held back (same POS Code, Item Code, BOM UOM and
     * Assembly as an existing line, different BOM Qty) as their own BOM lines.
     */
    public function allowDuplicates(Request $request)
    {
        $ids = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'string'])['ids'];
        $pending = collect(session($this->pendingKey(), []));
        $selected = $pending->whereIn('id', $ids);

        if ($selected->isEmpty()) {
            return back()->with('warning', 'Those rows are no longer waiting for review.');
        }

        $import = new POSMasterfileBOMImport(app(\App\Support\EntityContext::class)->id());
        DB::transaction(fn () => $selected->each(fn ($item) => $import->saveAllowedLine($item['row'])));

        session()->put($this->pendingKey(), $pending->whereNotIn('id', $ids)->values()->all());

        return back()->with('success', $selected->count() . ' BOM line(s) added.');
    }

    /** Drop the selected held-back rows without saving them. */
    public function dismissDuplicates(Request $request)
    {
        $ids = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'string'])['ids'];
        $pending = collect(session($this->pendingKey(), []));

        session()->put($this->pendingKey(), $pending->whereNotIn('id', $ids)->values()->all());

        return back()->with('success', $pending->whereIn('id', $ids)->count() . ' row(s) dismissed. Nothing was saved for them.');
    }

    /** Held-back rows are kept per entity, so switching entity never mixes them up. */
    private function pendingKey(): string
    {
        return 'pos_bom_pending_duplicates.' . (app(\App\Support\EntityContext::class)->id() ?? 'none');
    }
}
