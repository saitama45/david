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
use App\Http\Services\OrderingCutoffService;
use App\Http\Services\RuleExceptionService;
use App\Http\Services\RuleExceptions\MassOrderLateOrderEvaluator;
use Exception;
use Illuminate\Support\Facades\DB;

class MassOrdersController extends Controller
{
    /** @see SupplierItems::CPO_CONSOLIDATED_SUPPLIER_CODES — the catalogue rule lives on the model. */
    private const CPO_CONSOLIDATED_SUPPLIER_CODES = SupplierItems::CPO_CONSOLIDATED_SUPPLIER_CODES;

    protected $massOrderService;
    protected $storeOrderService;

    public function __construct(
        MassOrderService $massOrderService,
        \App\Http\Services\StoreOrderService $storeOrderService,
        private OrderingCutoffService $cutoffs,
        private RuleExceptionService $ruleExceptions,
    ) {
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

        $rolesAndBranches = \App\Models\User::rolesAndAssignedBranches();

        $query = \App\Models\StoreOrder::with(['supplier', 'store_branch', 'delivery_receipts'])
            ->where('variant', 'mass regular')
            // Number of lines receiving has started on. Editing those is refused server-side
            // (StoreOrderService::guardAgainstReceivedItemChanges); untouched worksheet
            // placeholders do not count. Used to swap the edit button for a hint.
            // withCount, not withExists: SQL Server rejects a bare EXISTS() as a select expression.
            ->withCount(['store_order_items as receiving_records' => fn ($q) => $q
                ->where(fn ($i) => $i
                    ->where('quantity_received', '>', 0)
                    ->orWhereHas('ordered_item_receive_dates', fn ($r) => $r
                        ->where(fn ($d) => $d->whereNotNull('received_date')
                            ->orWhereIn('status', ['approved', 'received']))))]);

        if (! $rolesAndBranches['isAdmin']) {
            $query->whereIn('store_branch_id', $rolesAndBranches['assignedBranches']);
        }

        if ($request->filled('search')) {
            $searchTerm = $request->input('search');
            $query->where(function($q) use ($searchTerm) {
                $q->where('order_number', 'like', '%' . $searchTerm . '%')
                  ->orWhereHas('delivery_receipts', function($drq) use ($searchTerm) {
                      $drq->where('sap_so_number', 'like', '%' . $searchTerm . '%');
                  });
            });
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('order_date', [$request->input('from'), $request->input('to')]);
        }

        if ($request->filled('branchId') && $request->input('branchId') !== 'all') {
            $query->where('store_branch_id', $request->input('branchId'));
        }

        if ($request->filled('filterQuery') && $request->input('filterQuery') !== 'all') {
            $query->where('order_status', $request->input('filterQuery'));
        }

        $massOrders = $query->latest()->paginate(15)->withQueryString();

        // Orders on this page the user holds an approved one-time edit exception for.
        $editGrants = \App\Models\RuleExceptionRequest::query()
            ->where('rule_key', 'mass_order.edit_after_cutoff')
            ->where('status', \App\Enums\RuleExceptionStatus::APPROVED->value)
            ->where('valid_until', '>=', $this->cutoffs->now()->format('Y-m-d H:i:s'))
            ->whereIn('subject_key', collect($massOrders->items())->pluck('order_number')->all() ?: ['__none__'])
            ->pluck('subject_key')
            ->values();

        $exceptionStoreOptions = $user->store_branches()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['store_branches.id', 'store_branches.name'])
            ->map(fn ($store) => ['value' => (int) $store->id, 'label' => $store->name])
            ->values();

        $suppliers = $user->suppliers()
            ->where('is_active', true)
            ->get()
            ->map(function ($supplier) {
                return [
                    'label' => $supplier->name . ' (' . $supplier->supplier_code . ')',
                    'value' => $supplier->supplier_code,
                ];
            });

        $branches = StoreBranch::options();

