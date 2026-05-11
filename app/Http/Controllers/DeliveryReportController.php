<?php

namespace App\Http\Controllers;

use App\Models\StoreBranch;
use App\Models\StoreOrderItem;
use App\Exports\DeliveryReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            'per_page',
            'sort_field',
            'sort_direction',
            'filter_expected_date',
            'filter_received_date',
            'filter_store',
            'filter_supplier',
            'filter_status',
            'filter_item_code',
            'filter_item_description',
            'filter_uom',
            'filter_so',
            'filter_dr'
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
        $filters['store_ids'] = array_intersect((array) ($filters['store_ids'] ?? []), $assignedStoreIds->toArray());


        // --- REFACTORED QUERY ---
        // Start query from store_order_items to include committed items
        $query = DB::table('store_order_items as soi')
            ->select([
                'orv.received_date AS date_received',
                'so.order_date AS expected_delivery_date',
                'sb.name AS store_name',
                'sb.brand_code AS store_code',
                'sup.supplier_code',
                'so.order_status as status',
                'soi.item_code',
                'sm.ItemDescription AS item_description',
                'soi.uom',
                'soi.quantity_ordered',
                'soi.quantity_commited',
                'orv.quantity_received',
                'dr.sap_so_number AS so_number',
                'dr.delivery_receipt_number AS dr_number',
                'so.store_branch_id',
                'soi.id'
            ])
            ->join('store_orders as so', 'so.id', '=', 'soi.store_order_id')
            ->join('store_branches as sb', 'sb.id', '=', 'so.store_branch_id')
            ->leftJoin('suppliers as sup', 'sup.id', '=', 'so.supplier_id')
            ->leftJoin('delivery_receipts as dr', 'dr.store_order_id', '=', 'so.id')
            ->leftJoin('sap_masterfiles as sm', function($join) {
                $join->on('sm.ItemCode', '=', 'soi.item_code')
                     ->on('sm.AltUOM', '=', 'soi.uom');
            })
            ->leftJoin('ordered_item_receive_dates as orv', function($join) {
                $join->on('orv.store_order_item_id', '=', 'soi.id')
                     ->where('orv.status', 'approved');
            });

        // Filter by order status: committed, partial_committed, or received
        $query->whereIn('so.order_status', ['committed', 'partial_committed', 'received']);

        // Apply Date Filter
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $query->where(function($q) use ($filters) {
                // Check received_date if it exists
                $q->where(function($sub) use ($filters) {
                    $sub->whereNotNull('orv.received_date');
                    if (!empty($filters['date_from'])) $sub->where('orv.received_date', '>=', $filters['date_from']);
                    if (!empty($filters['date_to'])) $sub->where('orv.received_date', '<', DB::raw("DATEADD(day, 1, '{$filters['date_to']}')"));
                })
                // Or check expected_delivery_date (order_date) if not received yet
                ->orWhere(function($sub) use ($filters) {
                    $sub->whereNull('orv.received_date');
                    if (!empty($filters['date_from'])) $sub->where('so.order_date', '>=', $filters['date_from']);
                    if (!empty($filters['date_to'])) $sub->where('so.order_date', '<', DB::raw("DATEADD(day, 1, '{$filters['date_to']}')"));
                });
            });
        }

        // Apply Store Filter from user selection
        if (!empty($filters['store_ids'])) {
            $query->whereIn('so.store_branch_id', $filters['store_ids']);
        } else {
            // If user unselects all stores, return no results.
            $query->whereRaw('1 = 0');
        }

        // Apply Global Search Filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('soi.item_code', 'like', "%{$search}%")
                  ->orWhere('sm.ItemDescription', 'like', "%{$search}%")
                  ->orWhere('dr.sap_so_number', 'like', "%{$search}%")
                  ->orWhere('dr.delivery_receipt_number', 'like', "%{$search}%")
                  ->orWhere('sup.supplier_code', 'like', "%{$search}%");
            });
        }

        // Apply Column Filters
        if (!empty($filters['filter_expected_date'])) {
            $query->where('so.order_date', 'like', "%{$filters['filter_expected_date']}%");
        }
        if (!empty($filters['filter_received_date'])) {
            $query->where('orv.received_date', 'like', "%{$filters['filter_received_date']}%");
        }
        if (!empty($filters['filter_store'])) {
            $query->where(function($q) use ($filters) {
                $q->where('sb.name', 'like', "%{$filters['filter_store']}%")
                  ->orWhere('sb.brand_code', 'like', "%{$filters['filter_store']}%");
            });
        }
        if (!empty($filters['filter_supplier'])) {
            $query->where('sup.supplier_code', 'like', "%{$filters['filter_supplier']}%");
        }
        if (!empty($filters['filter_status'])) {
            $query->where('so.order_status', 'like', "%{$filters['filter_status']}%");
        }
        if (!empty($filters['filter_item_code'])) {
            $query->where('soi.item_code', 'like', "%{$filters['filter_item_code']}%");
        }
        if (!empty($filters['filter_item_description'])) {
            $query->where('sm.ItemDescription', 'like', "%{$filters['filter_item_description']}%");
        }
        if (!empty($filters['filter_uom'])) {
            $query->where('soi.uom', 'like', "%{$filters['filter_uom']}%");
        }
        if (!empty($filters['filter_so'])) {
            $query->where('dr.sap_so_number', 'like', "%{$filters['filter_so']}%");
        }
        if (!empty($filters['filter_dr'])) {
            $query->where('dr.delivery_receipt_number', 'like', "%{$filters['filter_dr']}%");
        }

        // Calculate totals based on the filtered query
        $totalsQuery = clone $query;
        // Clear the existing select columns and only fetch the sums
        $totals = $totalsQuery->reorder()->select([])->selectRaw('
            SUM(soi.quantity_ordered) as total_ordered,
            SUM(soi.quantity_commited) as total_committed,
            SUM(orv.quantity_received) as total_received
        ')->first();

        // Apply Sorting
        if (!empty($filters['sort_field'])) {
            $sortField = $filters['sort_field'];
            $sortDir = $filters['sort_direction'] ?? 'asc';
            
            // Map frontend field names to SQL columns
            $sortMap = [
                'expected_delivery_date' => 'so.order_date',
                'date_received' => 'orv.received_date',
                'store_name' => 'sb.name',
                'supplier_code' => 'sup.supplier_code',
                'status' => 'so.order_status',
                'item_code' => 'soi.item_code',
                'item_description' => 'sm.ItemDescription',
                'uom' => 'soi.uom',
                'so_number' => 'dr.sap_so_number',
                'dr_number' => 'dr.delivery_receipt_number',
                'quantity_ordered' => 'soi.quantity_ordered',
                'quantity_committed' => 'soi.quantity_commited',
                'quantity_received' => 'orv.quantity_received',
            ];

            if (isset($sortMap[$sortField])) {
                $query->orderBy($sortMap[$sortField], $sortDir);
            }
        } else {
            // Default sort by received_date DESC, then expected date
            $query->orderBy(DB::raw('COALESCE(orv.received_date, so.order_date)'), 'desc');
        }

        // Get paginated results
        $items = $query->paginate($filters['per_page'])->withQueryString();

        // Build the final flat delivery data from the filtered & paginated items
        $deliveryData = [];
        foreach ($items as $item) {
            $deliveryData[] = [
                'id' => $item->id ?? uniqid(), // Ensure unique key
                'date_received' => $item->date_received,
                'expected_delivery_date' => $item->expected_delivery_date,
                'store_name' => $item->store_name,
                'store_code' => $item->store_code,
                'supplier_code' => $item->supplier_code,
                'status' => $item->status,
                'item_code' => $item->item_code,
                'item_description' => $item->item_description,
                'uom' => $item->uom,
                'quantity_ordered' => $item->quantity_ordered,
                'quantity_committed' => $item->quantity_commited,
                'quantity_received' => $item->quantity_received,
                'so_number' => $item->so_number,
                'dr_number' => $item->dr_number,
                'store_branch_id' => $item->store_branch_id
            ];
        }

        return Inertia::render('Reports/DeliveryReport/Index', [
            'deliveryData' => $deliveryData,
            'paginatedData' => $items, // Pass the paginator instance for links and totals
            'filters' => $filters,
            'stores' => $stores,
            'assignedStoreIds' => $assignedStoreIds,
            'totals' => [
                'quantity_ordered' => $totals->total_ordered ?? 0,
                'quantity_committed' => $totals->total_committed ?? 0,
                'quantity_received' => $totals->total_received ?? 0,
            ]
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
        // Start query from store_order_items to include committed items
        $query = DB::table('store_order_items as soi')
            ->select([
                'orv.received_date AS date_received',
                'so.order_date AS expected_delivery_date',
                'sb.name AS store_name',
                'sb.brand_code AS store_code',
                'sup.supplier_code',
                'so.order_status as status',
                'soi.item_code',
                'sm.ItemDescription AS item_description',
                'soi.uom',
                'soi.quantity_ordered',
                'soi.quantity_commited',
                'orv.quantity_received',
                'dr.sap_so_number AS so_number',
                'dr.delivery_receipt_number AS dr_number',
                'so.store_branch_id'
            ])
            ->join('store_orders as so', 'so.id', '=', 'soi.store_order_id')
            ->join('store_branches as sb', 'sb.id', '=', 'so.store_branch_id')
            ->leftJoin('suppliers as sup', 'sup.id', '=', 'so.supplier_id')
            ->leftJoin('delivery_receipts as dr', 'dr.store_order_id', '=', 'so.id')
            ->leftJoin('sap_masterfiles as sm', function($join) {
                $join->on('sm.ItemCode', '=', 'soi.item_code')
                     ->on('sm.AltUOM', '=', 'soi.uom');
            })
            ->leftJoin('ordered_item_receive_dates as orv', function($join) {
                $join->on('orv.store_order_item_id', '=', 'soi.id')
                     ->where('orv.status', 'approved');
            });

        // Filter by order status: committed, partial_committed, or received
        $query->whereIn('so.order_status', ['committed', 'partial_committed', 'received']);

        // Apply Date Filter
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $query->where(function($q) use ($filters) {
                // Check received_date if it exists
                $q->where(function($sub) use ($filters) {
                    $sub->whereNotNull('orv.received_date');
                    if (!empty($filters['date_from'])) $sub->where('orv.received_date', '>=', $filters['date_from']);
                    if (!empty($filters['date_to'])) $sub->where('orv.received_date', '<', DB::raw("DATEADD(day, 1, '{$filters['date_to']}')"));
                })
                // Or check expected_delivery_date (order_date) if not received yet
                ->orWhere(function($sub) use ($filters) {
                    $sub->whereNull('orv.received_date');
                    if (!empty($filters['date_from'])) $sub->where('so.order_date', '>=', $filters['date_from']);
                    if (!empty($filters['date_to'])) $sub->where('so.order_date', '<', DB::raw("DATEADD(day, 1, '{$filters['date_to']}')"));
                });
            });
        }

        // Apply Store Filter from user selection
        if (!empty($filters['store_ids'])) {
            $query->whereIn('so.store_branch_id', $filters['store_ids']);
        } else {
            // If user unselects all stores, return no results.
            $query->whereRaw('1 = 0');
        }

        // Apply Search Filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('soi.item_code', 'like', "%{$search}%")
                  ->orWhere('sm.ItemDescription', 'like', "%{$search}%")
                  ->orWhere('dr.sap_so_number', 'like', "%{$search}%")
                  ->orWhere('dr.delivery_receipt_number', 'like', "%{$search}%")
                  ->orWhere('sup.supplier_code', 'like', "%{$search}%");
            });
        }

        // Sort by received_date DESC, then expected date
        $query->orderBy(DB::raw('COALESCE(orv.received_date, so.order_date)'), 'desc');

        // Get all results for export (no pagination)
        $items = $query->get(); // Order for consistent export

        // Build the final flat delivery data from the filtered items
        $deliveryData = [];
        foreach ($items as $item) {
            $deliveryData[] = [
                'id' => $item->id ?? uniqid(), // Ensure unique key
                'date_received' => $item->date_received,
                'expected_delivery_date' => $item->expected_delivery_date,
                'store_name' => $item->store_name,
                'store_code' => $item->store_code,
                'supplier_code' => $item->supplier_code,
                'status' => $item->status,
                'item_code' => $item->item_code,
                'item_description' => $item->item_description,
                'uom' => $item->uom,
                'quantity_ordered' => $item->quantity_ordered,
                'quantity_committed' => $item->quantity_commited,
                'quantity_received' => $item->quantity_received,
                'so_number' => $item->so_number,
                'dr_number' => $item->dr_number,
                'store_branch_id' => $item->store_branch_id
            ];
        }

        return Excel::download(new DeliveryReportExport($deliveryData, $filters), 'delivery-report.xlsx');
    }
}