<?php

namespace App\Http\Controllers;

use App\Models\Wastage;
use App\Models\StoreBranch;
use App\Enums\WastageStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\WastageReportExport;
use Carbon\Carbon;

class WastageReportController extends Controller
{
    /**
     * Display a listing of wastage records as a report
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        // Get filter parameters
        $filters = $request->only([
            'date_from',
            'date_to',
            'store_branch_id',
            'status',
            'search',
            'per_page',
            'sort_field',
            'sort_direction',
            'filter_wastage_no',
            'filter_store',
            'filter_item',
            'filter_status',
            'filter_reason'
        ]);

        // Set default values
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');
        $filters['status'] = $filters['status'] ?? 'approved_lvl2';
        $filters['per_page'] = $filters['per_page'] ?? 50;

        // Get user's assigned store IDs
        $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();

        // Base query for wastage records with joins for sorting/filtering
        $query = Wastage::query()
            ->select('wastages.*')
            ->leftJoin('store_branches', 'store_branches.id', '=', 'wastages.store_branch_id')
            ->leftJoin('sap_masterfiles', 'sap_masterfiles.id', '=', 'wastages.sap_masterfile_id')
            ->with(['storeBranch', 'sapMasterfile'])
            ->when(!empty($assignedStoreIds), function ($q) use ($assignedStoreIds) {
                $q->whereIn('wastages.store_branch_id', $assignedStoreIds);
            })
            ->when($filters['date_from'], function ($q) use ($filters) {
                $q->whereDate('wastages.created_at', '>=', $filters['date_from']);
            })
            ->when($filters['date_to'], function ($q) use ($filters) {
                $q->whereDate('wastages.created_at', '<=', $filters['date_to']);
            })
            ->when(!empty($filters['store_branch_id']), function ($q) use ($filters) {
                $q->where('wastages.store_branch_id', $filters['store_branch_id']);
            })
            ->when(!empty($filters['status']), function ($q) use ($filters) {
                if ($filters['status'] !== 'all') {
                    $q->where('wastages.wastage_status', $filters['status']);
                }
            })
            ->when(!empty($filters['search']), function ($q) use ($filters) {
                $search = $filters['search'];
                $q->where(function ($query) use ($search) {
                    $query->where('wastages.wastage_no', 'like', '%' . $search . '%')
                          ->orWhere('store_branches.name', 'like', '%' . $search . '%')
                          ->orWhere('store_branches.branch_code', 'like', '%' . $search . '%')
                          ->orWhere('sap_masterfiles.ItemDescription', 'like', '%' . $search . '%')
                          ->orWhere('sap_masterfiles.ItemCode', 'like', '%' . $search . '%');
                });
            });

        // Apply column-specific filters
        if (!empty($filters['filter_wastage_no'])) {
            $query->where('wastages.wastage_no', 'like', "%{$filters['filter_wastage_no']}%");
        }
        if (!empty($filters['filter_store'])) {
            $query->where('store_branches.name', 'like', "%{$filters['filter_store']}%");
        }
        if (!empty($filters['filter_item'])) {
            $query->where(function($q) use ($filters) {
                $q->where('sap_masterfiles.ItemCode', 'like', "%{$filters['filter_item']}%")
                  ->orWhere('sap_masterfiles.ItemDescription', 'like', "%{$filters['filter_item']}%");
            });
        }
        if (!empty($filters['filter_status'])) {
            $query->where('wastages.wastage_status', 'like', "%{$filters['filter_status']}%");
        }
        if (!empty($filters['filter_reason'])) {
            $query->where('wastages.reason', 'like', "%{$filters['filter_reason']}%");
        }

        // Calculate summary totals before pagination
        $summaryQuery = clone $query;
        $summaryTotals = [
            'total_records' => $summaryQuery->count(),
            'total_quantity' => $summaryQuery->sum('wastages.wastage_qty'),
            'total_cost' => $summaryQuery->sum(DB::raw('wastages.wastage_qty * wastages.cost')),
        ];

        // Apply Sorting
        if (!empty($filters['sort_field'])) {
            $sortField = $filters['sort_field'];
            $sortDir = $filters['sort_direction'] ?? 'asc';
            
            $sortMap = [
                'wastage_no' => 'wastages.wastage_no',
                'store_name' => 'store_branches.name',
                'item_description' => 'sap_masterfiles.ItemDescription',
                'wastage_qty' => 'wastages.wastage_qty',
                'cost' => 'wastages.cost',
                'total_cost' => DB::raw('wastages.wastage_qty * wastages.cost'),
                'wastage_status' => 'wastages.wastage_status',
                'reason' => 'wastages.reason',
                'created_at' => 'wastages.created_at',
            ];

            if (isset($sortMap[$sortField])) {
                $query->orderBy($sortMap[$sortField], $sortDir);
            }
        } else {
            $query->orderBy('wastages.created_at', 'desc');
        }

        // Paginate the results
        $paginatedData = $query->paginate($filters['per_page'])->withQueryString();

        // Get store options for filtering
        $storeOptions = StoreBranch::whereIn('id', $assignedStoreIds)
            ->orderBy('name')
            ->get()
            ->map(function ($store) {
                return [
                    'value' => $store->id,
                    'label' => $store->name . ' (' . $store->branch_code . ')',
                ];
            });

        // Get status options
        $statusOptions = collect(WastageStatus::cases())->map(function ($status) {
            return [
                'value' => $status->value,
                'label' => $status->getLabel(),
            ];
        });
        $statusOptions->prepend(['value' => 'all', 'label' => 'All Statuses']);

        return Inertia::render('Reports/WastageReport/Index', [
            'wastages' => $paginatedData->items(),
            'paginatedData' => $paginatedData,
            'filters' => $filters,
            'stores' => $storeOptions,
            'statusOptions' => $statusOptions,
            'assignedStoreIds' => $assignedStoreIds,
            'summaryTotals' => $summaryTotals,
        ]);
    }

    /**
     * Export wastage report to Excel
     */
    public function export(Request $request)
    {
        $user = auth()->user();

        if (!$user->hasPermissionTo('export wastage report')) {
            abort(403, 'You do not have permission to export wastage report');
        }

        try {
            // Get filter parameters
            $filters = $request->only([
                'date_from',
                'date_to',
                'store_branch_id',
                'status',
                'search',
                'filter_wastage_no',
                'filter_store',
                'filter_item',
                'filter_status',
                'filter_reason'
            ]);

            // Set defaults for export
            $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
            $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');

            // Get user's assigned store IDs
            $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
                ->pluck('store_branch_id')
                ->toArray();

            // Base query for wastage records with joins (same as index)
            $query = Wastage::query()
                ->select('wastages.*')
                ->leftJoin('store_branches', 'store_branches.id', '=', 'wastages.store_branch_id')
                ->leftJoin('sap_masterfiles', 'sap_masterfiles.id', '=', 'wastages.sap_masterfile_id')
                ->when(!empty($assignedStoreIds), function ($q) use ($assignedStoreIds) {
                    $q->whereIn('wastages.store_branch_id', $assignedStoreIds);
                })
                ->when($filters['date_from'], function ($q) use ($filters) {
                    $q->whereDate('wastages.created_at', '>=', $filters['date_from']);
                })
                ->when($filters['date_to'], function ($q) use ($filters) {
                    $q->whereDate('wastages.created_at', '<=', $filters['date_to']);
                })
                ->when(!empty($filters['store_branch_id']), function ($q) use ($filters) {
                    $q->where('wastages.store_branch_id', $filters['store_branch_id']);
                })
                ->when(!empty($filters['status']), function ($q) use ($filters) {
                    if ($filters['status'] !== 'all') {
                        $q->where('wastages.wastage_status', $filters['status']);
                    }
                })
                ->when(!empty($filters['search']), function ($q) use ($filters) {
                    $search = $filters['search'];
                    $q->where(function ($query) use ($search) {
                        $query->where('wastages.wastage_no', 'like', '%' . $search . '%')
                              ->orWhere('store_branches.name', 'like', '%' . $search . '%')
                              ->orWhere('sap_masterfiles.ItemDescription', 'like', '%' . $search . '%');
                    });
                });

            // Apply column filters (same as index)
            if (!empty($filters['filter_wastage_no'])) {
                $query->where('wastages.wastage_no', 'like', "%{$filters['filter_wastage_no']}%");
            }
            if (!empty($filters['filter_store'])) {
                $query->where('store_branches.name', 'like', "%{$filters['filter_store']}%");
            }
            if (!empty($filters['filter_item'])) {
                $query->where(function($q) use ($filters) {
                    $q->where('sap_masterfiles.ItemCode', 'like', "%{$filters['filter_item']}%")
                      ->orWhere('sap_masterfiles.ItemDescription', 'like', "%{$filters['filter_item']}%");
                });
            }
            if (!empty($filters['filter_status'])) {
                $query->where('wastages.wastage_status', 'like', "%{$filters['filter_status']}%");
            }
            if (!empty($filters['filter_reason'])) {
                $query->where('wastages.reason', 'like', "%{$filters['filter_reason']}%");
            }

            // Get all filtered wastage records
            $wastageRecords = $query->orderBy('wastages.created_at', 'desc')->get();

            // Transform the individual records for export
            $exportData = $wastageRecords->map(function ($item) {
                return [
                    'Wastage #' => $item->wastage_no,
                    'Store' => $item->storeBranch ? $item->storeBranch->name : 'N/A',
                    'Item Code' => $item->sapMasterfile ? $item->sapMasterfile->ItemCode : 'N/A',
                    'Item Description' => $item->sapMasterfile ? $item->sapMasterfile->ItemDescription : 'N/A',
                    'UoM' => $item->sapMasterfile ? $item->sapMasterfile->BaseUOM : 'N/A',
                    'Quantity' => $item->wastage_qty,
                    'Unit Cost' => $item->cost,
                    'Total Cost' => $item->wastage_qty * $item->cost,
                    'Status' => $item->wastage_status->getLabel(),
                    'Reason' => $item->reason,
                    'Remarks' => $item->remarks,
                    'Date' => $item->created_at->format('m/d/Y h:i A'),
                ];
            })->toArray();

            $export = new WastageReportExport($exportData);

            return Excel::download($export, 'wastage_report_' . now()->format('Y_m_d_His') . '.xlsx');

        } catch (\Exception $e) {
            \Log::error('Wastage report export failed: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'filters' => $filters ?? []
            ]);

            return back()->withErrors(['error' => 'Failed to export wastage report: ' . $e->getMessage()]);
        }
    }
}