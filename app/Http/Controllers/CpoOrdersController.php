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
 * CPO Orders: purchases from outside vendors, placed through the mass order template with
 * the vendor and its invoice / receipt, then approved in Mass Orders Approval.
 */
class CpoOrdersController extends Controller
{
    /** The most invoice / receipt files one upload takes, and the size of each (KB). */
    public const MAX_ATTACHMENTS = 5;

    public const MAX_ATTACHMENT_KB = 5120;

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
            ->withCount('cpoAttachments');

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

        return Inertia::render('CpoOrders/Index', [
            'orders' => $query->latest()->paginate(15)->withQueryString(),
            'branches' => StoreBranch::options(),
            'filters' => $request->only(['search', 'from', 'to', 'branchId', 'filterQuery']),
            'currentDate' => Carbon::now('Asia/Manila')->toDateString(),
            // Last date closed for every CPO store of the user (final approved month end counts).
            'closedForAllStores' => $this->closedPeriods->closedForAll($cpoStores->pluck('id')),
            'hasCpoStores' => $cpoStores->isNotEmpty(),
            'maxAttachments' => self::MAX_ATTACHMENTS,
            'maxAttachmentMb' => self::MAX_ATTACHMENT_KB / 1024,
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
                'attachments' => $order->cpoAttachments()->get(),
            ],
        ]);
    }

    public function downloadTemplate(Request $request)
    {
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
        $request->validate([
            'order_date' => 'required|date',
            'cpo_vendor_id' => ['required', 'integer', Rule::exists('cpo_vendors', 'id')],
            'mass_order_file' => 'required|file|mimes:xlsx,xls',
            'attachments' => 'required|array|min:1|max:'.self::MAX_ATTACHMENTS,
            'attachments.*' => 'required|file|mimes:jpg,jpeg,png,pdf|max:'.self::MAX_ATTACHMENT_KB,
        ], [
            'cpo_vendor_id.required' => 'Select the vendor the store bought from.',
            'attachments.required' => 'Attach the invoice or receipt.',
            'attachments.min' => 'Attach the invoice or receipt.',
            'attachments.max' => 'Attach at most '.self::MAX_ATTACHMENTS.' files.',
            'attachments.*.mimes' => 'An invoice or receipt must be a JPG, PNG or PDF file.',
            'attachments.*.max' => 'Each invoice or receipt file must be '.(self::MAX_ATTACHMENT_KB / 1024).' MB or smaller.',
        ]);

        // Looked up through the entity scope, so another entity's vendor is not found.
        $vendor = CpoVendor::find($request->integer('cpo_vendor_id'));
        if (! $vendor) {
            throw ValidationException::withMessages(['cpo_vendor_id' => 'Select the vendor the store bought from.']);
        }

        $rows = Excel::toCollection(new MassOrderImport, $request->file('mass_order_file'))->first() ?? collect();

        $result = $this->cpoOrders->processUpload(
            $rows,
            $request->input('order_date'),
            $vendor,
            $request->file('attachments'),
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
        $term = trim((string) $request->input('q', ''));

        if ($term === '') {
            return response()->json([]);
        }

        return response()->json(
            CpoVendor::search($term)->limit(CpoVendor::SEARCH_LIMIT)->get()->map->toOption()->values()
        );
    }

    public function storeVendor(Request $request)
    {
        $vendor = CpoVendor::create($this->validateVendor($request) + [
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return response()->json($vendor->toOption(), 201);
    }

    public function updateVendor(Request $request, CpoVendor $vendor)
    {
        $vendor->update($this->validateVendor($request, $vendor) + ['updated_by' => Auth::id()]);

        return response()->json($vendor->toOption());
    }

    /** An invoice / receipt file, for a user who may see the order's store. */
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
