<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DTSController extends Controller
{
    public function index()
    {
        $search = request('search');
        $filter = request('filter') ?? 'all';

        $query = StoreOrder::query()->with(['store_branch', 'supplier']);
        $user = Auth::user();

        if (in_array('so encoder', $user->roles->pluck('name')->toArray()) && !in_array('admin', $user->roles->pluck('name')->toArray())) {

            $query->whereIn('store_branch_id', $user->store_branches->pluck('id'));
        }

        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%')
                ->orWhereHas('store_branch', function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%');
                });;

        if ($filter !== 'all')
            $query->where('order_request_status', $filter);

        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%')
                ->orWhereHas('store_branch', function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%');
                });;

        $orders = $query
            ->where('type', 'dts')
            ->latest()
            ->paginate(10);

        return Inertia::render('DTSOrder/Index', [
            'orders' => $orders,
            'filters' => request()->only(['from', 'to', 'branchId', 'search', 'filter'])
        ]);
    }

    public function create($variant)
    {
        $suppliers = Supplier::where('supplier_code', 'DROPS')->options();
        if ($variant === 'fruits and vegetables') {
            $items = ProductInventory::where('inventory_category_id', 6)
                ->options();
        } else if ($variant === 'salmon') {
            $items = ProductInventory::where('inventory_code', '269A2A')->options();
        } else {
            $items = ProductInventory::whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($variant) . '%'])->options();
        }
        if ($variant === 'ice cream') {

            $branches = StoreBranch::whereHas('delivery_schedules', function ($query) {
                $query->where('variant', 'ICE CREAM');
            })->options();
        }
        if ($variant === 'salmon') {
            $branches = StoreBranch::whereHas('delivery_schedules', function ($query) {
                $query->where('variant', 'SALMON');
            })->options();
        }
        if ($variant === 'fruits and vegetables') {
            $branches = StoreBranch::whereHas('delivery_schedules', function ($query) {
                $query->where('variant', 'FRUITS AND VEGETABLES');
            })->options();
        }


        return Inertia::render('DTSOrder/Create', [
            'suppliers' => $suppliers,
            'items' => $items,
            'branches' => $branches,
            'variant' => $variant,
        ]);
    }

    public function store(Request $request)
    {

        $validated = $request->validate([
            'branch_id' => ['required', 'exists:store_branches,id'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'order_date' => ['required'],
            'orders' => ['required', 'array']
        ], [
            'branch_id.required' => 'Store field branch is required',
            'supplier_id.required' => 'Supplier field is required'
        ]);



        $supplier = Supplier::find($validated['supplier_id'])->id;


        DB::beginTransaction();
        $order = StoreOrder::create([
            'encoder_id' => 1,
            'supplier_id' => $supplier,
            'store_branch_id' => $validated['branch_id'],
            'order_number' => $this->getOrderNumber($validated['branch_id']),
            'order_date' => Carbon::parse($validated['order_date'])->addDay()->format('Y-m-d'),
            'order_status' => OrderStatus::PENDING->value,
            'order_request_status' => OrderRequestStatus::PENDING->value,
            'type' => 'dts'
        ]);




        foreach ($validated['orders'] as $data) {
            $order->store_order_items()->create([
                'product_inventory_id' => $data['id'],
                'quantity_ordered' => $data['quantity'],
                'total_cost' => $data['total_cost'],
            ]);
        }
        DB::commit();

        return redirect()->route('dts-orders.index');
    }

    public function show($id)
    {
        $order = StoreOrder::with(['store_branch', 'supplier', 'store_order_items'])->where('order_number', $id)->firstOrFail();
        $orderedItems = $order->store_order_items()->with(['product_inventory', 'product_inventory.unit_of_measurement'])->get();

        return Inertia::render('DTSOrder/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems
        ]);
    }

    public function getOrderNumber($id)
    {
        $branchId = $id;
        $branchCode = StoreBranch::select('branch_code')->findOrFail($branchId)->branch_code;
        $orderCount = StoreOrder::where('store_branch_id', $branchId)->count() + 1;
        while (true) {
            $orderNumber = str_pad($orderCount, 5, '0', STR_PAD_LEFT);
            $store_order_number = "$branchCode-$orderNumber";
            $result = StoreOrder::where('order_number', $store_order_number)->first();
            $orderCount++;
            if (!$result) break;
        }
        return $store_order_number;
    }
}
