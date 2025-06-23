<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrder\StoreOrderRequest;
use App\Http\Services\StoreOrderService;
use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\Supplier;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AdditionalOrderController extends StoreOrderController
{
    protected $storeOrderService;

    public function __construct(StoreOrderService $storeOrderService)
    {
        $this->storeOrderService = $storeOrderService;
    }
    public function index()
    {
        $orders = $this->storeOrderService->getOrdersList('additional_order');
        $branches = StoreBranch::options();
        return Inertia::render(
            'AdditionalOrder/Index',
            [
                'orders' => $orders,
                'branches' => $branches,
                'filters' => request()->only(['from', 'to', 'branchId', 'search', 'filterQuery'])
            ]
        );
    }

    public function store(StoreOrderRequest $request)
    {
        try {
            $this->storeOrderService->createStoreOrder($request->validated());
        } catch (Exception $e) {
            DB::rollBack();
        }

        return redirect()->route(route: 'additional-orders.index');
    }

    public function create()
    {
        $products = ProductInventory::options();
        $suppliers = Supplier::whereNot('supplier_code', 'DROPS')->options();
        $branches = StoreBranch::options();

        return Inertia::render('AdditionalOrder/Create', [
            'products' => $products,
            'branches' => $branches,
            'suppliers' => $suppliers,
            'previousOrder' => $this->storeOrderService->getPreviousOrderReference()
        ]);
    }
}
