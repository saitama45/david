<?php

namespace App\Http\Controllers;

use App\Http\Services\DTSStoreOrderService;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\DTSDeliverySchedule;
use App\Models\DeliverySchedule;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\DTSOrdersExport;
use Illuminate\Support\Facades\Log;
use App\Rules\IsValidDTSOrder;

class DTSController extends Controller
{
    protected DTSStoreOrderService $dtsStoreOrderService;

    public function __construct(DTSStoreOrderService $dtsStoreOrderService)
    {
        $this->dtsStoreOrderService = $dtsStoreOrderService;
    }

    /**
     * Display a listing of DTS orders.
     */
    public function index(Request $request)
    {
        $filters = $request->only(['search', 'filterQuery', 'from', 'to', 'branchId']);
        
        $query = StoreOrder::query()
            // Updated to filter out 'regular' variants.
            ->where('variant', '<>', 'regular')
            ->with(['store_branch', 'supplier']);

        // Apply filters based on the request
        // if ($request->has('search')) {
        //     $query->where('order_number', 'like', '%' . $request->input('search') . '%');
        // }

        if ($request->has('filterQuery') && $request->input('filterQuery') !== 'all') {
            $query->where('order_status', $request->input('filterQuery'));
        }

        // if ($request->has('branchId') && $request->input('branchId') !== 'all') {
        //     $query->where('store_branch_id', (int)$request->input('branchId'));
        // }

        // if ($request->has('from')) {
        //     $query->whereDate('order_date', '>=', $request->input('from'));
        // }

        // if ($request->has('to')) {
        //     $query->whereDate('order_date', '<=', $request->input('to'));
        // }

        // if ($request->has('to')) {
        //     $query->whereDate('order_date', '<=', $request->input('to'));
        // }

        $orders = $query->paginate(10)->withQueryString();
        $branches = StoreBranch::query()->where('is_active', true)->pluck('name', 'id');
        
        return Inertia::render('DTSOrder/Index', [
            'orders' => $orders,
            'branches' => $branches,
            'filters' => $filters
        ]);
    }

    /**
     * Show the form for creating a new DTS order.
     */
    public function create()
    {
        $supplier = \App\Models\Supplier::where('supplier_code', 'DROPS')->first();
        $branches = StoreBranch::query()->where('is_active', true)->get();
        $variants = DTSDeliverySchedule::select('variant')->distinct()->get();

        // Group schedules by branch, then by variant, for easy lookup on the frontend.
        $deliverySchedules = DTSDeliverySchedule::with('deliverySchedule')
            ->get()
            ->groupBy(['store_branch_id', 'variant'])
            ->map(fn($branchGroup) => $branchGroup->map(fn($variantGroup) => 
                $variantGroup->map(fn($schedule) => $schedule->deliverySchedule->day)->unique()->values()
            ));

        return Inertia::render('DTSOrder/Create', [
            'branches' => $branches->map(fn($branch) => [
                'label' => $branch->name . ' (' . $branch->branch_code . ')',
                'value' => $branch->id,
            ])->values()->toArray(),
            'dtsSupplier' => $supplier ? [
                'label' => $supplier->name . ' (' . $supplier->supplier_code . ')',
                'value' => $supplier->id,
                'supplier_code' => $supplier->supplier_code
            ] : null,
            'variants' => $variants->pluck('variant')->values()->toArray(),
            'deliverySchedules' => $deliverySchedules,
        ]);
    }

    /**
     * Store a newly created DTS order in storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => ['required', 'array', new IsValidDTSOrder()],
            'items.*.order_date' => 'required|date',
            'items.*.store_branch_id' => 'required|exists:store_branches,id',
            'items.*.variant' => 'required|string',
            'items.*.item_id' => 'required|exists:sap_masterfiles,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.cost' => 'required|numeric|min:0',
        ]);

        try {
            $this->dtsStoreOrderService->createDTSOrder($validated);
            return redirect()->route('dts-orders.index')->with('success', 'DTS Order(s) placed successfully!');
        } catch (\Exception $e) {
            Log::error('Error placing DTS order: ' . $e->getMessage(), ['exception' => $e]);
            return back()->with('error', 'Error placing DTS order: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified DTS order.
     * @param string $id
     * @return \Inertia\Response
     */
    public function show(string $id)
    {
        $order = StoreOrder::where('order_number', $id)
            ->with(['store_branch', 'supplier', 'store_order_items.supplierItem', 'encoder', 'approver'])
            ->firstOrFail();

        return Inertia::render('DTSOrder/Show', [
            'order' => $order
        ]);
    }

