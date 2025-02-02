<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Exports\StoreOrdersExport;
use App\Exports\UsersExport;
use App\Http\Requests\StoreOrder\StoreOrderRequest;
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
        $products = ProductInventory::options();
        $suppliers = Supplier::whereNot('supplier_code', 'DROPS')->options();
        $branches = StoreBranch::options();

        return Inertia::render('StoreOrder/Create', [
            'products' => $products,
            'branches' => $branches,
            'suppliers' => $suppliers,
            'previousOrder' => $this->storeOrderService->getPreviousOrderReference()
        ]);
    }

    public function store(StoreOrderRequest $request)
    {
        try {
            $this->storeOrderService->createStoreOrder($request->validated());
        } catch (Exception $e) {
            DB::rollBack();
        }

        return redirect()->route('store-orders.index');
    }

    public function show($id)
    {
        $order = $this->storeOrderService->getOrderDetails($id);

        return Inertia::render('StoreOrder/Show', [
            'order' => $order,
            'orderedItems' => $this->storeOrderService->getOrderItems($order),
            'receiveDatesHistory' => $order->ordered_item_receive_dates,
            'images' =>  $this->storeOrderService->getImageAttachments($order)
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
        $order = $this->storeOrderService->getOrder($id);
        $orderedItems = $this->storeOrderService->getOrderItems($order);
        $products = ProductInventory::options();
        $suppliers = Supplier::options();
        $branches = StoreBranch::options();

        return Inertia::render('StoreOrder/Edit', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'products' => $products,
            'branches' => $branches,
            'suppliers' => $suppliers
        ]);
    }

    public function update(UpdateOrderRequest $request, StoreOrder $storeOrder)
    {
        $order =  $storeOrder->load('store_order_items');
        $this->storeOrderService->updateOrder($order, $request->validated());

        return redirect()->route('store-orders.index');
    }

    


}
