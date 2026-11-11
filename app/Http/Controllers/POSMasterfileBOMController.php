<?php

namespace App\Http\Controllers;

use App\Models\POSMasterfile;
use App\Models\POSMasterfileBOM;
use App\Models\SAPMasterfile;
use App\Models\User; // Assuming User model is needed for created_by/updated_by
use App\Http\Services\PosRecipeHistoryService;
use App\Support\PosRecipeHistory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
        return Inertia::render('POSMasterfileBOM/Create');
    }

    /**
     * What the Create form shows once a code is typed: the POS item's description, and the
     * ingredient's description with the units SAP gives it.
     */
    public function lookup(Request $request)
    {
        $posCode = trim((string) $request->query('pos_code', ''));
        $itemCode = trim((string) $request->query('item_code', ''));

        $posItem = $posCode === '' ? null : POSMasterfile::where('POSCode', $posCode)->first();
        $sapRows = $itemCode === '' ? collect() : SAPMasterfile::where('ItemCode', $itemCode)->orderBy('id')->get();

        return response()->json([
            'pos' => $posItem ? ['code' => $posItem->POSCode, 'description' => $posItem->POSDescription] : null,
            'item' => $sapRows->isEmpty() ? null : [
                'code' => $sapRows->first()->ItemCode,
                'description' => $sapRows->first()->ItemDescription,
                'units' => $this->sapUnits($sapRows),
            ],
        ]);
    }

    /**
     * Add one BOM line by hand, under the same rules as the import: the POS Code and Item
     * Code must be on their masterlists, the BOM Qty must be above zero, and a line is
     * POS Code + Item Code + BOM UOM + Assembly. Repeating a line with another BOM Qty is
     * deducted twice per sale, so it is saved only once the user has confirmed it.
     */
    public function store(Request $request)
    {
        $request->merge(collect($request->only(['POSCode', 'Assembly', 'ItemCode', 'RecipeUOM', 'BOMUOM']))
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->all());

        $validated = $request->validate([
            'POSCode' => ['required', 'string', 'max:255'],
            'Assembly' => ['nullable', 'string', 'max:255'],
            'ItemCode' => ['required', 'string', 'max:255'],
            'RecPercent' => ['nullable', 'numeric', 'min:0'],
            // A negative BOM Qty (and its Recipe Qty / Total Cost) is a line each sale adds back to stock.
            'RecipeQty' => ['nullable', 'numeric'],
            'RecipeUOM' => ['nullable', 'string', 'max:50'],
            'BOMQty' => ['required', 'numeric'],
            'BOMUOM' => ['required', 'string', 'max:50'],
            'UnitCost' => ['nullable', 'numeric', 'min:0'],
            'TotalCost' => ['nullable', 'numeric'],
            'allow_repeat' => ['nullable', 'boolean'],
        ]);

        if ((float) $validated['BOMQty'] == 0.0) {
            throw ValidationException::withMessages([
                'BOMQty' => 'BOM Qty cannot be 0. Use a negative quantity for an item each sale adds back to stock.',
            ]);
        }

        $posItem = POSMasterfile::where('POSCode', $validated['POSCode'])->first();

        if (! $posItem) {
            throw ValidationException::withMessages([
                'POSCode' => "{$validated['POSCode']} is not in the POS Masterlist. Add it there first.",
            ]);
        }

        $sapRows = SAPMasterfile::where('ItemCode', $validated['ItemCode'])->orderBy('id')->get();

        if ($sapRows->isEmpty()) {
            throw ValidationException::withMessages([
                'ItemCode' => "{$validated['ItemCode']} is not in the SAP Masterlist. Add it there first.",
            ]);
        }

        // A sale can only deduct the ingredient in a unit SAP knows for it.
        $units = $this->sapUnits($sapRows);
        $bomUom = collect($units)->first(fn ($unit) => strtoupper($unit) === strtoupper($validated['BOMUOM']));

        if (! $bomUom) {
            throw ValidationException::withMessages([
                'BOMUOM' => "'{$validated['BOMUOM']}' is not a unit of {$validated['ItemCode']} in the SAP Masterlist. Use one of: ".implode(', ', $units).'.',
            ]);
        }

        // Blank inputs arrive as null; the import stores a blank Assembly as ''.
        $assembly = (string) ($validated['Assembly'] ?? '');
        $bomQty = number_format((float) $validated['BOMQty'], 7, '.', '');

        $lines = POSMasterfileBOM::where('POSCode', $posItem->POSCode)
            ->where('ItemCode', $sapRows->first()->ItemCode)
            ->where('BOMUOM', $bomUom)
            ->where(fn ($query) => $assembly === ''
                ? $query->whereNull('Assembly')->orWhere('Assembly', '')
                : $query->where('Assembly', $assembly))
            ->get(['id', 'BOMQty']);

        if ($lines->contains(fn ($line) => number_format((float) $line->BOMQty, 7, '.', '') === $bomQty)) {
            throw ValidationException::withMessages([
                'BOMQty' => 'This BOM line already exists with the same BOM Qty. Edit it instead.',
            ]);
        }

        if ($lines->isNotEmpty() && ! $request->boolean('allow_repeat')) {
            $existing = $lines->map(fn ($line) => rtrim(rtrim(number_format((float) $line->BOMQty, 7, '.', ''), '0'), '.'))->implode(', ');

            throw ValidationException::withMessages([
                'repeat' => "This recipe already has this item in {$bomUom} with BOM Qty {$existing}.",
            ]);
        }

        $line = POSMasterfileBOM::create([
            'POSCode' => $posItem->POSCode,
            'POSDescription' => $posItem->POSDescription,
            'Assembly' => $assembly,
            'ItemCode' => $sapRows->first()->ItemCode,
            'ItemDescription' => $sapRows->first()->ItemDescription,
            'RecPercent' => $validated['RecPercent'] ?? 0,
            'RecipeQty' => $validated['RecipeQty'] ?? 0,
            'RecipeUOM' => (string) ($validated['RecipeUOM'] ?? ''),
            'BOMQty' => $bomQty,
            'BOMUOM' => $bomUom,
            'UnitCost' => $validated['UnitCost'] ?? 0,
            'TotalCost' => $validated['TotalCost'] ?? 0,
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);
        PosRecipeHistory::bomLine(PosRecipeHistory::CREATED, null, $line);

        return to_route('pos-bom.index')->with('success', 'BOM line created.');
    }

    /**
     * Every unit SAP gives an item, as SAP spells it, each once.
     *
     * @return array<int, string>
     */
    private function sapUnits($sapRows): array
    {
        return $sapRows
            ->flatMap(fn ($row) => [trim((string) $row->AltUOM), trim((string) $row->BaseUOM)])
            ->filter()
            ->unique(fn ($unit) => strtoupper($unit))
            ->values()
            ->all();
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
     * BOM Change History: every change to a BOM line or a POS item, from the forms and the
     * Excel imports, with who, when, the old and new values and the source.
     */
    public function history(Request $request, PosRecipeHistoryService $history)
    {
        $filters = $this->historyFilters($request);
        $changes = $history->query($filters)->paginate(PosRecipeHistoryService::PER_PAGE)->withQueryString();
        $changes->setCollection($changes->getCollection()->map(fn ($row) => $history->present($row)));
        $changedBy = filled($filters['changed_by'] ?? null)
            ? DB::table('users')->where('id', (int) $filters['changed_by'])->first(['id', 'first_name', 'last_name', 'email'])
            : null;

        return Inertia::render('POSMasterfileBOM/History', [
            'changes' => $changes,
            // Every key, even when empty: a missing one would read as a JavaScript built-in.
            'filters' => $filters,
            'changedBy' => $changedBy ? ['id' => (int) $changedBy->id, 'name' => trim($changedBy->first_name . ' ' . $changedBy->last_name), 'email' => $changedBy->email] : null,
            'options' => [
                'subjects' => $this->optionList(PosRecipeHistoryService::SUBJECTS),
                'actions' => $this->optionList(PosRecipeHistoryService::ACTIONS),
                'sources' => $this->optionList(PosRecipeHistoryService::SOURCES),
            ],
        ]);
    }

    public function historyExport(Request $request, PosRecipeHistoryService $history)
    {
        $changes = $history->query($this->historyFilters($request))->get()->map(fn ($row) => $history->present($row))->all();
        $response = Excel::download(new \App\Exports\PosRecipeChangesExport($changes), 'bom_change_history-' . now()->format('Y-m-d') . '.xlsx');

        // Built fresh on every download: the history grows, so a browser must never hand back an older copy.
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    /** The Changed By picker: users who made a change, matching what was typed. */
    public function historyUsers(Request $request, PosRecipeHistoryService $history)
    {
        return response()->json($history->users((string) $request->input('q', '')));
    }

    /** @return array<string, string> */
    private function historyFilters(Request $request): array
    {
        $filters = $request->validate([
            'pos_code' => ['nullable', 'string', 'max:255'],
            'item_code' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'changed_by' => ['nullable', 'integer'],
            'source' => ['nullable', 'string', 'max:30'],
            'action' => ['nullable', 'string', 'max:20'],
            'subject' => ['nullable', 'string', 'max:20'],
        ]);

        return array_map(fn ($value) => (string) ($value ?? ''), array_merge(
            array_fill_keys(['pos_code', 'item_code', 'date_from', 'date_to', 'changed_by', 'source', 'action', 'subject'], null),
            $filters
        ));
    }

    /** @return array<int, array{value: string, label: string}> */
    private function optionList(array $labels): array
    {
        return collect($labels)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all();
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
            DB::transaction(function () use ($posBom, $validatedData, $user) {
                $before = $posBom->getAttributes();
                $posBom->update(array_merge($validatedData, [
                    'updated_by' => $user->id,
                ]));
                PosRecipeHistory::bomLine(PosRecipeHistory::UPDATED, $before, $posBom);
            });

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
            DB::transaction(function () use ($posBom) {
                // The line as it was, kept in the change history once the line itself is gone.
                $before = $posBom->getAttributes();
                $posBom->delete();
                PosRecipeHistory::bomLine(PosRecipeHistory::DELETED, $before, null);
            });
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
            $import = new POSMasterfileBOMImport(app(\App\Support\EntityContext::class)->id(), $request->file('pos_bom_file')->getClientOriginalName());
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
        DB::transaction(fn () => $selected->each(fn ($item) => $import->saveAllowedLine($item['row'], $item['source_file'] ?? null)));

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
