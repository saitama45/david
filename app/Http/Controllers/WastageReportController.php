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
            'per_page'
        ]);

        // Set default values (copied from PMIXReportController logic)
        $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');
        $filters['per_page'] = $filters['per_page'] ?? 50;

        // Get user's assigned store IDs
        $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
            ->pluck('store_branch_id')
            ->toArray();

        // Base query for wastage records
        $query = Wastage::with(['storeBranch', 'sapMasterfile'])
            ->when(!empty($assignedStoreIds), function ($q) use ($assignedStoreIds) {
                $q->whereIn('store_branch_id', $assignedStoreIds);
            })
            ->when($filters['date_from'], function ($q) use ($filters) {
                $q->whereDate('created_at', '>=', $filters['date_from']);
            })
            ->when($filters['date_to'], function ($q) use ($filters) {
                $q->whereDate('created_at', '<=', $filters['date_to']);
            })
            ->when(!empty($filters['store_branch_id']), function ($q) use ($filters) {
                $q->where('store_branch_id', $filters['store_branch_id']);
            })
            ->when(!empty($filters['status']), function ($q) use ($filters) {
                $q->where('wastage_status', $filters['status']);
            })
            ->when(!empty($filters['search']), function ($q) use ($filters) {
                $search = $filters['search'];
                $q->where(function ($query) use ($search) {
                    $query->where('wastage_no', 'like', '%' . $search . '%')
                          ->orWhereHas('storeBranch', function ($subQuery) use ($search) {
                              $subQuery->where('name', 'like', '%' . $search . '%')
                                       ->orWhere('branch_code', 'like', '%' . $search . '%');
                          });
                });
            });

        // Group wastage records by wastage_no to get the report data
        $wastageGroups = $query->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('wastage_no');

        // Transform the grouped data for the report
        $reportData = $wastageGroups->map(function ($wastageItems, $wastageNo) {
            $firstItem = $wastageItems->first();

            // Calculate totals
            $totalQty = $wastageItems->sum('wastage_qty');
            $itemsCount = $wastageItems->count();
            $totalCost = $wastageItems->sum(function ($item) {
                return ($item->wastage_qty ?? 0) * ($item->cost ?? 0);
            });

            return [
                'wastage_no' => $wastageNo,
                'store' => $firstItem->storeBranch ? $firstItem->storeBranch->name : 'Unknown',
                'store_branch_id' => $firstItem->store_branch_id,
                'total_qty' => $totalQty,
                'items_count' => $itemsCount,
                'total_cost' => $totalCost,
                'status' => $firstItem->wastage_status->value,
                'status_label' => $firstItem->wastage_status->getLabel(),
                'reason' => $firstItem->reason,
                'created_at' => $firstItem->created_at,
                'formatted_date' => $firstItem->created_at->format('m/d/Y h:i A'),
            ];
        })->values();

        // Paginate the results
        $currentPage = request()->get('page', 1);
        $perPage = $filters['per_page'];
        $offset = ($currentPage - 1) * $perPage;

        $paginatedData = new \Illuminate\Pagination\LengthAwarePaginator(
            $reportData->slice($offset, $perPage),
            $reportData->count(),
            $perPage,
            $currentPage,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );

        // Get store options for filtering
        $storeOptions = StoreBranch::whereIn('id', $assignedStoreIds)
            ->when(!empty($assignedStoreIds), function ($q) use ($assignedStoreIds) {
                $q->whereIn('id', $assignedStoreIds);
            })
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

        // Calculate summary totals for dashboard cards
        $summaryTotals = [
            'total_records' => $reportData->count(),
            'total_quantity' => $reportData->sum('total_qty'),
            'total_cost' => $reportData->sum('total_cost'),
        ];

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
            // Get filter parameters (same as index method)
            $filters = $request->only([
                'date_from',
                'date_to',
                'store_branch_id',
                'status',
                'search'
            ]);

            // Set defaults for export (same as index method)
            $filters['date_from'] = $filters['date_from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
            $filters['date_to'] = $filters['date_to'] ?? Carbon::today()->format('Y-m-d');

            // Get user's assigned store IDs
            $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
                ->pluck('store_branch_id')
                ->toArray();

            // Base query for wastage records (same as index method)
            $query = Wastage::with(['storeBranch', 'sapMasterfile'])
                ->when(!empty($assignedStoreIds), function ($q) use ($assignedStoreIds) {
                    $q->whereIn('store_branch_id', $assignedStoreIds);
                })
                ->when($filters['date_from'], function ($q) use ($filters) {
                    $q->whereDate('created_at', '>=', $filters['date_from']);
                })
                ->when($filters['date_to'], function ($q) use ($filters) {
                    $q->whereDate('created_at', '<=', $filters['date_to']);
                })
                ->when(!empty($filters['store_branch_id']), function ($q) use ($filters) {
                    $q->where('store_branch_id', $filters['store_branch_id']);
                })
                ->when(!empty($filters['status']), function ($q) use ($filters) {
                    $q->where('wastage_status', $filters['status']);
                })
                ->when(!empty($filters['search']), function ($q) use ($filters) {
                    $search = $filters['search'];
                    $q->where(function ($query) use ($search) {
                        $query->where('wastage_no', 'like', '%' . $search . '%')
                              ->orWhereHas('storeBranch', function ($subQuery) use ($search) {
                                  $subQuery->where('name', 'like', '%' . $search . '%')
                                           ->orWhere('branch_code', 'like', '%' . $search . '%');
                              });
                    });
                });

            // Group wastage records by wastage_no to get the report data
            $wastageGroups = $query->orderBy('created_at', 'desc')
                ->get()
                ->groupBy('wastage_no');

            // Transform the grouped data for export
            $exportData = $wastageGroups->map(function ($wastageItems, $wastageNo) {
                $firstItem = $wastageItems->first();

                // Calculate totals
                $totalQty = $wastageItems->sum('wastage_qty');
                $itemsCount = $wastageItems->count();
                $totalCost = $wastageItems->sum(function ($item) {
                    return ($item->wastage_qty ?? 0) * ($item->cost ?? 0);
                });

                return [
                    'wastage_no' => $wastageNo,
                    'store' => $firstItem->storeBranch ? $firstItem->storeBranch->name : 'Unknown',
                    'total_qty' => $totalQty,
                    'items_count' => $itemsCount,
                    'total_cost' => $totalCost,
                    'status' => $firstItem->wastage_status->getLabel(),
                    'reason' => $firstItem->reason,
                    'created_at' => $firstItem->created_at->format('m/d/Y h:i A'),
                ];
            })->values()->toArray();

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