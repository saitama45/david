<?php

namespace App\Http\Controllers;

use App\Exports\ConsolidatedSOReportExport;
use App\Http\Services\ConsolidatedSOReportService;
use App\Models\StoreBranch;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class ConsolidatedSOReportController extends Controller
{
    protected $consolidatedSOReportService;

    public function __construct(ConsolidatedSOReportService $consolidatedSOReportService)
    {
        $this->consolidatedSOReportService = $consolidatedSOReportService;
    }

    public function index(Request $request)
    {
        // Default to today's date if not provided
        $orderDate = $request->input('order_date', Carbon::today()->format('Y-m-d'));
        $supplierId = $request->input('supplier_id', 'all');

        $branches = StoreBranch::options();
        $suppliers = Supplier::reportOptions(); // Use the new reportOptions scope for suppliers

        // Fetch report data including dynamic branch columns
        $reportData = $this->consolidatedSOReportService->getConsolidatedSOReportData(
            $orderDate,
            $supplierId
        );

        return Inertia::render('ConsolidatedSOReport/Index', [
            'filters' => [
                'order_date' => $orderDate,
                'supplier_id' => $supplierId,
            ],
            'branches' => $branches,
            'suppliers' => $suppliers,
            'report' => $reportData['report'],
            'dynamicHeaders' => $reportData['dynamicHeaders'],
            'totalBranches' => $reportData['totalBranches'], // Pass total branches for column span
        ]);
    }

    public function export(Request $request)
    {
        $orderDate = $request->input('order_date', Carbon::today()->format('Y-m-d'));
        $supplierId = $request->input('supplier_id', 'all');

        $reportData = $this->consolidatedSOReportService->getConsolidatedSOReportData(
            $orderDate,
            $supplierId
        );

        return Excel::download(
            new ConsolidatedSOReportExport(
                $reportData['report'],
                $reportData['dynamicHeaders'],
                $reportData['totalBranches'],
                $orderDate // CRITICAL FIX: Pass the orderDate to the export constructor
            ),
            'consolidated-so-report-' . Carbon::parse($orderDate)->format('Y-m-d') . '.xlsx'
        );
    }
}
