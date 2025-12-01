<?php

namespace App\Http\Controllers;

use App\Models\MonthEndCountTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\MonthEndCountTemplatesExport;
use App\Imports\MonthEndCountTemplatesImport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Validators\ValidationException;

class MonthEndCountTemplateController extends Controller
{
    public function index(Request $request)
    {
        $templates = MonthEndCountTemplate::query()
            ->with(['createdBy', 'updatedBy'])
            ->search($request->input('search'))
            ->latest()
            ->paginate(50);

        return Inertia::render('MonthEndCountTemplates/Index', [
            'templates' => $templates,
            'filters' => request()->only(['search'])
        ]);
    }

    public function create()
    {
        return Inertia::render('MonthEndCountTemplates/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_code' => 'required|string|max:255',
            'item_name' => 'required|string|max:255',
            'area' => 'nullable|string|max:255',
            'category_2' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'packaging_config' => 'nullable|string|max:255',
            'config' => 'nullable|string|max:255',
            'uom' => 'nullable|string|max:255',
            'loose_uom' => 'nullable|string|max:255',
        ]);

        $validated['created_by'] = auth()->id();

        MonthEndCountTemplate::create($validated);

        return redirect()->route('month-end-count-templates.index')
                        ->with('success', 'Template created successfully.');
    }

    public function show(MonthEndCountTemplate $monthEndCountTemplate)
    {
        return Inertia::render('MonthEndCountTemplates/Show', [
            'template' => $monthEndCountTemplate->load(['createdBy', 'updatedBy'])
        ]);
    }

    public function edit(MonthEndCountTemplate $monthEndCountTemplate)
    {
        return Inertia::render('MonthEndCountTemplates/Edit', [
            'template' => $monthEndCountTemplate
        ]);
    }

    public function update(Request $request, MonthEndCountTemplate $monthEndCountTemplate)
    {
        $validated = $request->validate([
            'item_code' => 'required|string|max:255',
            'item_name' => 'required|string|max:255',
            'area' => 'nullable|string|max:255',
            'category_2' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'packaging_config' => 'nullable|string|max:255',
            'config' => 'nullable|string|max:255',
            'uom' => 'nullable|string|max:255',
            'loose_uom' => 'nullable|string|max:255',
        ]);

        $validated['updated_by'] = auth()->id();

        $monthEndCountTemplate->update($validated);

        return redirect()->route('month-end-count-templates.index')
                        ->with('success', 'Template updated successfully.');
    }

    public function destroy(MonthEndCountTemplate $monthEndCountTemplate)
    {
        $monthEndCountTemplate->delete();

        return redirect()->route('month-end-count-templates.index')
                        ->with('success', 'Template deleted successfully.');
    }

    public function export(Request $request)
    {
        return Excel::download(
            new MonthEndCountTemplatesExport($request->input('search')),
            'month-end-count-templates-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function import(Request $request)
    {
        set_time_limit(300); // 5 minutes

        $request->validate([
            'file' => 'required|mimes:xlsx,csv,txt|max:10240', // Max 10MB
        ]);

        $import = new MonthEndCountTemplatesImport;

        try {
            Excel::import($import, $request->file('file'));

            $createdCount = $import->getCreatedCount();
            $updatedCount = $import->getUpdatedCount();
            $skippedRows = $import->getSkippedRows();
            $collectionCalled = $import->getCollectionCalled();

            // Log the results for debugging
            Log::info('Month End Count Template Import Results:', [
                'created' => $createdCount,
                'updated' => $updatedCount,
                'skipped_count' => count($skippedRows),
                'collection_called' => $collectionCalled,
                'skipped_rows' => $skippedRows,
            ]);

            $message = [];
            if ($createdCount > 0) {
                $message[] = "{$createdCount} templates created.";
            }
            if ($updatedCount > 0) {
                $message[] = "{$updatedCount} templates updated.";
            }
            
            $redirect = back();

            if (!empty($skippedRows)) {
                $redirect->with('skippedItems', $skippedRows);
                // Add a generic message if no other message is present
                if (empty($message)) {
                    $message[] = 'Import processed with some skipped rows.';
                }
            }
            
            if (!$collectionCalled) {
                return $redirect->with('error', 'No valid data rows were found in the file. Please check the file content.');
            } 
            
            if (empty($message) && empty($skippedRows)) {
                return $redirect->with('error', 'No valid templates were imported or updated. Please check your file for valid data.');
            }

            return $redirect->with('success', implode(' ', $message));

        } catch (ValidationException $e) {
            $failures = $e->failures();
            $errorMessages = collect($failures)->map(function ($failure) {
                return "Row " . $failure->row() . ": " . implode(", ", $failure->errors());
            })->implode('; ');
            Log::error('Month End Count Template Import Validation Failed:', ['errors' => $errorMessages]);
            return back()->with('error', 'Validation failed: ' . $errorMessages);
              } catch (\Exception $e) {
            Log::error('Month End Count Template Import Exception:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $request->file('file') ? $request->file('file')->getClientOriginalName() : 'No file'
            ]);

            // Provide more user-friendly error messages
            $errorMessage = 'Error importing file: ' . $e->getMessage();

            // Add specific guidance for common issues
            if (strpos($e->getMessage(), 'heading') !== false) {
                $errorMessage .= ' Please ensure your Excel file has the correct column headers: Item Code, Item Name, Category 1, Area, Category 2, Packaging, Conversion, Bulk UOM, Loose UOM.';
            } elseif (strpos($e->getMessage(), 'required') !== false) {
                $errorMessage .= ' Please check that Item Code and Item Name columns are not empty.';
            }

            return back()->with('error', $errorMessage);
        }
    }

    public function downloadTemplate()
    {
        $path = public_path('templates/month-end-count-templates-template.xlsx');

        // Create a simple template file if it doesn't exist
        if (!file_exists($path)) {
            $templateData = [
                ['Item Code', 'Item Name', 'Category 1', 'Area', 'Category 2', 'Packaging', 'Conversion', 'Bulk UOM', 'Loose UOM'],
                ['EXAMPLE001', 'Example Item', 'Example Category 1', 'Example Area', 'Example Category 2', 'Example Packaging', '1', 'PCS', 'PCS'],
            ];

            Excel::download(new class($templateData) implements \Maatwebsite\Excel\Concerns\FromCollection, \Maatwebsite\Excel\Concerns\WithHeadings {
                protected $data;

                public function __construct($data) {
                    $this->data = $data;
                }

                public function collection() {
                    return collect($this->data);
                }

                public function headings(): array {
                    return $this->data[0];
                }
            }, 'month-end-count-templates-template.xlsx')->store('temp/template.xlsx', 'local');

            $path = storage_path('app/temp/template.xlsx');
        }

        return response()->download($path)->deleteFileAfterSend(true);
    }
}

