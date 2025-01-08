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
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class DTSController extends Controller
{
    public function index()
    {

        $search = request('search');
        $filter = request('currentFilter') ?? 'pending';

        $query = StoreOrder::query()->with(['store_branch', 'supplier'])->whereNot('variant', 'regular');
        $user = Auth::user();

        if (in_array('so encoder', $user->roles->pluck('name')->toArray()) && !in_array('admin', $user->roles->pluck('name')->toArray())) {

            $query->whereIn('store_branch_id', $user->store_branches->pluck('id'));
        }

        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%');
        // ->whereHas('store_branch', function ($query) use ($search) {
        //     $query->where('name', 'like', '%' . $search . '%');
        // });

        if ($filter !== 'all')
            $query->where('order_request_status', $filter);

        // if ($search)
        //     $query->where('order_number', 'like', '%' . $search . '%')
        //         ->orWhereHas('store_branch', function ($query) use ($search) {
        //             $query->where('name', 'like', '%' . $search . '%');
        //         });;



        $orders = $query
            ->latest()
            ->paginate(10)
            ->withQueryString();



        return Inertia::render('DTSOrder/Index', [
            'orders' => $orders,
            'filters' => request()->only(['from', 'to', 'branchId', 'search', 'currentFilter'])
        ]);
    }

    public function create(Request $request, $variant)
    {

        if ($request->has('orderId')) {
            $orderId = $request->input('orderId');
            $previousOrder = StoreOrder::with(['store_order_items', 'store_order_items.product_inventory', 'store_order_items.product_inventory.unit_of_measurement'])->find($orderId);
        }
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
            'previousOrder' => $previousOrder
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:store_branches,id'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'order_date' => ['required'],
            'orders' => ['required', 'array'],
            'variant' => ['required']
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
            'variant' => $validated['variant']
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
        $order = StoreOrder::with([
            'encoder',
            'approver',
            'delivery_receipts',
            'store_branch',
            'supplier',
            'store_order_remarks',
            'store_order_remarks.user',
            'store_order_items',
            'ordered_item_receive_dates',
            'ordered_item_receive_dates.receiver',
            'ordered_item_receive_dates.store_order_item',
            'ordered_item_receive_dates.store_order_item.product_inventory',
            'image_attachments'
        ])->where('order_number', $id)->firstOrFail();
        $orderedItems = $order->store_order_items()->with(['product_inventory', 'product_inventory.unit_of_measurement'])->get();


        $images = $order->image_attachments->map(function ($image) {
            return [
                'id' => $image->id,
                'image_url' => Storage::url($image->file_path),
            ];
        });

        $receiveDatesHistory = $order->ordered_item_receive_dates;

        return Inertia::render('DTSOrder/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'receiveDatesHistory' => $receiveDatesHistory,
            'images' => $images
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'supplier_id' => ['required'],
            'branch_id' => ['required', 'exists:store_branches,id'],
            'order_date' => ['required'],
            'orders' => ['required', 'array'],
            'variant' => ['required']
        ], [
            'branch_id.required' => 'Store branch is required'
        ]);
        $order = StoreOrder::with('store_order_items')->findOrFail($id);

        DB::beginTransaction();
        if ($order->store_branch_id !== $validated['branch_id'])
            $order->order_number = $this->getOrderNumber($validated['branch_id']);
        $order->update([
            'supplier_id' => $validated['supplier_id'],
            'store_branch_id' => $validated['branch_id'],
            'order_date' => $validated['order_date'],
            'variant' => $validated['variant']
        ]);
        $updatedProductIds = collect($validated['orders'])->pluck('id')->toArray();
        $order->store_order_items()
            ->whereNotIn('product_inventory_id', $updatedProductIds)
            ->delete();

        foreach ($validated['orders'] as $data) {
            $order->store_order_items()->updateOrCreate(
                [
                    'store_order_id' => $order->id,
                    'product_inventory_id' => $data['id'],
                ],
                [
                    'quantity_ordered' => $data['quantity'],
                    'total_cost' => $data['total_cost'],
                ]
            );
        }

        $order->save();
        DB::commit();

        return redirect()->route('dts-orders.index');
    }

    public function edit($id)
    {
        $order = StoreOrder::with(['store_branch', 'supplier', 'store_order_items'])
            ->where('order_number', $id)->firstOrFail();

        if ($order->order_request_status !== OrderRequestStatus::PENDING->value)
            abort(401, 'Order can no longer be updated');
        $orderedItems = $order->store_order_items()->with(['product_inventory', 'product_inventory.unit_of_measurement'])->get();


        if ($order->variant === 'fruits and vegetables') {
            $products = ProductInventory::where('inventory_category_id', 6)
                ->options();
        } else if ($order->variant === 'salmon') {
            $products = ProductInventory::where('inventory_code', '269A2A')->options();
        } else {
            $products = ProductInventory::where('inventory_code', '359A2A')->options();
        }
        $suppliers = Supplier::where('supplier_code', 'DROPS')->options();
        $user = Auth::user();
        if ($user->role == 'so_encoder') {
            $assignedBranches = $user->store_branches->pluck('id');
            $branches = StoreBranch::whereIn('id', $assignedBranches)->options();
        } else {
            $branches = StoreBranch::options();
        }

        return Inertia::render('DTSOrder/Edit', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'products' => $products,
            'branches' => $branches,
            'suppliers' => $suppliers
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
