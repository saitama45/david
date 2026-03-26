<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Exports\SalesBudgetTemplateExport;
use App\Imports\SalesBudgetImport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;

class SalesBudgetController extends Controller
{
    public function index()
    {
        return Inertia::render('SalesBudget/Index');
    }

    public function downloadTemplate()
    {
        return Excel::download(new SalesBudgetTemplateExport, 'sales_budget_template.xlsx');
    }

    public function upload(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('Upload request received', $request->all());
        $request->validate([
            'type' => 'required|in:Sales,Budget',
            'year' => 'required|integer|min:2000|max:2100',
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        try {
            \Illuminate\Support\Facades\Log::info('Starting Excel::import');
            $import = new SalesBudgetImport($request->type, $request->year);
            Excel::import($import, $request->file('file'));
            \Illuminate\Support\Facades\Log::info('Excel::import finished');

            return redirect()->back()->with([
                'success' => 'Data upload processed successfully!',
                'summary_messages' => $import->getSummaryMessages(),
                'summary_counts' => $import->getSummaryCounts()
            ]);
        } catch (\Exception $e) {
            Log::error('SalesBudget Upload Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Error uploading data: ' . $e->getMessage());
        }
    }
}
