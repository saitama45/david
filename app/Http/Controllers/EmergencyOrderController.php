<?php

namespace App\Http\Controllers;

use App\Http\Services\StoreOrderService;
use App\Models\ProductInventory;
use App\Models\StoreBranch;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmergencyOrderController extends Controller
{
    protected $storeOrderService;

    public function __construct(StoreOrderService $storeOrderService)
    {
        $this->storeOrderService = $storeOrderService;
    }
    public function index()
    {
        $orders = $this->storeOrderService->getOrdersList('emergency_orders');
        $branches = StoreBranch::options();
        return Inertia::render(
            'EmergencyOrder/Index',
            [
                'orders' => $orders,
                'branches' => $branches,
                'filters' => request()->only(['from', 'to', 'branchId', 'search', 'filterQuery'])
            ]
        );
    }

    public function create()
    {
        $products = ProductInventory::options();
        $suppliers = Supplier::whereNot('supplier_code', 'DROPS')->options();
        $branches = StoreBranch::options();

        return Inertia::render('EmergencyOrder/Create', [
            'products' => $products,
            'branches' => $branches,
            'suppliers' => $suppliers,
            'previousOrder' => $this->storeOrderService->getPreviousOrderReference()
        ]);
    }
}
