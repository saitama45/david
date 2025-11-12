<?php

namespace App\Http\Controllers;

use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\StoreBranch;
use App\Models\StoreOrderRemark;
use App\Models\OrderedItemReceiveDate;
use App\Enums\IntercoStatus;
use App\Exports\IntercoReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class IntercoReportController extends Controller
{
    /**
     * Display the Interco Report page.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Get filter parameters
        $filters = $request->only([
            'date_from',
            'date_to',
            'sending_store_id',
            'receiving_store_id',
            'interco_status',
            'search',
            'per_page'
        ]);

        // Set default values
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');
        $filters['interco_status'] = $filters['interco_status'] ?? 'received';
        $filters['per_page'] = $filters['per_page'] ?? 50;

        // Get user's assigned stores
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');

        // Get all stores for filter dropdowns (user can only see their assigned stores)
        $stores = StoreBranch::whereIn('id', $assignedStoreIds)
            ->orderBy('name')
            ->get(['id', 'name', 'brand_name']);

        // Build query for interco orders with line items
        $query = StoreOrder::whereNotNull('interco_number')
            ->whereNotNull('sending_store_branch_id')
            ->with([
                'sendingStore' => fn($q) => $q->select('id', 'name', 'brand_name'),
                'store_branch' => fn($q) => $q->select('id', 'name', 'brand_name'),
                'store_order_items.sapMasterfile' => fn($q) => $q->select('id', 'ItemCode', 'ItemDescription', 'BaseUOM'),
                'store_order_items.ordered_item_receive_dates' => fn($q) => $q->select('store_order_item_id', 'received_date', 'expiry_date', 'quantity_received', 'status'),
                'store_order_remarks' => fn($q) => $q->where('action', 'COMMIT')->select('store_order_id', 'created_at')
            ])
            ->whereHas('store_order_items.sapMasterfile')
            ->whereBetween('order_date', [$filters['date_from'], $filters['date_to']]);

        // Apply user permissions - filter to only show orders where user is involved
        if ($assignedStoreIds->isNotEmpty()) {
            $query->where(function($q) use ($assignedStoreIds) {
                $q->whereIn('store_branch_id', $assignedStoreIds)  // User is receiving store
                  ->orWhereIn('sending_store_branch_id', $assignedStoreIds); // User is sending store
            });
        }

        // Apply filters
        if (!empty($filters['sending_store_id'])) {
            $query->where('sending_store_branch_id', $filters['sending_store_id']);
        }

        if (!empty($filters['receiving_store_id'])) {
            $query->where('store_branch_id', $filters['receiving_store_id']);
        }

        if (!empty($filters['interco_status']) && $filters['interco_status'] !== 'all') {
            $query->where('interco_status', $filters['interco_status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('interco_number', 'like', "%{$search}%")
                  ->orWhereHas('sendingStore', fn($sq) => $sq->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('store_branch', fn($rq) => $rq->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('store_order_items.sapMasterfile', fn($iq) => $iq->where('ItemCode', 'like', "%{$search}%")
                                                                                                    ->orWhere('ItemDescription', 'like', "%{$search}%"));
            });
        }

        // Get paginated orders
        $orders = $query->orderBy('order_date', 'desc')
            ->paginate($filters['per_page'])
            ->withQueryString();

        // Transform data for line-item reporting
        $lineItems = [];
        foreach ($orders->items() as $order) {
            foreach ($order->store_order_items as $item) {
                if ($item->sapMasterfile) {
                    // Get shipped date from COMMIT remarks
                    $shippedDate = $order->store_order_remarks->first()?->created_at;

                    // Get received dates and expiry dates from receive history
                    $receivedDates = $item->ordered_item_receive_dates
                        ->where('status', 'approved')
                        ->pluck('received_date')
                        ->filter();

                    $expiryDates = $item->ordered_item_receive_dates
                        ->where('status', 'approved')
                        ->pluck('expiry_date')
                        ->filter();

                    // Calculate total received quantity from approved receive history
                    $totalReceivedQuantity = $item->ordered_item_receive_dates
                        ->where('status', 'approved')
                        ->sum('quantity_received');

                    $lineItems[] = [
                        'id' => $item->id,
                        'item_code' => $item->sapMasterfile->ItemCode,
                        'item_description' => $item->sapMasterfile->ItemDescription,
                        'committed_qty' => $item->quantity_commited,
                        'received_qty' => $totalReceivedQuantity,
                        'uom' => $item->sapMasterfile->BaseUOM,
                        'requested_delivery_date' => $order->order_date,
                        'interco_reason' => $order->interco_reason,
                        'to_store' => $order->store_branch->name . ' (' . $order->store_branch->brand_name . ')',
                        'from_store' => $order->sendingStore->name . ' (' . $order->sendingStore->brand_name . ')',
                        'interco_number' => $order->interco_number,
                        'status' => $order->interco_status,
                        'expiry_dates' => $expiryDates->unique()->values(),
                        'unit_cost' => $item->cost_per_quantity,
                        'total_cost' => $item->total_cost,
                        'shipped_date' => $shippedDate,
                        'received_dates' => $receivedDates->unique()->values(),
                        'order' => $order
                    ];
                }
            }
        }

        // Get status options for filter
        $statusOptions = collect(IntercoStatus::cases())
            ->filter(fn($status) => $status->value !== 'committed') // Remove 'committed'
            ->map(fn($status) => [
                'value' => $status->value,
                'label' => $status->getLabel()
            ])
            ->prepend(['value' => 'all', 'label' => 'All Statuses']) // Add 'All Statuses'
            ->values();


        return Inertia::render('Reports/IntercoReport/Index', [
            'lineItems' => $lineItems,
            'orders' => $orders,
            'filters' => $filters,
            'stores' => $stores,
            'statusOptions' => $statusOptions,
            'assignedStoreIds' => $assignedStoreIds
        ]);
    }

    /**
     * Export Interco Report to Excel.
     */
    public function export(Request $request)
    {
        // Get filter parameters from the request
        $filters = $request->only([
            'date_from',
            'date_to',
            'sending_store_id',
            'receiving_store_id',
            'interco_status',
            'search'
        ]);

        // Export to Excel using IntercoReportExport class
        return Excel::download(
            new IntercoReportExport($filters),
            'interco-report-' . Carbon::now()->format('Y-m-d') . '.xlsx'
        );
    }
}