<?php

namespace App\Http\Controllers;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Exports\CSApprovalExport;
use App\Http\Requests\OrderApproval\ApproveOrderRequest;
use App\Http\Requests\StoreOrder\UpdateOrderRequest; // Added missing use statement
use App\Http\Services\CSCommitService;
use App\Http\Services\StoreOrderService; // Added missing use statement
use App\Imports\OrderListImport; // Added missing use statement
use App\Models\StoreOrder;
use App\Models\StoreOrderItem; // Added missing use statement
use App\Models\Supplier; // Import Supplier model
use App\Models\StoreBranch; // Added missing use statement
use App\Models\SupplierItems; // Added missing use statement
use Carbon\Carbon;
use Exception; // Added missing use statement
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Added missing use statement
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SupplierOrderTemplateExport; // Import the new export class

class CSApprovalController extends Controller
{
    protected $csCommitService;
    protected $storeOrderService; // Declare the property for StoreOrderService

    public function __construct(CSCommitService $csCommitService, StoreOrderService $storeOrderService) // Inject StoreOrderService
    {
        $this->csCommitService = $csCommitService;
        $this->storeOrderService = $storeOrderService; // Assign the injected service
    }

    public function index()
    {
        // Get the currently authenticated user
        $user = Auth::user();

        // Get the supplier codes assigned to the logged-in user
        $assignedSupplierCodes = $user->suppliers->pluck('supplier_code')->toArray();

        // Get the current supplier filter from the request (defaults to 'all' if not present)
        $currentSupplierFilter = request('currentSupplierFilter') ?? 'all'; 
        // Status filter is now static to 'approved' as per request
        $currentStatusFilter = OrderStatus::APPROVED->value; 

        // Fetch all active suppliers to populate the dynamic tabs in the frontend
        $assignedSuppliers = Supplier::whereIn('supplier_code', $assignedSupplierCodes)
                                     ->where('is_active', true)
                                     ->select('id', 'supplier_code', 'name')
                                     ->get()
                                     ->map(function ($supplier) {
                                         return [
                                             'value' => $supplier->supplier_code,
                                             'label' => $supplier->name,
                                         ];
                                     })->toArray();

        // Pass the assigned supplier codes, the current supplier filter, and the static status filter to the service
        $data = $this->csCommitService->getOrdersAndCounts(
            'cs', // type
            $assignedSupplierCodes,
            $currentSupplierFilter, // passed as $variant in service
            $currentStatusFilter // passed as $statusFilter in service
        );

        return Inertia::render('CSApproval/Index', [
            'orders' => $data['orders'],
            'filters' => request()->only(['search', 'currentSupplierFilter']), // Removed currentStatusFilter from filters as it's static
            'counts' => $data['counts'],
            'assignedSuppliers' => $assignedSuppliers,
        ]);
    }

    public function export(Request $request)
    {
        $search = $request->input('search');
        $currentSupplierFilter = $request->input('currentSupplierFilter') ?? 'all';
        $currentStatusFilter = OrderStatus::APPROVED->value; // Static to 'approved' for export

        // Get the currently authenticated user's assigned supplier codes for export filtering
        $user = Auth::user();
        $assignedSupplierCodes = $user->suppliers->pluck('supplier_code')->toArray();

        // Pass all relevant filters to the export class
        return Excel::download(
            new CSApprovalExport($search, $currentSupplierFilter, $assignedSupplierCodes, $currentStatusFilter),
            'cs-approvals-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    public function show($id)
    {
        $order = $this->csCommitService->getOrder($id, 'cs');
        $orderedItems = $this->csCommitService->getOrderItems($order);
        return Inertia::render('CSApproval/Show', [
            'order' => $order,
            'orderedItems' => $orderedItems
        ]);
    }

    public function approve(ApproveOrderRequest $request)
    {
        $this->csCommitService->commitOrder($request->validated());
        return to_route('cs-approvals.index');
    }

    public function getImportedOrders(Request $request)
    {
        // Validate the incoming request, ensuring the file and supplier_id are present
        $request->validate([
            'orders_file' => 'required|file|mimes:xlsx,xls',
            'supplier_id' => 'required|string', // Ensure supplier_id is passed and is a string
        ]);

        $supplierId = $request->input('supplier_id'); // Get the supplier_id from the request
        $import = new OrderListImport($supplierId); // Pass the supplier_id to the importer's constructor
        Excel::import($import, $request->file('orders_file'));
        $importedCollection = $import->getImportedData();
        $skippedItems = $import->getSkippedItems(); // NEW: Get skipped items

        // If no valid items were imported after filtering (e.g., due to supplier mismatch or other validation)
        // We will now always return a 200 OK, but include skipped items for frontend display.
        return response()->json([
            'orders' => $importedCollection,
            'skipped_items' => $skippedItems // NEW: Include skipped items in the response
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

    public function getSupplierItems($supplierCode)
    {
        $supplierItems = SupplierItems::where('SupplierCode', $supplierCode)
                                       ->where('is_active', true)
                                       ->options()
                                       ->all();

        $formattedSupplierItems = [];
        foreach ($supplierItems as $itemCode => $formattedName) {
            $formattedSupplierItems[] = [
                'value' => (string) $itemCode,
                'label' => $formattedName
            ];
        }

        return response()->json([
            'items' => $formattedSupplierItems
        ]);
    }

    public function getSupplierItemDetailsByCode(Request $request)
    {
        $itemCode = $request->itemCode;
        $supplierCode = $request->supplierCode;

        $item = SupplierItems::where('ItemCode', $itemCode)
                            ->where('SupplierCode', $supplierCode)
                            ->where('is_active', true)
                            ->first();

        if (!$item) {
            return response()->json(['message' => 'Item not found for the given code and supplier.'], 404);
        }

        return response()->json(['item' => $item]);
    }

    public function downloadSupplierOrderTemplate(string $supplierCode)
    {
        $supplierExists = Supplier::where('supplier_code', $supplierCode)->exists();
        if (!$supplierExists) {
            return response()->json(['message' => 'Supplier not found.'], 404);
        }

        $fileName = "supplier_order_template_{$supplierCode}.xlsx";
        return Excel::download(new SupplierOrderTemplateExport($supplierCode), $fileName);
    }
}
