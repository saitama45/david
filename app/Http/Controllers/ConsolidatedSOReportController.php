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
        $user = \Illuminate\Support\Facades\Auth::user();
        $orderDate = $request->input('order_date', \Carbon\Carbon::today()->format('Y-m-d'));
        $supplierCode = $request->input('supplier_id', 'all');

        // Get user's assigned suppliers for the dropdown
        $userSuppliers = $user->suppliers()->get();
        $suppliers = $userSuppliers->map(function ($supplier) {
            return [
                'label' => $supplier->name . ' (' . $supplier->supplier_code . ')',
                'value' => $supplier->supplier_code,
            ];
        });

        // Convert supplier_code from request to supplier_id for the service
        $supplierId = ($supplierCode === 'all') ? 'all' : \App\Models\Supplier::where('supplier_code', $supplierCode)->first()?->id;

        // Get user's assigned branches that have a delivery on the selected date
        $dayName = \Carbon\Carbon::parse($orderDate)->format('l');
        $user->load('store_branches.delivery_schedules');

        $branchesForReport = $user->store_branches->filter(function ($branch) use ($dayName, $supplierCode, $userSuppliers) {
            $schedulesOnDay = $branch->delivery_schedules->where('day', strtoupper($dayName));
            if ($schedulesOnDay->isEmpty()) {
                return false;
            }

            if ($supplierCode !== 'all') {
                // Check for the specific supplier
                return $schedulesOnDay->contains(function ($schedule) use ($supplierCode) {
                    return $schedule->pivot->variant === $supplierCode;
                });
            } else {
                // Check for ANY of the user's assigned suppliers
                $userSupplierCodes = $userSuppliers->pluck('supplier_code');
                return $schedulesOnDay->contains(function ($schedule) use ($userSupplierCodes) {
                    return $userSupplierCodes->contains($schedule->pivot->variant);
                });
            }
        });

        $branches = $branchesForReport->pluck('name', 'id');
        $branchIdsForReport = $branchesForReport->pluck('id');

        // Call the service with the filtered lists
        $reportData = $this->consolidatedSOReportService->getConsolidatedSOReportData(
            $orderDate,
            $supplierId, // Pass the ID
            $branchIdsForReport
        );

        return Inertia::render('ConsolidatedSOReport/Index', [
            'filters' => [
                'order_date' => $orderDate,
                'supplier_id' => $supplierCode, // Send the code back to the view
            ],
            'branches' => $branches,
            'suppliers' => $suppliers,
            'report' => $reportData['report'],
            'dynamicHeaders' => $reportData['dynamicHeaders'],
            'totalBranches' => $reportData['totalBranches'],
        ]);
    }

    public function export(Request $request)
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        $orderDate = $request->input('order_date', \Carbon\Carbon::today()->format('Y-m-d'));
        $supplierCode = $request->input('supplier_id', 'all');

        // Convert supplier_code from request to supplier_id for the service
        $supplierId = ($supplierCode === 'all') ? 'all' : \App\Models\Supplier::where('supplier_code', $supplierCode)->first()?->id;

        // Get user's assigned branches that have a delivery on the selected date
        $userSuppliers = $user->suppliers()->get();
        $dayName = \Carbon\Carbon::parse($orderDate)->format('l');
        $user->load('store_branches.delivery_schedules');

        $branchesForReport = $user->store_branches->filter(function ($branch) use ($dayName, $supplierCode, $userSuppliers) {
            $schedulesOnDay = $branch->delivery_schedules->where('day', strtoupper($dayName));
            if ($schedulesOnDay->isEmpty()) {
                return false;
            }

            if ($supplierCode !== 'all') {
                return $schedulesOnDay->contains(function ($schedule) use ($supplierCode) {
                    return $schedule->pivot->variant === $supplierCode;
                });
            } else {
                $userSupplierCodes = $userSuppliers->pluck('supplier_code');
                return $schedulesOnDay->contains(function ($schedule) use ($userSupplierCodes) {
                    return $userSupplierCodes->contains($schedule->pivot->variant);
                });
            }
        });
        $branchIdsForReport = $branchesForReport->pluck('id');

        $reportData = $this->consolidatedSOReportService->getConsolidatedSOReportData(
            $orderDate,
            $supplierId,
            $branchIdsForReport
        );

        return Excel::download(
            new ConsolidatedSOReportExport(
                $reportData['report'],
                $reportData['dynamicHeaders'],
                $reportData['totalBranches'],
                $orderDate
            ),
            'consolidated-so-report-' . Carbon::parse($orderDate)->format('Y-m-d') . '.xlsx'
        );
    }
}
