<?php

namespace App\Http\Controllers;

use App\Imports\WIPIngredientImport;
use App\Imports\WIPListImport;
use App\Models\WIP;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class WIPListController extends Controller
{
    public function index()
    {
        $search = request('search');

        $query = WIP::query()->with('wip_ingredients.product');

        if ($query)
            $query->whereAny(['sap_code', 'name'], 'like', "%{$search}%");

        $wips = $query->latest()->paginate(10);

        return Inertia::render('WIPList/Index', [
            'wips' => $wips,
            'filters' => request()->only(['search'])
        ]);
    }

    public function show($id)
    {
        $wip = WIP::with('wip_ingredients.product')->findOrFail($id);
        return Inertia::render('WIPList/Show', [
            'wip' => $wip
        ]);
    }

    public function importWipList(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv'
        ]);

        Excel::import(new WIPListImport, $request->file('file'));

        return back();
    }

    public function importWipIngredients(Request $request)
{
    $request->validate([
        'file' => 'required|mimes:xlsx,xls,csv'
    ]);

    $import = new WIPIngredientImport();
    
    try {
        Excel::import($import, $request->file('file'));
        
        $processedCount = $import->getProcessedCount();
        
        Log::info('WIP Ingredient Import Completed Successfully', [
            'processed_rows' => $processedCount,
            'file_name' => $request->file('file')->getClientOriginalName()
        ]);
        
        return redirect()->back()->with('success', "WIP ingredients imported successfully! {$processedCount} rows processed.");
        
    } catch (\Exception $e) {
        Log::error('WIP Import Failed', [
            'error' => $e->getMessage(),
            'file_name' => $request->file('file')->getClientOriginalName(),
            'trace' => $e->getTraceAsString()
        ]);
        
        // Check if it's a validation error (contains our custom validation messages)
        if (strpos($e->getMessage(), 'Validation failed:') === 0) {
            $errorMessage = str_replace('Validation failed: ', '', $e->getMessage());
            $errors = explode('; ', $errorMessage);
            
            return redirect()->back()->withErrors([
                'validation_errors' => $errors
            ])->with('error', 'Import cancelled due to validation errors. Please fix the following issues:');
        }
        
        // For other types of errors
        return redirect()->back()->withErrors([
            'message' => 'Import failed: ' . $e->getMessage()
        ])->with('error', 'Import was cancelled due to an error.');
    }
}

}
