<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Exports\StoreOrdersExport;
use App\Exports\UsersExport;
use App\Http\Requests\StoreOrder\StoreOrderRequest; // Ensure this is the correct namespace
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
use App\Models\SupplierItems;
use Illuminate\Support\Facades\Log; // Import Log facade

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
        $suppliers = Supplier::whereNot('supplier_code', 'DROPS')->options();
        $branches = StoreBranch::options();

        return Inertia::render('StoreOrder/Create', [
            'branches' => $branches,
            'suppliers' => $suppliers,
            'previousOrder' => $this->storeOrderService->getPreviousOrderReference()
        ]);
    }

    // REVISED: Store method with improved error handling and correct StoreOrderRequest usage
    public function store(StoreOrderRequest $request)
    {
        try {
            $this->storeOrderService->createStoreOrder($request->validated());
            return redirect()->route('store-orders.index')->with('success', 'Order placed successfully!');
        } catch (Exception $e) {
            Log::error("Error creating store order: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to place order: ' . $e->getMessage()]);
        }
    }

    public function show($id)
    {
        $order = $this->storeOrderService->getOrderDetails($id);
        // Eager load the 'sapMasterfiles' relationship (plural) so the 'sap_masterfile' accessor works correctly.
        $order->load(['ordered_item_receive_dates.store_order_item.supplierItem.sapMasterfiles']);

        $orderedItems = $this->storeOrderService->getOrderItems($order);
        // Eager load the 'sapMasterfiles' relationship (plural) for ordered items.
        $orderedItems->load('supplierItem.sapMasterfiles');

        return Inertia::render('StoreOrder/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'receiveDatesHistory' => $order->ordered_item_receive_dates,
            'images' => $this->storeOrderService->getImageAttachments($order)
        ]);
    }

    public function getImportedOrders(Request $request)
    {
        $import = new OrderListImport();
        Excel::import($import, $request->file('orders_file'));
        $importedCollection = $import->getImportedData();
        return response()->json([
            'orders' => $importedCollection
        ]);
    }

    public function edit($id)
    {
        $order = $this->storeOrderService->getOrder($id);
        $orderedItems = $this->storeOrderService->getOrderItems($order);
        // Eager load the 'sapMasterfiles' relationship (plural) for ordered items in edit.
        $orderedItems->load('supplierItem.sapMasterfiles'); 
        $suppliers = Supplier::options();
        $branches = StoreBranch::options();

        return Inertia::render('StoreOrder/Edit', [
            'order' => $order,
            'orderedItems' => $orderedItems,
            'branches' => $branches,
            'suppliers' => $suppliers
        ]);
    }

    // REVISED: Update method with improved error handling
    public function update(UpdateOrderRequest $request, StoreOrder $storeOrder)
    {
        $order = $storeOrder->load('store_order_items');
        try {
            $this->storeOrderService->updateOrder($order, $request->validated());
            return redirect()->route('store-orders.index')->with('success', 'Order updated successfully!');
        } catch (Exception $e) {
            Log::error("Error updating store order: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withErrors(['error' => 'Failed to update order: ' . $e->getMessage()]);
        }
    }

    // getSupplierItems method now correctly takes supplierCode (string)
    public function getSupplierItems($supplierCode) // Expects supplierCode (string)
    {
        // Use the SupplierItems::options() scope which returns ItemCode => CONCAT(item_name, ' (', ItemCode, ') ', uom)
        $supplierItems = SupplierItems::where('SupplierCode', $supplierCode)
                                     ->where('is_active', true)
                                     ->options() // This scope returns ItemCode => CONCAT(item_name, ' (', ItemCode, ') ', uom)
                                     ->all(); // Convert the collection to an array of value/label pairs

        // The options scope returns an associative array (ItemCode => formatted_name),
        // we need to convert it to the {value: 'ItemCode', label: 'formatted_name'} format expected by Select component.
        $formattedSupplierItems = [];
        foreach ($supplierItems as $itemCode => $formattedName) {
            $formattedSupplierItems[] = [
                'value' => (string) $itemCode, // Ensure ItemCode is a string
                'label' => $formattedName // Use the concatenated name as the label
            ];
        }

        return response()->json([
            'items' => $formattedSupplierItems
        ]);
    }
}
