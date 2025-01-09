<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Http\Requests\Api\StoreOrderRequest;
use App\Imports\OrderListImport;
use Inertia\Inertia;
use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\Supplier;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;


class StoreOrderController extends Controller
{
    public function index()
    {
        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : '1999-01-01';
        $to = request('to') ? Carbon::parse(request('to'))->addDay()->format('Y-m-d') : Carbon::today()->addMonth();
        $branchId = request('branchId');
        $search = request('search');
        $filterQuery = request('filterQuery') ?? 'pending';

        $query = StoreOrder::query()->with(['store_branch', 'supplier']);

        $user = Auth::user();

        if (in_array('so encoder', $user->roles->pluck('name')->toArray()) && !in_array('admin', $user->roles->pluck('name')->toArray())) {

            $query->whereIn('store_branch_id', $user->store_branches->pluck('id'));
        }

        if ($from && $to) {
            $query->whereBetween('order_date', [$from, $to]);
        }

        if ($filterQuery !== 'all')
            $query->where('order_request_status', $filterQuery);

        if ($branchId)
            $query->where('store_branch_id', $branchId);


        if ($search)
            $query->where('order_number', 'like', '%' . $search . '%')
                ->orWhereHas('store_branch', function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%');
                });;




        $orders = $query
            ->where('variant', 'regular')
            ->latest()
            ->paginate(10)
            ->withQueryString();

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

    public function create(Request $request)
    {
        if ($request->has('orderId')) {
            $orderId = $request->input('orderId');
            $previousOrder = StoreOrder::with(['store_order_items', 'store_order_items.product_inventory', 'store_order_items.product_inventory.unit_of_measurement'])->find($orderId);
        }

        $products = ProductInventory::options();
        $suppliers = Supplier::whereNot('supplier_code', 'DROPS')->options();
        $user = Auth::user();

        if (!in_array('admin', $user->roles->pluck('name')->toArray())) {
            $assignedBranches = $user->store_branches->pluck('id');
            $branches = StoreBranch::whereIn('id', $assignedBranches)->options();
        } else {
            $branches = StoreBranch::options();
        }

        return Inertia::render('StoreOrder/Create', [
            'products' => $products,
            'branches' => $branches,
            'suppliers' => $suppliers,
            'previousOrder' => $previousOrder ?? null
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


        try {
            DB::beginTransaction();
            $order = StoreOrder::create([
                'encoder_id' => Auth::user()->id,
                'supplier_id' => $supplier,
                'store_branch_id' => $validated['branch_id'],
                'order_number' => $this->getOrderNumber($validated['branch_id']),
                'order_date' => Carbon::parse($validated['order_date'])->addDays(1)->format('Y-m-d'),
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
        } catch (Exception $e) {
            DB::rollBack();
            dd($e);
        }

        return redirect()->route('store-orders.index');
    }

    public function show($id)
    {

        $order = StoreOrder::with([
            'encoder',
            'approver',
            'delivery_receipts',
            'store_branch',
            'supplier',
            'store_order_items',
            'store_order_remarks',
            'store_order_remarks.user',
            'ordered_item_receive_dates',
            'ordered_item_receive_dates.receiver',
            'ordered_item_receive_dates.store_order_item',
            'ordered_item_receive_dates.store_order_item.product_inventory',
            'image_attachments' => function ($query) {
                $query->where('is_approved', true);
            },
        ])->where('order_number', $id)->firstOrFail();
        $orderedItems = $order->store_order_items()->with(['product_inventory', 'product_inventory.unit_of_measurement'])->get();


        $images = $order->image_attachments->map(function ($image) {
            return [
                'id' => $image->id,
                'image_url' => Storage::url($image->file_path),
            ];
        });

        $receiveDatesHistory = $order->ordered_item_receive_dates;

        return Inertia::render('StoreOrder/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'receiveDatesHistory' => $receiveDatesHistory,
            'images' => $images
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


    public function edit($id)
    {
        $order = StoreOrder::with(['store_branch', 'supplier', 'store_order_items'])
            ->where('order_number', $id)->firstOrFail();

        if ($order->order_request_status !== OrderRequestStatus::PENDING->value)
            abort(401, 'Order can no longer be updated');
        $orderedItems = $order->store_order_items()->with(['product_inventory', 'product_inventory.unit_of_measurement'])->get();
        $products = ProductInventory::options();
        $suppliers = Supplier::whereNot('supplier_code', 'DROPS')->options();
        $user = Auth::user();
        if ($user->role == 'so_encoder') {
            $assignedBranches = $user->store_branches->pluck('id');
            $branches = StoreBranch::whereIn('id', $assignedBranches)->options();
        } else {
            $branches = StoreBranch::options();
        }

        return Inertia::render('StoreOrder/Edit', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'products' => $products,
            'branches' => $branches,
            'suppliers' => $suppliers
        ]);
    }

    public function update(Request $request, $id)
    {


        $validated = $request->validate([
            'supplier_id' => ['required'],
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
            'supplier_id' => $validated['supplier_id'],
            'store_branch_id' => $validated['branch_id'],
            'order_date' => Carbon::parse($validated['order_date'])->addDays(1)->format('Y-m-d'),
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
