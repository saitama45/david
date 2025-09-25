<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Exports\StoreOrdersExport;
use App\Exports\UsersExport;
use App\Http\Requests\StoreOrder\StoreOrderRequest; // Ensure this is the correct namespace
use App\Http\Requests\StoreOrder\UpdateOrderRequest;
use App\Http\Services\StoreOrderService;
use App\Imports\OrderListImport;
use Inertia\Inertia;
use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\SupplierItems;
use Illuminate\Support\Facades\Log; // Import Log facade
use App\Exports\SupplierOrderTemplateExport; // Import the new export class

class StoreOrderController extends Controller
{
    protected $storeOrderService;

    public function __construct(StoreOrderService $storeOrderService)
    {
        $this->storeOrderService = $storeOrderService;
    }
    public function index()
    {
        $orders = $this->storeOrderService->getOrdersList();
        $branches = StoreBranch::options();
        return Inertia::render(
            'StoreOrder/Index',
            [
                'orders' => $orders,
                'branches' => $branches,
                'filters' => request()->only(['from', 'to', 'branchId', 'search', 'filterQuery'])
            ]
        );
    }

    public function export()
    {
        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : '1999-01-01';
        $to = request('to') ? Carbon::parse(request('to'))->addDay()->format('Y-m-d') : Carbon::today()->addMonth();
        $branchId = request('branchId');
        $search = request('search');
        $filterQuery = request('filterQuery') ?? 'pending';

        return Excel::download(
            new StoreOrdersExport($search, $branchId, $filterQuery, $from, $to),
            'store-orders-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function create()
    {
        $suppliers = Supplier::whereNot('supplier_code', 'DROPS')->options();

        return Inertia::render('StoreOrder/Create', [
            'branches' => [], // Pass empty array, will be fetched dynamically
            'suppliers' => $suppliers,
            'previousOrder' => null, // Remove previousOrder logic as it conflicts with the new flow
        ]);
    }

    // REVISED: Store method with improved error handling and correct StoreOrderRequest usage
    public function store(StoreOrderRequest $request)
    {
        try {
            $this->storeOrderService->createStoreOrder($request->validated());
            return redirect()->route('store-orders.index')->with('success', 'Order placed successfully!');
        } catch (Exception $e) {
            Log::error("Error creating store order: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to place order: ' . $e->getMessage()]);
        }
    }

    public function show($id)
    {
        $order = $this->storeOrderService->getOrderDetails($id);
        // Eager load the 'sapMasterfiles' relationship (plural) so the 'sap_master_file' accessor works correctly.
        $order->load(['ordered_item_receive_dates.store_order_item.supplierItem.sapMasterfiles']);

        $orderedItems = $this->storeOrderService->getOrderItems($order);
        // Eager load the 'sapMasterfiles' relationship (plural) for ordered items.
        $orderedItems->load('supplierItem.sapMasterfiles');

        return Inertia::render('StoreOrder/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'receiveDatesHistory' => $order->ordered_item_receive_dates,
            'images' => $this->storeOrderService->getImageAttachments($order)
        ]);
    }

    public function getImportedOrders(Request $request)
    {
        // Validate the incoming request, ensuring the file and supplier_id are present
        $request->validate([
            'orders_file' => 'required|file|mimes:xlsx,xls',
            'supplier_id' => 'required|string', // Ensure supplier_id is passed and is a string
        ]);

        $supplierId = $request->input('supplier_id'); // Get the supplier_id from the request
        $import = new OrderListImport($supplierId); // Pass the supplier_id to the importer's constructor
        Excel::import($import, $request->file('orders_file'));
        $importedCollection = $import->getImportedData();
        $skippedItems = $import->getSkippedItems(); // NEW: Get skipped items

        // If no valid items were imported after filtering (e.g., due to supplier mismatch or other validation)
        // We will now always return a 200 OK, but include skipped items for frontend display.
        return response()->json([
            'orders' => $importedCollection,
            'skipped_items' => $skippedItems // NEW: Include skipped items in the response
        ]);
    }

    public function edit($id)
    {
        $order = $this->storeOrderService->getOrder($id);
        $orderedItems = $this->storeOrderService->getOrderItems($order);
        $orderedItems->load('supplierItem.sapMasterfiles');
        $suppliers = Supplier::options();

        $initialSupplierCode = $order->supplier->supplier_code;
        $initialOrderDate = Carbon::parse($order->order_date);

        // Get initial available branches
        $initialDayName = strtoupper($initialOrderDate->format('l'));
        $user = Auth::user();
        $user->load('store_branches');
        $initialFinalBranches = $user->store_branches->filter(function ($branch) use ($initialSupplierCode, $initialDayName) {
            return $branch->delivery_schedules()
                ->where('delivery_schedules.day', $initialDayName)
                ->wherePivot('variant', $initialSupplierCode)
                ->exists();
        });
        $initialActiveBranches = $initialFinalBranches->where('is_active', true);
        $branches = $initialActiveBranches->mapWithKeys(function ($branch) {
            return [$branch->id => $branch->name . ' (' . $branch->brand_code . ')'];
        });

        // Get initial enabled dates
        $enabledDates = [];
        $cutoff = \App\Models\OrdersCutoff::where('ordering_template', $initialSupplierCode)->first();
        if ($cutoff) {
            $now = Carbon::now();
            $getCutoffDate = function($day, $time) use ($now) {
                if (!$day || !$time) return null;
                $dayIndex = ($day == 7) ? 0 : $day;
                return $now->copy()->startOfWeek(Carbon::SUNDAY)->addDays($dayIndex)->setTimeFromTimeString($time);
            };

            $cutoff1Date = $getCutoffDate($cutoff->cutoff_1_day, $cutoff->cutoff_1_time);
            $cutoff2Date = $getCutoffDate($cutoff->cutoff_2_day, $cutoff->cutoff_2_time);

            $daysToCoverStr = '';
            $weekOffset = 0;

            if ($cutoff1Date && $now->lt($cutoff1Date)) {
                $daysToCoverStr = $cutoff->days_covered_1;
                $weekOffset = str_starts_with($initialSupplierCode, 'GSI') ? 1 : 0;
            } elseif ($cutoff2Date && $now->lt($cutoff2Date)) {
                $daysToCoverStr = $cutoff->days_covered_2;
                $weekOffset = str_starts_with($initialSupplierCode, 'GSI') ? 1 : 0;
            } else {
                $daysToCoverStr = $cutoff->days_covered_1;
                $weekOffset = 1;
            }

            $startOfTargetWeek = $now->copy()->startOfWeek(Carbon::SUNDAY)->addWeeks($weekOffset);
            $daysToCover = $daysToCoverStr ? explode(',', $daysToCoverStr) : [];
            $dayMap = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

            foreach ($daysToCover as $day) {
                $day = trim($day);
                if (isset($dayMap[$day])) {
                    $date = $startOfTargetWeek->copy()->addDays($dayMap[$day]);
                    $enabledDates[] = $date->toDateString();
                }
            }
        }

        return Inertia::render('StoreOrder/Edit', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'branches' => $branches,
            'suppliers' => $suppliers,
            'enabledDates' => $enabledDates,
        ]);
    }

    // REVISED: Update method with improved error handling
    public function update(UpdateOrderRequest $request, StoreOrder $storeOrder)
    {
        $order = $storeOrder->load('store_order_items');
        try {
            $this->storeOrderService->updateOrder($order, $request->validated());
            return redirect()->route('store-orders.index')->with('success', 'Order updated successfully!');
        } catch (Exception $e) {
            Log::error("Error updating store order: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to update order: ' . $e->getMessage()]);
        }
    }

    // getSupplierItems method now correctly takes supplierCode (string)
    public function getSupplierItems($supplierCode) // Expects supplierCode (string)
    {
        // Use the SupplierItems::options() scope which returns ItemCode => CONCAT(item_name, ' (', ItemCode, ') ', uom)
        $supplierItems = SupplierItems::where('SupplierCode', $supplierCode)
                                       ->where('is_active', true)
                                       ->options() // This scope returns ItemCode => CONCAT(item_name, ' (', ItemCode, ') ', uom)
                                       ->all(); // Convert the collection to an array of value/label pairs

        // The options scope returns an associative array (ItemCode => formatted_name),
        // we need to convert it to the {value: 'ItemCode', label: 'formatted_name'} format expected by Select component.
        $formattedSupplierItems = [];
        foreach ($supplierItems as $itemCode => $formattedName) {
            $formattedSupplierItems[] = [
                'value' => (string) $itemCode, // Ensure ItemCode is a string
                'label' => $formattedName // Use the concatenated name as the label
            ];
        }

        return response()->json([
            'items' => $formattedSupplierItems
        ]);
    }

    public function getSupplierItemDetailsByCode(Request $request)
    {
        $itemCode = $request->itemCode;
        $supplierCode = $request->supplierCode;

        $item = SupplierItems::where('ItemCode', $itemCode)
                            ->where('SupplierCode', $supplierCode)
                            ->where('is_active', true)
                            // The 'sap_master_file' is an accessor, not a relationship.
                            // It's automatically appended due to $appends on the SupplierItems model.
                            // No need to eager load it with 'with()'.
                            ->first();

        if (!$item) {
            return response()->json(['message' => 'Item not found for the given code and supplier.'], 404);
        }

        return response()->json(['item' => $item]);
    }

    /**
     * Downloads an Excel template for supplier items based on the selected supplier.
     *
     * @param string $supplierCode The supplier code from the frontend.
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function downloadSupplierOrderTemplate(string $supplierCode)
    {
        // Validate that the supplierCode exists
        $supplierExists = Supplier::where('supplier_code', $supplierCode)->exists();
        if (!$supplierExists) {
            return response()->json(['message' => 'Supplier not found.'], 404);
        }

        $fileName = "supplier_order_template_{$supplierCode}.xlsx";
        return Excel::download(new SupplierOrderTemplateExport($supplierCode), $fileName);
    }

    public function getAvailableDatesForSupplier($supplier_code)
    {
        $cutoff = \App\Models\OrdersCutoff::where('ordering_template', $supplier_code)->first();
        if (!$cutoff) {
            return response()->json([]);
        }

        $now = \Carbon\Carbon::now();

        $getCutoffDate = function($day, $time) use ($now) {
            if (!$day || !$time) return null;
            $dayIndex = ($day == 7) ? 0 : $day;
            return $now->copy()->startOfWeek(\Carbon\Carbon::SUNDAY)->addDays($dayIndex)->setTimeFromTimeString($time);
        };

        $cutoff1Date = $getCutoffDate($cutoff->cutoff_1_day, $cutoff->cutoff_1_time);
        $cutoff2Date = $getCutoffDate($cutoff->cutoff_2_day, $cutoff->cutoff_2_time);

        $daysToCoverStr = '';
        $weekOffset = 0;

        if ($cutoff1Date && $now->lt($cutoff1Date)) {
            $daysToCoverStr = $cutoff->days_covered_1;
            $weekOffset = str_starts_with($supplier_code, 'GSI') ? 1 : 0;
        } elseif ($cutoff2Date && $now->lt($cutoff2Date)) {
            $daysToCoverStr = $cutoff->days_covered_2;
            $weekOffset = str_starts_with($supplier_code, 'GSI') ? 1 : 0;
        } else {
            $daysToCoverStr = $cutoff->days_covered_1;
            $weekOffset = 1;
        }

        $startOfTargetWeek = $now->copy()->startOfWeek(\Carbon\Carbon::SUNDAY)->addWeeks($weekOffset);

        $daysToCover = $daysToCoverStr ? explode(',', $daysToCoverStr) : [];
        $dayMap = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

        $enabledDates = [];
        foreach ($daysToCover as $day) {
            $day = trim($day);
            if (isset($dayMap[$day])) {
                $date = $startOfTargetWeek->copy()->addDays($dayMap[$day]);
                $enabledDates[] = $date->toDateString();
            }
        }

        return response()->json($enabledDates);
    }

    public function getBranchesForDateAndSupplier(Request $request)
    {
        $request->validate([
            'supplier_code' => 'required|string|exists:suppliers,supplier_code',
            'order_date' => 'required|date',
        ]);

        $supplierCode = $request->input('supplier_code');
        $orderDate = \Carbon\Carbon::parse($request->input('order_date'));
        $dayName = strtoupper($orderDate->format('l'));

        $user = \Illuminate\Support\Facades\Auth::user();
        $user->load('store_branches');

        $finalBranches = $user->store_branches->filter(function ($branch) use ($supplierCode, $dayName) {
            return $branch->delivery_schedules()
                ->where('delivery_schedules.day', $dayName)
                ->wherePivot('variant', $supplierCode)
                ->exists();
        });

        $activeBranches = $finalBranches->where('is_active', true);

        $options = $activeBranches->mapWithKeys(function ($branch) {
            return [$branch->id => $branch->name . ' (' . $branch->brand_code . ')'];
        });

        return response()->json($options);
    }
}