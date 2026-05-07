<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Supplier;
use App\Models\StoreBranch;
use App\Http\Services\OrderCalculatorService;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\OrderCalculatorExport;

class OrderCalculatorController extends Controller
{
    protected $orderCalculatorService;

    public function __construct(OrderCalculatorService $orderCalculatorService)
    {
        $this->orderCalculatorService = $orderCalculatorService;
    }

    public function index()
    {
        $user = auth()->user();
        
        // Get suppliers (ordering templates) for the user
        $suppliers = $user->suppliers()
            ->where('is_active', true)
            ->get()
            ->map(function ($supplier) {
                if ($supplier->supplier_code === 'CPO') {
                    return [
                        'label' => 'CPO',
                        'value' => 'CPO',
                    ];
                }
                return [
                    'label' => $supplier->name === 'DROPSHIPPING' ? 'FRUITS AND VEGETABLES' : $supplier->name . ' (' . $supplier->supplier_code . ')',
                    'value' => $supplier->supplier_code,
                ];
            });

        // Get assigned stores for the user
        $user->load('store_branches');
        $stores = $user->store_branches->map(function($branch) {
            return [
                'label' => $branch->name . ' (' . $branch->branch_code . ')',
                'value' => $branch->id,
                'branch_code' => $branch->branch_code,
            ];
        });

        // If admin, they might see all stores
        if ($user->hasRole('admin') && $stores->isEmpty()) {
            $stores = StoreBranch::where('is_active', true)->get()->map(function($branch) {
                return [
                    'label' => $branch->name . ' (' . $branch->branch_code . ')',
                    'value' => $branch->id,
                    'branch_code' => $branch->branch_code,
                ];
            });
        }

        // Start of Forecasting Week (Monday of next week)
        $startOfForecastingWeek = Carbon::now()->next(Carbon::MONDAY)->format('Y-m-d');

        return Inertia::render('OrderCalculator/Index', [
            'stores' => $stores,
            'templates' => $suppliers,
            'startOfForecastingWeek' => $startOfForecastingWeek,
        ]);
    }

    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'store_branch_id' => 'required|exists:store_branches,id',
            'ordering_template' => 'required|string', // Supplier Code
            'target_dtl' => 'required|date',
            'sunday_date' => 'required|date',
            'adu_month' => 'required|string', // e.g., '2026-02'
            'pmix_months' => 'required|array|min:1', // Allow multiple months
            'pmix_months.*' => 'required|string'
        ]);

        $data = $this->orderCalculatorService->getCalculatorData(
            $validated['store_branch_id'],
            $validated['ordering_template'],
            $validated['target_dtl'],
            $validated['sunday_date'],
            $validated['adu_month'],
            $validated['pmix_months']
        );

        return response()->json($data);
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'headers' => 'required|array',
            'filename' => 'required|string'
        ]);

        $items = collect($validated['items'])->map(function($item) {
            return [
                $item['item_code'],
                $item['item_name'],
                $item['category'],
                $item['brand'],
                $item['classification'],
                $item['packaging_config'],
                $item['uom'],
                $item['sunday_ending_inventory'],
                $item['incoming_deliveries'],
                $item['incremental'],
                $item['calculated_adu']['rate'],
                $item['calculated_adu']['dtl1'],
                $item['calculated_adu']['dtl2'],
                $item['calculated_adu']['revisedRate'],
                $item['calculated_adu']['suggestedOrder'],
                $item['calculated_pmix']['rate'],
                $item['calculated_pmix']['dtl1'],
                $item['calculated_pmix']['dtl2'],
                $item['calculated_pmix']['revisedRate'],
                $item['calculated_pmix']['suggestedOrder'],
            ];
        });

        return Excel::download(
            new OrderCalculatorExport($items, $validated['headers']), 
            $validated['filename'] . '.xlsx'
        );
    }
}
