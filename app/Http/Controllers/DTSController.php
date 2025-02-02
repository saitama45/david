<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Exports\DTSOrdersExport;
use App\Http\Requests\DTSStoreOrder\StoreDtsOrderRequest;
use App\Http\Requests\DTSStoreOrder\UpdateDtsOrderRequest;
use App\Http\Services\DTSStoreOrderService;
use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class DTSController extends Controller
{

    protected $dtsStoreOrderService;

    public function __construct(DTSStoreOrderService $dtsStoreOrderService)
    {
        $this->dtsStoreOrderService = $dtsStoreOrderService;
    }
    public function index()
    {
        $branches = StoreBranch::options();
        return Inertia::render('DTSOrder/Index', [
            'orders' => $this->dtsStoreOrderService->getDtsOrdersList(),
            'branches' => $branches,
            'filters' => request()->only(['from', 'to', 'branchId', 'search', 'filterQuery'])
        ]);
    }

    public function create($variant)
    {
        $previousOrder = $this->dtsStoreOrderService->getPreviousOrderReference();
        $suppliers = Supplier::optionsDTS();
        $items = $this->dtsStoreOrderService->getItems($variant);
        $branches = StoreBranch::options();

        return Inertia::render('DTSOrder/Create', [
            'suppliers' => $suppliers,
            'items' => $items,
            'branches' => $branches,
            'variant' => $variant,
            'previousOrder' => $previousOrder ?? null
        ]);
    }

    public function export()
    {
        $from = request('from') ? Carbon::parse(request('from'))->format('Y-m-d') : '1999-01-01';
        $to = request('to') ? Carbon::parse(request('to'))->addDay()->format('Y-m-d') : Carbon::today()->addMonth();
        $branchId = request('branchId');
        $search = request('search');
        $filterQuery = request('filterQuery') ?? 'pending';

        return Excel::download(
            new DTSOrdersExport($search, $branchId, $filterQuery, $from, $to),
            'dts-orders-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function store(StoreDtsOrderRequest $request)
    {
        $this->dtsStoreOrderService->createOrder($request->validated());
        return redirect()->route('dts-orders.index');
    }

    public function show($id)
    {
        $order = $this->dtsStoreOrderService->getOrderDetails($id);

        return Inertia::render('DTSOrder/Show', [
            'order' => $order,
            'orderedItems' => $this->dtsStoreOrderService->getOrderItems($order),
            'receiveDatesHistory' =>  $order->ordered_item_receive_dates,
            'images' => $this->dtsStoreOrderService->getImageAttachments($order)
        ]);
    }

    public function update(UpdateDtsOrderRequest $request, StoreOrder $storeOrder)
    {
        $order =  $storeOrder->load('store_order_items');
        $this->dtsStoreOrderService->updateOrder($order, $request->validated());

        return redirect()->route('dts-orders.index');
    }

    public function edit($id)
    {
        $order = $this->dtsStoreOrderService->getOrder($id);
        $orderedItems = $this->dtsStoreOrderService->getOrderItems($order);
        $products = $this->dtsStoreOrderService->getItems($order->variant);
        $suppliers = Supplier::optionsDTS();
        $branches = StoreBranch::options();

        return Inertia::render('DTSOrder/Edit', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'products' => $products,
            'branches' => $branches,
            'suppliers' => $suppliers
        ]);
    }

}
