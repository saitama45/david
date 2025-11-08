<?php

namespace App\Http\Controllers;

use App\Models\OrdersCutoff;
use App\Models\Supplier;
use App\Models\SupplierItems;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use App\Exports\MassOrderTemplateExport;
use App\Models\DTSDeliverySchedule;
use App\Models\StoreBranch;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\MassOrderImport;
use App\Http\Services\MassOrderService;
use Exception;
use Illuminate\Support\Facades\DB;

class MassOrdersController extends Controller
{
    protected $massOrderService;
    protected $storeOrderService;

    public function __construct(MassOrderService $massOrderService, \App\Http\Services\StoreOrderService $storeOrderService)
    {
        $this->massOrderService = $massOrderService;
        $this->storeOrderService = $storeOrderService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $user->load('store_branches');
        $branchIds = $user->store_branches->pluck('id');

        $query = \App\Models\StoreOrder::with(['supplier', 'store_branch'])
            ->where('variant', 'mass regular')
            ->whereIn('store_branch_id', $branchIds);

        if ($request->filled('search')) {
            $query->where('order_number', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('order_date', [$request->input('from'), $request->input('to')]);
        }

        if ($request->filled('branchId')) {
            $query->where('store_branch_id', $request->input('branchId'));
        }

        if ($request->filled('filterQuery') && $request->input('filterQuery') !== 'all') {
            $query->where('order_status', $request->input('filterQuery'));
        }

        $massOrders = $query->latest()->paginate(15)->withQueryString();

        $suppliers = $user->suppliers()
            ->where('is_active', true)
            ->get()
            ->map(function ($supplier) {
                return [
                    'label' => $supplier->name . ' (' . $supplier->supplier_code . ')',
                    'value' => $supplier->supplier_code,
                ];
            });

        $branches = $user->store_branches->pluck('name', 'id');

        return Inertia::render('MassOrders/Index', [
            'massOrders' => $massOrders,
            'suppliers' => $suppliers,
            'ordersCutoff' => OrdersCutoff::all(),
            'currentDate' => Carbon::now()->toDateString(),
            'branches' => $branches,
            'filters' => $request->only(['search', 'from', 'to', 'branchId', 'filterQuery']),
        ]);
    }

    public function uploadMassOrder(Request $request)
    {
        $request->validate([
            'mass_order_file' => 'required|file|mimes:xlsx,xls',
            'supplier_code' => 'required|string|exists:suppliers,supplier_code',
            'order_date' => 'required|date',
        ]);

        try {
            $supplierCodeFromDropdown = $request->input('supplier_code');

            $import = new MassOrderImport();
            $rows = Excel::toCollection($import, $request->file('mass_order_file'))->first();

            // Validate that the supplier_code in each row matches the selected supplier
            foreach ($rows as $index => $row) {
                if (isset($row['supplier_code']) && $row['supplier_code']) {
                    if (strcasecmp(trim($row['supplier_code']), $supplierCodeFromDropdown) !== 0) {
                        $rowNumber = $index + 2; // +1 for 1-based index, +1 for header row
                        throw new \Exception("Upload failed. The supplier code '{$row['supplier_code']}' in row {$rowNumber} does not match the selected supplier '{$supplierCodeFromDropdown}'.");
                    }
                }
            }

            // 1. Get list of ALL store brand codes from DB, and the valid ones for this specific order.
            $allBrandCodes = \App\Models\StoreBranch::pluck('brand_code')->all();
            
            $orderDate = Carbon::parse($request->input('order_date'));
            $dayName = strtoupper($orderDate->format('l'));
            $user = Auth::user();
            $user->load('store_branches');

            $finalBranches = $user->store_branches->filter(function ($branch) use ($supplierCodeFromDropdown, $dayName) {
                return $branch->delivery_schedules()
                    ->where('delivery_schedules.day', $dayName)
                    ->wherePivot('variant', $supplierCodeFromDropdown)
                    ->exists();
            });
            $validStoresForThisOrder = $finalBranches->where('is_active', true)->pluck('brand_code')->all();

            // 2. Get headers from uploaded file.
            $headerRow = $rows->first() ? $rows->first()->keys()->toArray() : [];

            // 3. Identify which headers in the file are meant to be store columns.
            $uploadedStoreColumns = [];
            foreach ($allBrandCodes as $dbBrandCode) {
                $sluggedDbBrandCode = \Illuminate\Support\Str::slug($dbBrandCode, '_');
                foreach ($headerRow as $header) {
                    if ($sluggedDbBrandCode === $header) {
                        $uploadedStoreColumns[] = $dbBrandCode; // Use the real name
                    }
                }
            }
            $uploadedStoreColumns = array_unique($uploadedStoreColumns);

            // 4. Find the invalid stores by comparing the identified store columns against the valid list for this order.
            $invalidStores = array_udiff($uploadedStoreColumns, $validStoresForThisOrder, 'strcasecmp');
            $validUploadedStores = array_intersect($uploadedStoreColumns, $validStoresForThisOrder);

            $pre_skipped_stores = [];
            if (!empty($invalidStores)) {
                foreach ($invalidStores as $brandCode) {
                    $pre_skipped_stores[] = [
                        'brand_code' => $brandCode,
                        'reason' => 'Store is not on the delivery schedule for the selected date.'
                    ];
                }
            }

            // If there are no valid stores in the uploaded file for the selected date, stop processing.
            if (empty($validUploadedStores)) {
                return redirect()->back()->with([
                    'success' => false,
                    'message' => 'Upload failed. No stores in the uploaded file are on the delivery schedule for the selected date.',
                    'skipped_stores' => $pre_skipped_stores,
                    'created_count' => 0,
                ]);
            }

            // Remove invalid store columns from the data before passing to the service
            if (!empty($invalidStores)) {
                $invalidStoreHeaders = [];
                foreach ($invalidStores as $brandCode) {
                    $invalidStoreHeaders[] = \Illuminate\Support\Str::slug($brandCode, '_');
                }

                $rows = $rows->map(function ($row) use ($invalidStoreHeaders) {
                    foreach ($invalidStoreHeaders as $header) {
                        unset($row[$header]);
                    }
                    return $row;
                });
            }

            $result = $this->massOrderService->processMassOrderUpload($rows, $supplierCodeFromDropdown, $request->input('order_date'));

            // Merge pre-validation skipped stores with the result from the service
            $all_skipped_stores = array_merge($pre_skipped_stores, $result['skipped_stores']);
            $unique_skipped_stores = collect($all_skipped_stores)->unique('brand_code')->values()->all();

            $created_count = 0;
            if (isset($result['message']) && preg_match('/created\s+(\d+)\s+store\s+order\(s\)/i', $result['message'], $matches)) {
                $created_count = (int) $matches[1];
            }

            return redirect()->back()->with([
                'success' => $result['success'],
                'message' => $result['message'],
                'skipped_stores' => $unique_skipped_stores,
                'created_count' => $created_count,
            ]);

        } catch (Exception $e) {
            return redirect()->back()->with([
                'success' => false,
                'message' => 'Error processing file: ' . $e->getMessage(),
            ]);
        }
    }

    public function downloadTemplate(Request $request)
    {
        $request->validate([
            'supplier_code' => 'required|string|exists:suppliers,supplier_code',
            'order_date' => 'required|date',
        ]);

        $supplierCode = $request->input('supplier_code');
        $orderDate = Carbon::parse($request->input('order_date'));
        $dayName = strtoupper($orderDate->format('l')); // "MONDAY", "TUESDAY", etc.

        $user = Auth::user();
        $user->load('store_branches');

        $finalBranches = $user->store_branches->filter(function ($branch) use ($supplierCode, $dayName) {
            return $branch->delivery_schedules()
                ->where('delivery_schedules.day', $dayName)
                ->wherePivot('variant', $supplierCode)
                ->exists();
        });

        $dynamicHeaders = $finalBranches->where('is_active', true)->pluck('brand_code')->unique()->sort()->values()->all();

        $items = SupplierItems::where('SupplierCode', $supplierCode)->where('is_active', true)->get();

        $staticHeaders = ['Category', 'Classification', 'Item Code', 'Item Name', 'Packaging Config', 'Unit'];

        return Excel::download(new MassOrderTemplateExport($items, $staticHeaders, $dynamicHeaders), 'mass_order_template.xlsx');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }
    
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function getAvailableDates($supplier_code)
    {
        $cutoff = \App\Models\OrdersCutoff::where('ordering_template', $supplier_code)->first();
        if (!$cutoff) {
            return response()->json([]);
        }

        $now = Carbon::now('Asia/Manila');

        $getCutoffDate = function($day, $time) use ($now) {
            if (!$day || !$time) return null;
            $dayIndex = ($day == 7) ? 0 : $day;
            return $now->copy()->startOfWeek(Carbon::SUNDAY)->addDays($dayIndex)->setTimeFromTimeString($time);
        };

        $cutoff1Date = $getCutoffDate($cutoff->cutoff_1_day, $cutoff->cutoff_1_time);
        $cutoff2Date = $getCutoffDate($cutoff->cutoff_2_day, $cutoff->cutoff_2_time);

        $daysToCoverStr = '';
        $weekOffset = 0; // How many weeks to add to the current week's start

        // Determine which set of days and which week to use
        if ($cutoff1Date && $now->lt($cutoff1Date)) {
            $daysToCoverStr = $cutoff->days_covered_1;
            // If it's a GSI supplier, the delivery is always next week.
            $weekOffset = str_starts_with($supplier_code, 'GSI') ? 1 : 0;
        } elseif ($cutoff2Date && $now->lt($cutoff2Date)) {
            $daysToCoverStr = $cutoff->days_covered_2;
            // If it's a GSI supplier, the delivery is always next week.
            $weekOffset = str_starts_with($supplier_code, 'GSI') ? 1 : 0;
        } else {
            // After all cutoffs, it's next week for most, but week-after-next for GSI.
            $daysToCoverStr = $cutoff->days_covered_1;
            if (str_starts_with($supplier_code, 'GSI')) {
                $weekOffset = 2;
            } else {
                $weekOffset = 1;
            }
        }

        $startOfTargetWeek = $now->copy()->startOfWeek(Carbon::SUNDAY)->addWeeks($weekOffset);

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

    public function getItems($supplier_code)
    {
        $items = \App\Models\SupplierItems::where('supplier_code', $supplier_code)->get();
        return response()->json($items);
    }

    public function show($id)
    {
        $order = $this->storeOrderService->getOrderDetails($id);
        $orderedItems = $this->storeOrderService->getOrderItems($order);
        $orderedItems->load('supplierItem.sapMasterfiles');

        return \Inertia\Inertia::render('MassOrders/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'receiveDatesHistory' => $order->ordered_item_receive_dates,
            'images' => $this->storeOrderService->getImageAttachments($order)
        ]);
    }

    public function edit($id)
    {
        $order = $this->storeOrderService->getOrder($id);
        $orderedItems = $this->storeOrderService->getOrderItems($order);
        $orderedItems->load('supplierItem.sapMasterfiles');
        $suppliers = \App\Models\Supplier::options();

        // --- START: Get initial available branches ---
        $initialSupplierCode = $order->supplier->supplier_code;
        $initialOrderDate = Carbon::parse($order->order_date);
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
        // --- END: Get initial available branches ---


        // --- START: Get initial enabled dates ---
        $enabledDates = [];
        $cutoff = \App\Models\OrdersCutoff::where('ordering_template', $initialSupplierCode)->first();
        if ($cutoff) {
            $now = Carbon::now('Asia/Manila');
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
                if (str_starts_with($initialSupplierCode, 'GSI')) {
                    $weekOffset = 2;
                } else {
                    $weekOffset = 1;
                }
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
        // --- END: Get initial enabled dates ---


        return \Inertia\Inertia::render('MassOrders/Edit', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'branches' => $branches,
            'suppliers' => $suppliers,
            'enabledDates' => $enabledDates, // Pass new prop
        ]);
    }

    public function update(\App\Http\Requests\StoreOrder\UpdateOrderRequest $request, $id)
    {
        $storeOrder = \App\Models\StoreOrder::where('order_number', $id)->firstOrFail();
        $order = $storeOrder->load('store_order_items');
        try {
            $validatedData = $request->validated();
            // Force the variant to 'mass regular' for mass orders
            $validatedData['variant'] = 'mass regular';

            $this->storeOrderService->updateOrder($order, $validatedData);

            // Refresh the order to get the latest items including newly added ones
            $order->refresh();

            // After the order and its items are updated by the service,
            // update the approved and committed quantities for mass orders.
            $order->storeOrderItems()->update([
                'quantity_approved' => \Illuminate\Support\Facades\DB::raw('quantity_ordered'),
                'quantity_commited' => \Illuminate\Support\Facades\DB::raw('quantity_ordered'),
                'committed_by' => \Illuminate\Support\Facades\Auth::id(),
                'committed_date' => now(),
            ]);

            return redirect()->route('mass-orders.index')->with('success', 'Order updated successfully!');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Error updating store order from MassOrders: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to update order: ' . $e->getMessage()]);
        }
    }

    public function getBranchesForDateAndSupplier(Request $request)
    {
        $request->validate([
            'supplier_code' => 'required|string|exists:suppliers,supplier_code',
            'order_date' => 'required|date',
        ]);

        $supplierCode = $request->input('supplier_code');
        $orderDate = Carbon::parse($request->input('order_date'));
        $dayName = strtoupper($orderDate->format('l'));

        $user = Auth::user();
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
