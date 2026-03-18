<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Supplier;
use App\Models\StoreBranch;
use App\Http\Services\OrderCalculatorService;
use Carbon\Carbon;

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
            'pmix_month' => 'required|string', // e.g., '2026-02'
        ]);

        $data = $this->orderCalculatorService->getCalculatorData(
            $validated['store_branch_id'],
            $validated['ordering_template'],
            $validated['target_dtl'],
            $validated['sunday_date'],
            $validated['adu_month'],
            $validated['pmix_month']
        );

        return response()->json($data);
    }
}
