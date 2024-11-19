<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\StoreOrderRequest;
use App\Imports\OrderListImport;
use App\Models\Branch;
use Inertia\Inertia;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Maatwebsite\Excel\Facades\Excel;

class StoreOrderController extends Controller
{
    public function index()
    {
        $from = request('from') ?? '2000-01-01';
        $to = request('to') ? date('Y-m-d', strtotime(request('to') . ' +1 day')) : now()->addDay();
        $branchId = request('branchId');
        $search = request('search');

        $query = Order::query()->with(['branch', 'vendor']);

        if ($branchId)
            $query->where('BranchID', $branchId);

        if ($search)
            $query->where('SONumber', 'like', '%' . $search . '%');

        $orders = $query
            ->whereBetween('OrderDate', [$from, $to])
            ->latest()
            ->paginate(10);


        $branches = Branch::options();

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
        $products = Product::select('ID', 'InventoryName')
            ->get()
            ->pluck('InventoryName', 'ID');
        $branches = Branch::options();
        return Inertia::render('StoreOrder/Create', [
            'products' => $products,
            'branches' => $branches
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'storeId' => ['required', 'exists:branches,id']
        ]);

        dd($validated);
    }

    public function show($id)
    {
        $orders = DB::select("CALL SP_GET_RECEIVINGITEMS_HEADERID(?,?)", [$id, 1]);
        $orderDetails = DB::select("CALL SP_GET_SO_TRANSACTIONHEADER(?)", [$id]);
        return Inertia::render('StoreOrder/Show', [
            'orders' => $orders,
            'orderDetails' => $orderDetails
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
}
