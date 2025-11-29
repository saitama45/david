<?php

namespace App\Http\Controllers;

use App\Models\StoreOrder;
use App\Models\StoreBranch;
use App\Models\SapMasterfile;
use App\Models\StoreOrderItem;
use App\Exports\DeliveryReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class DeliveryReportController extends Controller
{
    /**
     * Display Delivery Report page.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Get filter parameters
        $filters = $request->only([
            'date_from',
            'date_to',
            'store_ids',
            'search',
            'per_page'
        ]);

        // Set default values
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');
        $filters['per_page'] = $filters['per_page'] ?? 50;

        // Get user's assigned stores and prepare for filtering
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');
        $stores = StoreBranch::whereIn('id', $assignedStoreIds)
            ->orderBy('name')
            ->get(['id', 'name', 'brand_code']);

        // Handle store_ids filter logic
        if (!$request->has('store_ids')) {
            $filters['store_ids'] = $assignedStoreIds->toArray();
        }
        $filters['store_ids'] = array_intersect($filters['store_ids'] ?? [], $assignedStoreIds->toArray());


        // --- REFACTORED QUERY ---
        // Start query from StoreOrderItem for precise filtering
        $query = StoreOrderItem::with([
            'store_order' => function ($q) {
                $q->with(['store_branch', 'delivery_receipts']);
            },
            'sapMasterfile',
            'ordered_item_receive_dates'
        ]);

        // Filter by base order status (COMMITTED/RECEIVED) and existence of a delivery receipt
        $query->whereHas('store_order', function ($q) {
            $q->whereIn('order_status', ['COMMITTED', 'RECEIVED'])
              ->whereHas('delivery_receipts');
        });

        // Apply Permission Filter: Ensure items belong to orders from assigned stores
        if ($assignedStoreIds->isNotEmpty()) {
            $query->whereHas('store_order', function($q) use ($assignedStoreIds) {
                $q->whereIn('store_branch_id', $assignedStoreIds);
            });
        }

        // Apply Store Filter from user selection
        $query->whereHas('store_order', function($q) use ($filters) {
            if (!empty($filters['store_ids'])) {
                $q->whereIn('store_branch_id', $filters['store_ids']);
            } else {
                // If user unselects all stores, return no results.
                $q->whereRaw('1 = 0');
            }
        });

        // Apply Date Filter
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $query->whereHas('ordered_item_receive_dates', function($sub) use ($filters) {
                if (!empty($filters['date_from'])) {
                    $sub->whereDate('received_date', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $sub->whereDate('received_date', '<=', $filters['date_to']);
                }
            });
        }

        // Apply Search Filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('item_code', 'like', "%{$search}%")
                    ->orWhereHas('sapMasterfile', function($sap) use ($search) {
                        $sap->where('ItemDescription', 'like', "%{$search}%");
                    })
                    ->orWhereHas('store_order.delivery_receipts', function($dr) use ($search) {
                        $dr->where('sap_so_number', 'like', "%{$search}%")
                           ->orWhere('delivery_receipt_number', 'like', "%{$search}%");
                    });
            });
        }

        // Get paginated results
        $items = $query->paginate($filters['per_page']);

        // Build the final flat delivery data from the filtered & paginated items
        $deliveryData = [];
        foreach ($items as $item) {
            // Since an item can be received on multiple dates, we loop through them
            foreach ($item->ordered_item_receive_dates as $receiveDate) {
                $deliveryData[] = [
                    'id' => $receiveDate->id, // Unique key for the v-for
                    'date_received' => $receiveDate->received_date,
                    'store_name' => optional($item->store_order->store_branch)->name,
                    'store_code' => optional($item->store_order->store_branch)->brand_code,
                    'item_code' => $item->item_code,
                    'item_description' => optional($item->sapMasterfile)->ItemDescription ?? 'N/A',
                    'quantity_ordered' => $item->quantity_ordered,
                    'quantity_committed' => $item->quantity_commited,
                    'quantity_received' => $receiveDate->quantity_received,
                    'so_number' => optional($item->store_order->delivery_receipts->first())->sap_so_number,
                    'dr_number' => optional($item->store_order->delivery_receipts->first())->delivery_receipt_number,
                    'store_branch_id' => optional($item->store_order)->store_branch_id
                ];
            }
        }

        return Inertia::render('Reports/DeliveryReport/Index', [
            'deliveryData' => $deliveryData,
            'paginatedData' => $items, // Pass the paginator instance for links and totals
            'filters' => $filters,
            'stores' => $stores,
            'assignedStoreIds' => $assignedStoreIds
        ]);
    }

    /**
     * Export Delivery Report to Excel.
     */
    public function export(Request $request)
    {
        $user = Auth::user();

        // Get filter parameters
        $filters = $request->only([
            'date_from',
            'date_to',
            'store_ids',
            'search'
        ]);

        // Set default values
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');

        // Get user's assigned stores and prepare for filtering
        $user->load('store_branches');
        $assignedStoreIds = $user->store_branches->pluck('id');

        // Handle store_ids filter logic
        if (!$request->has('store_ids')) {
            $filters['store_ids'] = $assignedStoreIds->toArray();
        }
        $filters['store_ids'] = array_intersect($filters['store_ids'] ?? [], $assignedStoreIds->toArray());


        // --- REFACTORED QUERY (Same as index method) ---
        // Start query from StoreOrderItem for precise filtering
        $query = StoreOrderItem::with([
            'store_order' => function ($q) {
                $q->with(['store_branch', 'delivery_receipts']);
            },
            'sapMasterfile',
            'ordered_item_receive_dates'
        ]);

        // Filter by base order status (COMMITTED/RECEIVED) and existence of a delivery receipt
        $query->whereHas('store_order', function ($q) {
            $q->whereIn('order_status', ['COMMITTED', 'RECEIVED'])
              ->whereHas('delivery_receipts');
        });

        // Apply Permission Filter: Ensure items belong to orders from assigned stores
        if ($assignedStoreIds->isNotEmpty()) {
            $query->whereHas('store_order', function($q) use ($assignedStoreIds) {
                $q->whereIn('store_branch_id', $assignedStoreIds);
            });
        }

        // Apply Store Filter from user selection
        $query->whereHas('store_order', function($q) use ($filters) {
            if (!empty($filters['store_ids'])) {
                $q->whereIn('store_branch_id', $filters['store_ids']);
            } else {
                // If user unselects all stores, return no results.
                $q->whereRaw('1 = 0');
            }
        });

        // Apply Date Filter
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $query->whereHas('ordered_item_receive_dates', function($sub) use ($filters) {
                if (!empty($filters['date_from'])) {
                    $sub->whereDate('received_date', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $sub->whereDate('received_date', '<=', $filters['date_to']);
                }
            });
        }

        // Apply Search Filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('item_code', 'like', "%{$search}%")
                    ->orWhereHas('sapMasterfile', function($sap) use ($search) {
                        $sap->where('ItemDescription', 'like', "%{$search}%");
                    })
                    ->orWhereHas('store_order.delivery_receipts', function($dr) use ($search) {
                        $dr->where('sap_so_number', 'like', "%{$search}%")
                           ->orWhere('delivery_receipt_number', 'like', "%{$search}%");
                    });
            });
        }

        // Get all results for export (no pagination)
        $items = $query->orderBy('store_order_id')->get(); // Order for consistent export

        // Build the final flat delivery data from the filtered items
        $deliveryData = [];
        foreach ($items as $item) {
            foreach ($item->ordered_item_receive_dates as $receiveDate) {
                $deliveryData[] = [
                    'id' => $receiveDate->id, // Unique key for the v-for
                    'date_received' => $receiveDate->received_date,
                    'store_name' => optional($item->store_order->store_branch)->name,
                    'store_code' => optional($item->store_order->store_branch)->brand_code,
                    'item_code' => $item->item_code,
                    'item_description' => optional($item->sapMasterfile)->ItemDescription ?? 'N/A',
                    'quantity_ordered' => $item->quantity_ordered,
                    'quantity_committed' => $item->quantity_commited,
                    'quantity_received' => $receiveDate->quantity_received,
                    'so_number' => optional($item->store_order->delivery_receipts->first())->sap_so_number,
                    'dr_number' => optional($item->store_order->delivery_receipts->first())->delivery_receipt_number,
                    'store_branch_id' => optional($item->store_order)->store_branch_id
                ];
            }
        }

        return Excel::download(new DeliveryReportExport($deliveryData, $filters), 'delivery-report.xlsx');
    }
}