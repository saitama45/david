<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Http\Requests\Api\StoreOrderRequest;
use App\Imports\OrderListImport;
use Inertia\Inertia;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class StoreOrderController extends Controller
{
    public function index()
    {
        $from = request('from') ? Carbon::parse(request('from')) : Carbon::today();
        $to = request('to') ? Carbon::parse(request('to'))->addDay() : Carbon::today()->addDay();
        $branchId = request('branchId');
        $search = request('search');

        $query = StoreOrder::query()->with(['store_branch', 'supplier']);

        if ($branchId)
            $query->where('store_branch_id', $branchId);

        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%');

        $orders = $query
            ->whereBetween('created_at', [$from, $to])
            ->latest()
            ->paginate(10);

        $branches = StoreBranch::options();

        return Inertia::render(
            'StoreOrder/Index',
            [
                'orders' => $orders,
                'branches' => $branches,
                'filters' => request()->only(['from', 'to', 'branchId', 'search'])
            ]
        );
    }

    public function create()
    {
        $products = ProductInventory::options();
        $suppliers = Supplier::options();
        $branches = StoreBranch::options();
        return Inertia::render('StoreOrder/Create', [
            'products' => $products,
            'branches' => $branches,
            'suppliers' => $suppliers
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:store_branches,id'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'order_date' => ['required', 'after_or_equal:today'],
            'orders' => ['required', 'array']
        ], [
            'branch_id.required' => 'Store field branch is required',
            'supplier_id.required' => 'Supplier field is required'
        ]);

        $supplier = Supplier::first($validated['supplier_id'])->id;

        $branchId = $validated['branch_id'];
        $branchCode = StoreBranch::select('branch_code')->findOrFail($branchId)->branch_code;
        $orderCount = StoreOrder::where('store_branch_id', $branchId)->count() + 1;
        $orderNumber = str_pad($orderCount, 5, '0', STR_PAD_LEFT);
        $formattedOrderNumber = "$branchCode-$orderNumber";

        DB::beginTransaction();
        $order = StoreOrder::create([
            'encoder_id' => 1,
            'supplier_id' => $supplier,
            'store_branch_id' => $branchId,
            'order_number' => $formattedOrderNumber,
            'order_date' => Carbon::parse($validated['order_date'])->format('Y-m-d'),
            'order_status' => OrderStatus::PENDING->value,
            'order_request_status' => OrderRequestStatus::PENDING->value,
        ]);


        foreach ($validated['orders'] as $data) {
            $order->store_order_items()->create([
                'product_inventory_id' => $data['id'],
                'quantity_ordered' => $data['quantity'],
                'total_cost' => $data['total_cost'],
            ]);
        }
        DB::commit();

        return redirect()->route('store-orders.index');
    }

    public function show($id)
    {
        $order = StoreOrder::with(['store_branch', 'supplier', 'store_order_items'])->where('order_number', $id)->firstOrFail();
        $orderedItems = $order->store_order_items()->with(['product_inventory', 'product_inventory.unit_of_measurement'])->get();

        return Inertia::render('StoreOrder/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems
        ]);
    }

    public function getImportedOrders(StoreOrderRequest $storeOrderRequest)
    {
        $import = new OrderListImport();
        Excel::import($import, $storeOrderRequest->file('orders_file'));
        $importedCollection = $import->getImportedData();
        return response()->json([

            'orders' => $importedCollection
        ]);
    }

    public function validateHeaderUpload(StoreOrderRequest $storeOrderRequest)
    {
        $import = new OrderListImport();
        Excel::import($import, $storeOrderRequest->file('orders_list'));

        $importedCollection = $import->getImportedData();
        $products = Product::select('ID', 'InventoryName')
            ->limit(10)
            ->get()
            ->pluck('InventoryName', 'ID');

        return Inertia::render('StoreOrder/Create', [
            'orders' => $importedCollection,
            'products' => $products,
            'orderDate' => $storeOrderRequest->store_order_date
        ]);
    }

    public function edit($id)
    {
        $order = StoreOrder::with(['store_branch', 'supplier', 'store_order_items'])
            ->where('order_number', $id)->firstOrFail();

        if ($order->order_request_status !== OrderRequestStatus::PENDING->value)
            abort(401, 'Order can no longer be updated');
        $orderedItems = $order->store_order_items()->with(['product_inventory', 'product_inventory.unit_of_measurement'])->get();
        $products = ProductInventory::options();
        $branches = StoreBranch::options();

        return Inertia::render('StoreOrder/Edit', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'products' => $products,
            'branches' => $branches
        ]);
    }

    public function update(Request $request, $id)
    {

        $validated = $request->validate([
            'branch_id' => ['required', 'exists:store_branches,id'],
            'order_date' => ['required'],
            'orders' => ['required', 'array']
        ], [
            'branch_id.required' => 'Store branch is required'
        ]);

        $order = StoreOrder::with('store_order_items')->findOrFail($id);

        DB::beginTransaction();

        if ($order->store_branch_id !== $validated['branch_id'])
            $order->order_number = $this->getOrderNumber($validated['branch_id']);

        $order->update([
            'store_branch_id' => $validated['branch_id'],
            'order_date' => Carbon::createFromFormat('F d, Y', $validated['order_date'])->format('Y-m-d')
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

        return redirect()->route('store-orders.index');
    }

    public function getOrderNumber($id)
    {
        $branchId = $id;
        $branchCode = StoreBranch::select('branch_code')->findOrFail($branchId)->branch_code;
        $orderCount = StoreOrder::where('store_branch_id', $branchId)->count() + 1;
        $orderNumber = str_pad($orderCount, 5, '0', STR_PAD_LEFT);
        return "$branchCode-$orderNumber";
    }
}
