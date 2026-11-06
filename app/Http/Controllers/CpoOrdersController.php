<?php

namespace App\Http\Controllers;

use App\Exports\MassOrderTemplateExport;
use App\Http\Services\CpoOrderService;
use App\Http\Services\MassOrderService;
use App\Http\Services\MonthEndClosedPeriodService;
use App\Imports\MassOrderImport;
use App\Models\CpoOrderAttachment;
use App\Models\CpoVendor;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\SupplierItems;
use App\Models\User;
use App\Support\EntityContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

/**
 * CPO Orders: purchases from outside vendors, placed through the mass order template, then
 * approved in Mass Orders Approval. The vendor may be left blank here: the store enters it and
 * uploads its invoice / receipt in Inbound Orders when it receives the order.
 */
class CpoOrdersController extends Controller
{
    private const NOT_ASSIGNED = 'The CPO ordering template is not assigned to you. Ask your administrator to assign it in Users.';

    public function __construct(
        private CpoOrderService $cpoOrders,
        private MassOrderService $massOrders,
        private MonthEndClosedPeriodService $closedPeriods,
    ) {}

    public function index(Request $request)
    {
        $access = User::rolesAndAssignedBranches();

        $query = StoreOrder::with(['supplier', 'store_branch', 'delivery_receipts'])
            ->where('variant', 'mass regular')
            ->whereHas('supplier', fn ($q) => $q->where('supplier_code', Supplier::CPO))
            ->withCount(['cpoAttachments', 'image_attachments'])
            ->tap(fn ($q) => CpoOrderService::withReceivingRecords($q));

        if (! $access['isAdmin']) {
            $query->whereIn('store_branch_id', $access['assignedBranches']);
        }

        if ($request->filled('search')) {
            $term = $request->input('search');
            $query->where(fn ($q) => $q->where('order_number', 'like', "%{$term}%")
                ->orWhere('cpo_vendor_name', 'like', "%{$term}%"));
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

        $cpoStores = $this->cpoOrders->scheduledStores(Auth::user());
        $orders = $query->latest()->paginate(15)->withQueryString();

        // The edit button shows only where the server would take the edit.
        $canEdit = Auth::user()->can('edit cpo orders') && $this->assignedCpo();
        $orders->getCollection()->each(fn ($order) => $order->setAttribute(
            'editable',
            $canEdit && $this->cpoOrders->editProblem($order) === null
        ));

        return Inertia::render('CpoOrders/Index', [
            'orders' => $orders,
            'branches' => StoreBranch::options(),
            'filters' => $request->only(['search', 'from', 'to', 'branchId', 'filterQuery']),
            'currentDate' => Carbon::now('Asia/Manila')->toDateString(),
            // Last date closed for every CPO store of the user (final approved month end counts).
            'closedForAllStores' => $this->closedPeriods->closedForAll($cpoStores->pluck('id')),
            'hasCpoStores' => $cpoStores->isNotEmpty(),
            // Placing an order also needs the CPO template assigned to the user, as in Mass Orders.
            'cpoAssigned' => $this->assignedCpo(),
        ]);
    }

    public function show(string $orderNumber)
    {
        $order = $this->findCpoOrder($orderNumber);

        return Inertia::render('MassOrders/Show', $this->massOrders->orderDetails($orderNumber) + [
            'canViewCost' => true,
            'cpo' => [
                'vendor_name' => $order->cpo_vendor_name,
                'vendor_tin' => $order->cpo_vendor_tin,
                'vendor_address' => $order->cpo_vendor_address,
                'attachments' => $order->invoiceFiles(),
            ],
        ]);
    }

    /** Edit a CPO order that is still waiting for its last approval, as Mass Orders edits one. */
    public function edit(string $orderNumber)
    {
        abort_unless($this->assignedCpo(), 403, self::NOT_ASSIGNED);
        $order = $this->findCpoOrder($orderNumber);

        if ($problem = $this->cpoOrders->editProblem($order)) {
            return redirect()->route('cpo-orders.index')->withErrors(['error' => $problem]);
        }

        $items = $order->store_order_items()->orderBy('id')->get();
        $names = SupplierItems::whereIn('ItemCode', $items->pluck('item_code')->filter()->unique()->values()->all() ?: ['__none__'])
            ->pluck('item_name', 'ItemCode');
        $units = $this->cpoOrders->unitsByItem($items->pluck('item_code')->filter()->unique()->values());

        // The order's own store stays listed even if it is no longer set up for CPO.
        $stores = $this->cpoOrders->scheduledStores(Auth::user())
            ->push($order->store_branch)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->map(fn ($store) => ['id' => (int) $store->id, 'name' => $store->name, 'brand_code' => $store->brand_code])
            ->values();

        $deadline = $this->cpoOrders->editDeadline($order);

        return Inertia::render('CpoOrders/Edit', [
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'order_date' => Carbon::parse($order->order_date)->toDateString(),
                'order_status' => $order->order_status,
                'store_branch_id' => (int) $order->store_branch_id,
                // The vendor details the order was placed with; kept while the vendor is not changed.
                'vendor' => $order->cpo_vendor_id ? [
                    'id' => (int) $order->cpo_vendor_id,
                    'name' => $order->cpo_vendor_name,
                    'tin' => $order->cpo_vendor_tin,
                    'address' => $order->cpo_vendor_address,
                ] : null,
            ],
            // The same vendor as the list has it now, for the picker and its Edit.
            'vendorOption' => $order->cpo_vendor_id ? CpoVendor::find($order->cpo_vendor_id)?->toOption() : null,
            'lines' => $items->map(fn ($item) => [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $names[$item->item_code] ?? $item->item_description,
                'uom' => $item->uom,
                // A unit no longer listed for the item is still offered, so the line shows as it is.
                'units' => collect($units[$item->item_code] ?? [])->push(strtoupper((string) $item->uom))->filter()->unique()->values(),
                'quantity' => (float) $item->quantity_ordered,
                'cost' => (float) $item->cost_per_quantity,
            ])->values(),
            'stores' => $stores,
            // Last closed date per store (final approved month end count); cast so none is {}.
            'closedThrough' => (object) $this->closedPeriods->closedThrough($stores->pluck('id')),
            'editDeadline' => $deadline?->format('M j, Y g:i A'),
        ]);
    }