        return Inertia::render('MassOrders/Index', [
            'massOrders' => $massOrders,
            'suppliers' => $suppliers,
            'ordersCutoff' => OrdersCutoff::all(),
            'currentDate' => Carbon::now('Asia/Manila')->toDateString(),
            // Full Manila timestamp for the edit-window check. currentDate stays
            // date-only because the calendar widget parses it as one.
            'serverNow' => Carbon::now('Asia/Manila')->format('Y-m-d H:i:s'),
            'branches' => $branches,
            'filters' => $request->only(['search', 'from', 'to', 'branchId', 'filterQuery']),
            'canViewCost' => Auth::user()->hasPermissionTo('view cost mass orders'),
            'editGrants' => $editGrants,
            'exceptionStoreOptions' => $exceptionStoreOptions,
        ]);
    }

    public function uploadMassOrder(Request $request)
    {
        $request->validate([
            'mass_order_file' => 'required|file|mimes:xlsx,xls',
            'supplier_code' => 'required|string|exists:suppliers,supplier_code',
            'order_date' => 'required|date',
            // Removed 'order_status' validation
        ]);

        try {
            $supplierCodeFromDropdown = $request->input('supplier_code');

            if (! $this->hasSupplierAccess($supplierCodeFromDropdown)) {
                return redirect()->back()->with([
                    'success' => false,
                    'message' => 'You do not have access to use the selected ordering template.',
                    'created_count' => 0,
                ]);
            }

            // Fetch the supplier to check the approval flag
            $supplier = Supplier::where('supplier_code', $supplierCodeFromDropdown)->firstOrFail();
            $determinedOrderStatus = $supplier->is_forapproval_massorders ? 'pending' : 'approved';

            $import = new MassOrderImport();
            $rows = Excel::toCollection($import, $request->file('mass_order_file'))->first();

            // Validate that the ordering_template in each row matches the selected supplier
            foreach ($rows as $index => $row) {
                if (isset($row['ordering_template']) && $row['ordering_template']) {
                    $fileTemplate = trim($row['ordering_template']);
                    $isValid = strcasecmp($fileTemplate, $supplierCodeFromDropdown) === 0;
                    
                    // Special case for DROPS: allow both 'DROPS' and 'FRUITS AND VEGETABLES'
                    if ($supplierCodeFromDropdown === 'DROPS') {
                        $isValid = $isValid || strcasecmp($fileTemplate, 'FRUITS AND VEGETABLES') === 0;
                    }
                    
                    if (!$isValid) {
                        throw new \Exception("Upload failed. It seems you are using an incorrect template. The supplier code '{$fileTemplate}' in the file does not match the selected Ordering Template '{$supplierCodeFromDropdown}'.");
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
                $query = $branch->delivery_schedules()
                    ->wherePivot('variant', $supplierCodeFromDropdown);
                
                if ($supplierCodeFromDropdown !== 'CPO') {
                    $query->where('delivery_schedules.day', $dayName);
                }

                return $query->exists();
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

            // Ordering cutoff, enforced on the server. Past the cutoff, only stores
            // holding an approved one-time exception for this template + date may
            // order; each grant is consumed inside the upload transaction.
            $grantKeys = [];
            $dateBlock = $this->cutoffs->massOrderDateBlock($supplierCodeFromDropdown, $orderDate->toDateString());

            if ($dateBlock) {
                foreach ($finalBranches->where('is_active', true) as $branch) {
                    if (! in_array($branch->brand_code, $validUploadedStores, true)) {
                        continue;
                    }

                    $key = MassOrderLateOrderEvaluator::subjectKey($supplierCodeFromDropdown, $orderDate->toDateString(), (int) $branch->id);

                    if ($this->ruleExceptions->usableGrant('mass_order.late_order', $key)) {
                        $grantKeys[(int) $branch->id] = $key;
                    } else {
                        $invalidStores[] = $branch->brand_code;
                        $pre_skipped_stores[] = [
                            'brand_code' => $branch->brand_code,
                            'reason' => $dateBlock.' Request a business-rule exception to order for this date.',
                        ];
                    }
                }

                $validUploadedStores = array_values(array_diff($validUploadedStores, $invalidStores));

                if (empty($validUploadedStores)) {
                    return redirect()->back()->with([
                        'success' => false,
                        'message' => 'Upload blocked. '.$dateBlock.' No store in the file has an approved exception for this date.',
                        'skipped_stores' => $pre_skipped_stores,
                        'created_count' => 0,
                    ]);
                }
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

            $consumeGrant = $grantKeys
                ? function ($storeBranch, $order) use ($grantKeys) {
                    if (isset($grantKeys[(int) $storeBranch->id])) {
                        $this->ruleExceptions->consume('mass_order.late_order', $grantKeys[(int) $storeBranch->id], Auth::user(), 'store_order', $order->order_number);
                    }
                }
                : null;

            $result = $this->massOrderService->processMassOrderUpload($rows, $supplierCodeFromDropdown, $request->input('order_date'), $determinedOrderStatus, $consumeGrant);

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

        if (! $this->hasSupplierAccess($supplierCode)) {
            abort(403, 'You do not have access to use the selected ordering template.');
        }

        $orderDate = Carbon::parse($request->input('order_date'));
        $dayName = strtoupper($orderDate->format('l')); // "MONDAY", "TUESDAY", etc.

        $user = Auth::user();
        $user->load('store_branches');

        $finalBranches = $user->store_branches->filter(function ($branch) use ($supplierCode, $dayName) {
            $query = $branch->delivery_schedules()
                ->wherePivot('variant', $supplierCode);
            
            if ($supplierCode !== 'CPO') {
                $query->where('delivery_schedules.day', $dayName);
            }

            return $query->exists();
        });

        $dynamicHeaders = $finalBranches->where('is_active', true)->pluck('brand_code')->unique()->sort()->values()->all();

        $items = $this->getMassOrderSupplierItems($supplierCode);

        $staticHeaders = ['Category', 'Classification', 'Item Code', 'Item Name', 'Packaging Config', 'Unit'];

        $fileName = $request->input('filename', 'mass_order_template');
        return Excel::download(new MassOrderTemplateExport($items, $staticHeaders, $dynamicHeaders, $supplierCode), $fileName . '.xlsx');
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
        if (! $this->hasSupplierAccess($supplier_code)) {
            abort(403, 'You do not have access to use the selected ordering template.');
        }

        $enabledDates = $this->cutoffs->massOrderAvailableDates($supplier_code);

        // Dates the user's stores hold an approved late-order exception for stay
        // selectable; the upload still only accepts the granted stores.
        $grantedDates = \App\Models\RuleExceptionRequest::query()
            ->where('rule_key', 'mass_order.late_order')
            ->where('status', \App\Enums\RuleExceptionStatus::APPROVED->value)
            ->where('valid_until', '>=', $this->cutoffs->now()->format('Y-m-d H:i:s'))
            ->whereIn('store_branch_id', Auth::user()->store_branches()->pluck('store_branches.id'))
            ->get(['context'])
            ->filter(fn ($grant) => ($grant->context['supplier_code'] ?? null) === $supplier_code)
            ->map(fn ($grant) => $grant->context['order_date'])
            ->all();

        $enabledDates = array_values(array_unique(array_merge($enabledDates, $grantedDates)));
        sort($enabledDates);

        return response()->json($enabledDates);
    }

    public function getItems($supplier_code)
    {
        if (! $this->hasSupplierAccess($supplier_code)) {
            abort(403, 'You do not have access to use the selected ordering template.');
        }

        $items = $this->getMassOrderSupplierItems($supplier_code)
            ->sortBy(function ($item) {
                $sortOrder = $item->sort_order ?? 0;
                return $sortOrder == 0 ? PHP_INT_MAX : $sortOrder;
            })
            ->values()
            ->map(function ($item) {
                return [
                    'value' => (string) $item->ItemCode,
                    'label' => "{$item->item_name} ({$item->ItemCode}) {$item->uom}",
                    'supplierCode' => $item->SupplierCode,
                ];
            })
            ->values();

        return response()->json([
            'items' => $items,
        ]);
    }

    private function hasSupplierAccess(string $supplierCode): bool
    {
        return Auth::user()->suppliers()->where('suppliers.supplier_code', $supplierCode)->exists();
    }

    private function isCpoSupplierCode(?string $supplierCode): bool
    {
        return strtoupper(trim((string) $supplierCode)) === 'CPO';
    }

    private function getMassOrderSupplierItems(string $supplierCode)
    {
        // Shared with receiving, so an item that can be ordered can also be received unlisted.
        return SupplierItems::forSupplierCode($supplierCode);
    }

    public function show($id)
    {
        // Increase execution time and memory limits for large datasets
        set_time_limit(120); // 2 minutes
        ini_set('memory_limit', '512M');
        
        // Set database timeout for SQL Server
        try {
            DB::statement('SET LOCK_TIMEOUT 120000'); // 2 minutes in milliseconds
        } catch (\Exception $e) {
            // Ignore if command fails
        }
        
        // Single optimized query with all necessary relationships
        $order = \App\Models\StoreOrder::select([
            'id', 'order_number', 'order_date', 'order_status', 'variant',
            'supplier_id', 'store_branch_id', 'encoder_id', 'approver_id', 'approval_action_date'
        ])->with([
            'supplier:id,name,supplier_code',
            'store_branch:id,name,brand_code', 
            'encoder:id,first_name,last_name',
            'approver:id,first_name,last_name',
            'delivery_receipts:id,store_order_id,delivery_receipt_number,remarks,created_at',
            'image_attachments:id,store_order_id,file_path',
            'store_order_items' => function($query) {
                $query->select([
                    'id', 'store_order_id', 'item_code', 'quantity_ordered', 
                    'quantity_approved', 'quantity_commited', 'quantity_received',
                    'uom', 'committed_by'
                ])->with([
                    'supplierItem:ItemCode,item_name,category',
                    'supplierItem.sapMasterfiles:id,ItemCode,AltUOM,BaseUOM,BaseQty',
                    'committedBy:id,first_name,last_name'
                ]);
            }
        ])->where('order_number', $id)->firstOrFail();

        // Statuses in which an order is past commitment and awaiting delivery. Shared with
        // the receiving module so both pages agree on what "committed" means.
        $receivableStatuses = "'".implode("','", \App\Http\Services\OrderReceivingService::RECEIVING_STATUSES)."'";

        // Optimized receiving history query mimicking the provided SQL logic
        $receiveDatesHistory = DB::table('store_order_items as soi')
            ->join('store_orders as so', 'so.id', '=', 'soi.store_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'so.supplier_id')
            // Both catalogs are joined through a one-row-per-key subquery. Joining
            // them directly duplicates the line: neither (ItemCode, AltUOM) nor
            // (ItemCode, SupplierCode, uom) is unique, so an item carrying more
            // than one catalog row was listed once per row — the same ordered
            // quantity appearing as two identical lines.
            ->leftJoinSub(\App\Models\SAPMasterfile::singleRowPerJoinKey(), 'sm', function($join) {
                $join->on('sm.ItemCode', '=', 'soi.item_code')
                     ->on('sm.AltUOM', '=', 'soi.uom');
            })
            ->leftJoinSub(\App\Models\SupplierItems::singleRowPerJoinKey(), 'si', function($join) {
                $join->on('si.ItemCode', '=', 'sm.ItemCode')
                     ->on('si.uom', '=', 'sm.AltUOM')
                     ->on('si.SupplierCode', '=', 's.supplier_code');
            })
            ->leftJoin('ordered_item_receive_dates as receive', 'receive.store_order_item_id', '=', 'soi.id')
            ->leftJoin('users as u', 'u.id', '=', 'receive.received_by_user_id')
            ->where('so.id', $order->id)
            ->orderByRaw("CASE WHEN ISNULL([si].[sort_order], 0) = 0 THEN 1 ELSE 0 END, [si].[sort_order]")
            ->select([
                'receive.id as id',
                'soi.id as store_order_item_id',
                'si.category',
                'soi.item_code',
                'sm.ItemDescription as item_name',
                'sm.BaseUOM',
                'soi.uom',
                'soi.quantity_ordered',
                'soi.quantity_approved',
                'soi.quantity_commited', // Retain original for variance calculation
                'receive.quantity_received', // Retain original for variance calculation
                'receive.received_date',
                'receive.remarks',
                'receive.expiry_date',
                'u.first_name as received_by_first_name',
                'u.last_name as received_by_last_name',
                // Mirrors the receiving page: a row counts as received once it is 'received'
                // (saved by the receiver) or 'approved' (swept into stock by Confirm Receive).
                // Commitment is read from the order status, not from committed_by — orders are
                // auto-committed from approval onwards, and a line committed that way carries
                // no committer.
                DB::raw("CASE
                    WHEN [receive].[status] IN ('approved', 'received') THEN 'RECEIVED'
                    WHEN [so].[order_status] IN ({$receivableStatuses}) THEN 'TO RECEIVE'
                    ELSE 'TO COMMIT'
                END as display_status"),
                DB::raw("[soi].[quantity_commited] as committed_display"),
                DB::raw("CASE
                    WHEN [receive].[status] IN ('approved', 'received') THEN [receive].[quantity_received]
                    ELSE 0
                END as received_display"),
            ])
            ->get();

        // Post-processing to ensure unique IDs for null records and calculate variances
        $receiveDatesHistory->transform(function($item) {
             if (is_null($item->id)) {
                 $item->id = 'pending_' . $item->store_order_item_id;
                 $item->received_date = null;
                 $item->remarks = null;
                 $item->expiry_date = null;
                 $item->received_by_first_name = null;
                 $item->received_by_last_name = null;
             }
             
             // Variances calculation based on SQL logic, using the original quantities
             $item->variance_ordered_committed = $item->committed_display - $item->quantity_ordered;
             
             // Variance (Committed vs Received): isnull(receive.quantity_received - soi.quantity_commited, 0)
             // Using the displayed quantities for variance calculation in the frontend, for consistency.
             $item->variance_committed_received = $item->received_display - $item->committed_display;

             return $item;
        });
        
        return \Inertia\Inertia::render('MassOrders/Show', [
            'order' => $order,
            'orderedItems' => $order->store_order_items,
            'receiveDatesHistory' => $receiveDatesHistory,
            'images' => $order->image_attachments,
            'canViewCost' => Auth::user()->hasPermissionTo('view cost mass orders'),
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
        $enabledDates = $this->cutoffs->massOrderAvailableDates($initialSupplierCode);
        // --- END: Get initial enabled dates ---


        return \Inertia\Inertia::render('MassOrders/Edit', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'branches' => $branches,
            'suppliers' => $suppliers,
            'enabledDates' => $enabledDates, // Pass new prop
            'canViewCost' => Auth::user()->hasPermissionTo('view cost mass orders'),
        ]);
    }

    public function update(\App\Http\Requests\StoreOrder\UpdateOrderRequest $request, $id)
    {
        $storeOrder = \App\Models\StoreOrder::where('order_number', $id)->firstOrFail();
        $order = $storeOrder->load('store_order_items');
        $validatedData = $request->validated();

        // Edit rules, enforced on the server. Status is never waivable; the edit
        // cutoff can be lifted once by an approved business-rule exception.
        $supplierCode = (string) $order->supplier?->supplier_code;
        $now = $this->cutoffs->now();

        if (! in_array($order->order_status, $this->cutoffs->massOrderEditableStatuses($supplierCode), true)) {
            return back()->withErrors(['error' => "This order is already {$order->order_status} and can no longer be edited."]);
        }

        $newDate = Carbon::parse($validatedData['order_date'])->toDateString();
        if ($newDate !== Carbon::parse($order->order_date)->toDateString()
            && ($dateBlock = $this->cutoffs->massOrderDateBlock((string) $validatedData['supplier_id'], $newDate, $now))) {
            return back()->withErrors(['error' => $dateBlock]);
        }

        $editDeadline = $this->cutoffs->massOrderEditDeadline($supplierCode, $order->created_at);
        $usesException = $editDeadline && $now->gte($editDeadline);

        if ($usesException) {
            if (! $this->ruleExceptions->usableGrant('mass_order.edit_after_cutoff', (string) $order->order_number)) {
                return back()->withErrors([
                    'error' => 'The edit cutoff for this order passed on '.$editDeadline->format('M j, Y g:i A').'. Request a business-rule exception to edit it.',
                ]);
            }

            if ((string) $validatedData['supplier_id'] !== $supplierCode || (int) $validatedData['branch_id'] !== (int) $order->store_branch_id) {
                return back()->withErrors(['error' => 'Under an exception only the items, quantities and delivery date can change, not the store or template.']);
            }
        }

        try {
            // Force the variant to 'mass regular' for mass orders
            $validatedData['variant'] = 'mass regular';

            DB::transaction(function () use ($order, $validatedData, $usesException) {
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

                if ($usesException) {
                    $this->ruleExceptions->consume('mass_order.edit_after_cutoff', (string) $order->order_number, Auth::user(), 'store_order', $order->order_number);
                }
            });

            return redirect()->route('mass-orders.index')->with('success', 'Order updated successfully!');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
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
            $query = $branch->delivery_schedules()
                ->wherePivot('variant', $supplierCode);
            
            if ($supplierCode !== 'CPO') {
                $query->where('delivery_schedules.day', $dayName);
            }

            return $query->exists();
        });

        $activeBranches = $finalBranches->where('is_active', true);

        $options = $activeBranches->mapWithKeys(function ($branch) {
            return [$branch->id => $branch->name . ' (' . $branch->brand_code . ')'];
        });

        return response()->json($options);
    }
}