    /**
     * Show the form for editing the specified DTS order.
     * @param string $id
     * @return \Inertia\Response
     */
    public function edit(string $id)
    {
        $order = StoreOrder::where('order_number', $id)
            ->with(['store_order_items']) // Load items, sap_masterfile_id is on the item
            ->firstOrFail();
            
        $supplier = \App\Models\Supplier::where('supplier_code', 'DROPS')->first();
        $branches = StoreBranch::query()->where('is_active', true)->get();
        $variants = DTSDeliverySchedule::select('variant')->distinct()->get();

        $deliverySchedules = DTSDeliverySchedule::with('deliverySchedule')
            ->get()
            ->groupBy(['store_branch_id', 'variant'])
            ->map(fn($branchGroup) => $branchGroup->map(fn($variantGroup) => 
                $variantGroup->map(fn($schedule) => $schedule->deliverySchedule->day)->unique()->values()
            ));

        return Inertia::render('DTSOrder/Edit', [
            'order' => $order,
            'branches' => $branches->map(fn($branch) => [
                'label' => $branch->name . ' (' . $branch->branch_code . ')',
                'value' => $branch->id,
            ])->values()->toArray(),
            'dtsSupplier' => $supplier ? [
                'label' => $supplier->name . ' (' . $supplier->supplier_code . ')',
                'value' => $supplier->id,
            ] : null,
            'variants' => $variants->pluck('variant')->values()->toArray(),
            'deliverySchedules' => $deliverySchedules,
        ]);
    }

    /**
     * Update the specified DTS order in storage.
     *
     * @param Request $request
     * @param string $order_number
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, $order_number)
    {
        $validated = $request->validate([
            'items' => ['required', 'array', new IsValidDTSOrder()],
            'items.*.order_date' => 'required|date',
            'items.*.store_branch_id' => 'required|exists:store_branches,id',
            'items.*.variant' => 'required|string',
            'items.*.item_id' => 'required|exists:sap_masterfiles,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.cost' => 'required|numeric|min:0',
        ]);

        try {
            $orderToUpdate = StoreOrder::where('order_number', $order_number)->firstOrFail();
            $this->dtsStoreOrderService->updateDTSOrder($orderToUpdate, $validated);
            return redirect()->route('dts-orders.index')->with('success', 'DTS Order updated successfully!');
        } catch (\Exception $e) {
            Log::error('Error updating DTS order: ' . $e->getMessage(), ['exception' => $e]);
            return back()->with('error', 'Error updating DTS order: ' . $e->getMessage());
        }
    }

    /**
     * Export DTS orders to Excel.
     * @param Request $request
     * @return \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function export(Request $request)
    {
        return Excel::download(new DTSOrdersExport($request->all()), 'dts_orders.xlsx');
    }

    
    
    /**
     * Get items for a specific variant.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getItemsByVariant(Request $request)
    {
        $request->validate(['variant' => 'required|string']);

        $variant = $request->input('variant');
        $itemCodes = [];

        switch (trim(strtoupper($variant))) {
            case 'ICE CREAM':
                $itemCodes = ['359A2A'];
                break;
            case 'SALMON':
                $itemCodes = ['269A2A'];
                break;
            case 'FRUITS AND VEGETABLES':
                $itemCodes = [
                    '261A2A', '262A2A', '306A2A', '307A2A', '308A2A', '309A2A', '310A2A', '311A2A', '312A2A',
                    '314A2A', '315A2A', '316A2A', '318A2A', '319A2A', '320A2A', '321A2A', '322A2A', '323A2A',
                    '324A2A', '325A2A', '409A2A', '411A2A', '517A2A', '592A2A', '593A2A', '594A2A', '595A2A',
                    '596A2A', '598A2A', '599A2A', '600A2A', '601A2A', '602A2A', '603A2A', '604A2A', '605A2A',
                    '606A2A', '607A2A', '608A2A', '609A2A', '610A2A', '611A2A', '612A2A', '613A2A', '614A2A',
                    '615A2A', '617A2A', '618A2A', '619A2A', '620A2A', '621A2A', '622A2A', '623A2A', '624A2A',
                    '625A2A', '626A2A', '627A2A', '628A2A', '629A2A', '630A2A', '631A2A', '632A2A', '633A2A',
                    '634A2A', '635A2A', '636A2A', '637A2A', '638A2A', '639A2A'
                ];
                break;
            default:
                $items = SAPMasterfile::query()->where('is_active', true)->get();
                return response()->json($items);
        }

        $items = SAPMasterfile::query()->whereIn('ItemCode', $itemCodes)->get();
        
        return response()->json($items->map(fn($item) => [
            'label' => $item->ItemCode . ' - ' . $item->ItemDescription . ' (' . $item->AltUOM . ')',
            'value' => $item->id,
            'item_code' => $item->ItemCode,
            'base_uom' => $item->BaseUOM,
            'alt_uom' => $item->AltUOM,
            'alt_qty' => $item->AltQty,
            'base_qty' => $item->BaseQty,
        ])->values()->toArray());
    }

    /**
     * Get the delivery schedule for a given branch and variant.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSchedule(Request $request)
    {
        $validated = $request->validate([
            'store_branch_id' => 'required|exists:store_branches,id',
            'variant' => 'required|string',
        ]);

        $validDays = DTSDeliverySchedule::where('store_branch_id', $validated['store_branch_id'])
            ->where('variant', $validated['variant'])
            ->with('deliverySchedule')
            ->get()
            ->pluck('deliverySchedule.day')
            ->unique()
            ->values();

        return response()->json($validDays);
    }
}