    public function update(Request $request, string $orderNumber)
    {
        abort_unless($this->assignedCpo(), 403, self::NOT_ASSIGNED);
        $order = $this->findCpoOrder($orderNumber);

        $data = $request->validate([
            'order_date' => ['required', 'date'],
            'store_branch_id' => ['required', 'integer'],
            'cpo_vendor_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.item_code' => ['required', 'string'],
            'lines.*.uom' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.cost' => ['required', 'numeric', 'gt:0'],
        ], [
            'lines.required' => 'The order needs at least one item.',
            'lines.min' => 'The order needs at least one item.',
            'lines.*.item_code.required' => 'Choose the item.',
            'lines.*.uom.required' => 'Choose the unit.',
            'lines.*.quantity.required' => 'The quantity is required.',
            'lines.*.quantity.numeric' => 'The quantity must be a number.',
            'lines.*.quantity.gt' => 'The quantity must be greater than 0.',
            'lines.*.cost.required' => 'The cost is required.',
            'lines.*.cost.numeric' => 'The cost must be a number.',
            'lines.*.cost.gt' => 'The cost must be greater than 0.',
        ]);

        // Looked up through the entity scope, so another entity's vendor is not found.
        $vendor = isset($data['cpo_vendor_id']) ? CpoVendor::find((int) $data['cpo_vendor_id']) : null;
        if (isset($data['cpo_vendor_id']) && ! $vendor) {
            throw ValidationException::withMessages(['cpo_vendor_id' => 'Select a vendor from the list, or leave it blank.']);
        }

        $order = $this->cpoOrders->update($order, $data, $vendor, Auth::user());

        return redirect()->route('cpo-orders.index')->with([
            'success' => true,
            'message' => "CPO order {$order->order_number} was updated.",
        ]);
    }

    /** CPO list items for the Edit page's picker: nothing below MIN_SEARCH_CHARS but an exact code or name. */
    public function items(Request $request)
    {
        abort_unless($this->assignedCpo(), 403, self::NOT_ASSIGNED);

        return response()->json($this->cpoOrders->itemOptions((string) $request->input('q', '')));
    }

    public function downloadTemplate(Request $request)
    {
        abort_unless($this->assignedCpo(), 403, self::NOT_ASSIGNED);
        $request->validate(['order_date' => 'required|date']);

        $orderDate = Carbon::parse($request->input('order_date'))->toDateString();
        $stores = $this->cpoOrders->openStores(Auth::user(), $orderDate)
            ->pluck('brand_code')->filter()->unique()->sort()->values()->all();

        return Excel::download(
            new MassOrderTemplateExport($this->cpoOrders->catalogue(), CpoOrderService::TEMPLATE_HEADERS, $stores, Supplier::CPO),
            'CPO_'.Carbon::parse($orderDate)->format('m-d').'.xlsx'
        );
    }

