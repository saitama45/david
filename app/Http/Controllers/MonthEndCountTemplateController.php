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
        $request->validate([
            'file' => 'required|mimes:xlsx,csv,txt|max:10240', // Max 10MB
        ]);

        try {
            Excel::import(new MonthEndCountTemplatesImport, $request->file('file'));
            return back()->with('success', 'Templates imported successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Error importing file: ' . $e->getMessage());
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