    public function upload(Request $request)
    {
        abort_unless($this->assignedCpo(), 403, self::NOT_ASSIGNED);

        $request->validate([
            'order_date' => 'required|date',
            'cpo_vendor_id' => ['nullable', 'integer', Rule::exists('cpo_vendors', 'id')],
            'mass_order_file' => 'required|file|mimes:xlsx,xls',
        ]);

        // Looked up through the entity scope, so another entity's vendor is not found.
        $vendor = $request->filled('cpo_vendor_id') ? CpoVendor::find($request->integer('cpo_vendor_id')) : null;
        if ($request->filled('cpo_vendor_id') && ! $vendor) {
            throw ValidationException::withMessages(['cpo_vendor_id' => 'Select a vendor from the list, or leave it blank.']);
        }

        $rows = Excel::toCollection(new MassOrderImport, $request->file('mass_order_file'))->first() ?? collect();

        $result = $this->cpoOrders->processUpload(
            $rows,
            $request->input('order_date'),
            $vendor,
            Auth::user()
        );

        return redirect()->back()->with([
            'success' => $result['success'],
            'message' => $result['message'],
            'skipped_stores' => $result['skipped_stores'],
            'created_count' => $result['created_count'],
        ]);
    }

    /** Vendors for the picker: nothing below MIN_SEARCH_CHARS but an exact name. */
    public function vendors(Request $request)
    {
        abort_unless($this->assignedCpo(), 403, self::NOT_ASSIGNED);

        return response()->json(CpoVendor::optionsFor((string) $request->input('q', '')));
    }

    public function storeVendor(Request $request)
    {
        abort_unless($this->assignedCpo(), 403, self::NOT_ASSIGNED);
        $vendor = CpoVendor::create($this->validateVendor($request) + [
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json($vendor->toOption(), 201);
    }

    public function updateVendor(Request $request, CpoVendor $vendor)
    {
        abort_unless($this->assignedCpo(), 403, self::NOT_ASSIGNED);
        $vendor->update($this->validateVendor($request, $vendor) + ['updated_by' => Auth::id()]);

        return response()->json($vendor->toOption());
    }

    /** An invoice / receipt file, for a user who may see the order's store (also opened from Inbound Orders). */
    public function attachment(CpoOrderAttachment $attachment)
    {
        $order = StoreOrder::findOrFail($attachment->store_order_id);
        $access = User::rolesAndAssignedBranches();

        abort_unless($access['isAdmin'] || in_array($order->store_branch_id, $access['assignedBranches']), 403);
        abort_unless(Storage::disk(CpoOrderAttachment::DISK)->exists($attachment->file_path), 404, 'The file is no longer available.');

        return Storage::disk(CpoOrderAttachment::DISK)->response($attachment->file_path, $attachment->original_name, [
            'Content-Disposition' => 'inline; filename="'.addcslashes($attachment->original_name, '"\\').'"',
        ]);
    }

    /** Whether the CPO ordering template is assigned to the user (Users > suppliers). */
    private function assignedCpo(): bool
    {
        return Auth::user()->suppliers()->where('suppliers.supplier_code', Supplier::CPO)->exists();
    }

    private function findCpoOrder(string $orderNumber): StoreOrder
    {
        $order = StoreOrder::where('order_number', $orderNumber)
            ->whereHas('supplier', fn ($q) => $q->where('supplier_code', Supplier::CPO))
            ->firstOrFail();

        $access = User::rolesAndAssignedBranches();
        abort_unless($access['isAdmin'] || in_array($order->store_branch_id, $access['assignedBranches']), 403);

        return $order;
    }

    /** Name and Address are required, TIN is optional; a name is used once per entity. */
    private function validateVendor(Request $request, ?CpoVendor $vendor = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('cpo_vendors', 'name')
                    ->where('entity_id', app(EntityContext::class)->id())
                    ->ignore($vendor?->id),
            ],
            'tin' => ['nullable', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:500'],
        ], [
            'name.required' => 'The Vendor Name is required.',
            'name.unique' => 'A vendor with this name already exists. Pick it from the list instead.',
            'address.required' => 'The Address is required.',
        ]);

        return [
            'name' => trim($data['name']),
            'tin' => isset($data['tin']) ? (trim($data['tin']) ?: null) : null,
            'address' => trim($data['address']),
        ];
    }
}
