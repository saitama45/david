<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $massOrdersApprovalCount = 0;
        $csMassCommitsCount = 0;
        $csMassCommitsDates = [];
        $csDtsMassCommitsCount = 0;
        $csDtsMassCommitsBatches = [];
        $intercoApprovalCount = 0;
        $intercoApprovalDates = [];
        $storeCommitsCount = 0;
        $storeCommitsDates = [];
        $wastageLvl1Count = 0;
        $wastageLvl1Dates = [];
        $wastageLvl2Count = 0;
        $wastageLvl2Dates = [];
        $monthEndLvl1Count = 0;
        $monthEndLvl1Dates = [];
        $monthEndLvl2Count = 0;
        $monthEndLvl2Dates = [];

        if ($user) {
            if ($user->can('view mass order approval')) {
                $suppliersForApproval = \App\Models\Supplier::where('is_forapproval_massorders', true)->pluck('id');
                $massOrdersApprovalCount = \App\Models\StoreOrder::where('variant', 'mass regular')
                    ->whereIn('supplier_id', $suppliersForApproval)
                    ->where('order_status', 'pending')
                    ->count();
            }

            if ($user->can('edit finished good commits') || $user->can('edit other commits')) {
                $csMassCommitsQuery = \App\Models\StoreOrder::where('variant', 'mass regular')
                    ->where('order_status', 'approved')
                    ->whereHas('storeOrderItems', function ($q) {
                        $q->where('quantity_commited', '>', 0);
                    });

                $csMassCommitsCount = $csMassCommitsQuery->count();
                
                if ($csMassCommitsCount > 0) {
                     $csMassCommitsDates = $csMassCommitsQuery->pluck('order_date')
                        ->unique()
                        ->sort()
                        ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                        ->values()
                        ->toArray();
                }
            }

            if ($user->can('edit cs dts mass commit')) {
                $csDtsQuery = \App\Models\StoreOrder::where('variant', 'mass dts')
                    ->where('order_status', 'approved')
                    ->whereNotNull('batch_reference');

                $csDtsMassCommitsCount = $csDtsQuery->distinct('batch_reference')->count('batch_reference');

                if ($csDtsMassCommitsCount > 0) {
                    $csDtsMassCommitsBatches = $csDtsQuery->distinct('batch_reference')
                        ->pluck('batch_reference')
                        ->sort()
                        ->values()
                        ->toArray();
                }
            }

            if ($user->can('view interco approvals')) {
                $user->load('store_branches');
                $assignedStoreIds = $user->store_branches->pluck('id');

                if ($assignedStoreIds->isNotEmpty()) {
                    $intercoApprovalQuery = \App\Models\StoreOrder::whereNotNull('interco_number')
                        ->whereNotNull('sending_store_branch_id')
                        ->where('variant', 'INTERCO')
                        ->whereIn('store_branch_id', $assignedStoreIds)
                        ->where('interco_status', 'open');

                    $intercoApprovalCount = $intercoApprovalQuery->count();
                    if ($intercoApprovalCount > 0) {
                        $intercoApprovalDates = $intercoApprovalQuery->pluck('order_date')
                            ->unique()
                            ->sort()
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                            ->values()
                            ->toArray();
                    }
                }
            }

            if ($user->can('view store commits')) {
                $user->load('store_branches');
                $assignedSendingStoreIds = $user->store_branches->pluck('id');

                $storeCommitsQuery = \App\Models\StoreOrder::whereNotNull('interco_number')
                    ->whereNotNull('store_branch_id')
                    ->whereNotNull('interco_status')
                    ->where('interco_status', 'approved');

                if (!$user->is_admin && $assignedSendingStoreIds->isNotEmpty()) {
                    $storeCommitsQuery->whereIn('sending_store_branch_id', $assignedSendingStoreIds);
                } elseif (!$user->is_admin && $assignedSendingStoreIds->isEmpty()) {
                     $storeCommitsQuery->whereRaw('1 = 0');
                }

                $storeCommitsCount = $storeCommitsQuery->count();
                if ($storeCommitsCount > 0) {
                    $storeCommitsDates = $storeCommitsQuery->pluck('order_date')
                        ->unique()
                        ->sort()
                        ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                        ->values()
                        ->toArray();
                }
            }
            
            if ($user->can('view wastage approval level 1')) {
                \Illuminate\Support\Facades\Log::info('Wastage L1: Permission check passed.');
                $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
                    ->pluck('store_branch_id');
                \Illuminate\Support\Facades\Log::info('Wastage L1: Assigned store IDs count: ' . $assignedStoreIds->count());
                \Illuminate\Support\Facades\Log::info('Wastage L1: Assigned store IDs: ' . $assignedStoreIds->implode(', '));

                if ($assignedStoreIds->isNotEmpty()) {
                    $baseWastageLvl1Query = \App\Models\Wastage::whereIn('store_branch_id', $assignedStoreIds)
                        ->where('wastage_status', 'pending');
                    
                    $wastageLvl1CountSubQuery = (clone $baseWastageLvl1Query)->select('wastage_no', 'store_branch_id')->distinct();
                    $wastageLvl1Count = \Illuminate\Support\Facades\DB::table($wastageLvl1CountSubQuery, 'sub')->count();

                    \Illuminate\Support\Facades\Log::info('Wastage L1: Calculated count is: ' . $wastageLvl1Count);

                    if ($wastageLvl1Count > 0) {
                        $wastageLvl1DatesQuery = (clone $baseWastageLvl1Query)
                            ->selectRaw('CONVERT(date, created_at) as date')
                            ->distinct()
                            ->orderBy('date');
                        
                        $wastageLvl1Dates = $wastageLvl1DatesQuery->pluck('date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                            ->values()
                            ->toArray();
                    }
                }
            } else {
                \Illuminate\Support\Facades\Log::info('Wastage L1: Permission check FAILED.');
            }

            if ($user->can('view wastage approval level 2')) {
                $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
                    ->pluck('store_branch_id');

                if ($assignedStoreIds->isNotEmpty()) {
                    $baseWastageLvl2Query = \App\Models\Wastage::whereIn('store_branch_id', $assignedStoreIds)
                        ->where('wastage_status', 'approved_lvl1');
                    
                    $wastageLvl2CountSubQuery = (clone $baseWastageLvl2Query)->select('wastage_no', 'store_branch_id')->distinct();
                    $wastageLvl2Count = \Illuminate\Support\Facades\DB::table($wastageLvl2CountSubQuery, 'sub')->count();

                    if ($wastageLvl2Count > 0) {
                        $wastageLvl2DatesQuery = (clone $baseWastageLvl2Query)
                            ->selectRaw('CONVERT(date, created_at) as date')
                            ->distinct()
                            ->orderBy('date');
                        
                        $wastageLvl2Dates = $wastageLvl2DatesQuery->pluck('date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                            ->values()
                            ->toArray();
                    }
                }
            }
            
            if ($user->can('approve month end count level 1')) {
                $assignedStoreIds = $user->store_branches->pluck('id');
                if ($assignedStoreIds->isNotEmpty()) {
                    $monthEndLvl1Query = \App\Models\MonthEndCountItem::whereIn('branch_id', $assignedStoreIds)
                        ->where('month_end_count_items.status', 'pending_level1_approval');
                    
                    $monthEndLvl1SubQuery = (clone $monthEndLvl1Query)->select('month_end_schedule_id', 'branch_id')->distinct();
                    $monthEndLvl1Count = \Illuminate\Support\Facades\DB::table($monthEndLvl1SubQuery, 'sub')->count();

                    if ($monthEndLvl1Count > 0) {
                         $monthEndLvl1Dates = $monthEndLvl1Query->join('month_end_schedules as mes', 'month_end_count_items.month_end_schedule_id', '=', 'mes.id')
                            ->selectRaw('CONVERT(date, mes.calculated_date) as date')
                            ->distinct()
                            ->orderBy('date')
                            ->pluck('date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                            ->values()
                            ->toArray();
                    }
                }
            }

            if ($user->can('approve month end count level 2')) {
                $assignedStoreIds = $user->store_branches->pluck('id');
                if ($assignedStoreIds->isNotEmpty()) {
                    $monthEndLvl2Query = \App\Models\MonthEndCountItem::whereIn('branch_id', $assignedStoreIds)
                        ->where('month_end_count_items.status', 'level1_approved');

                    $monthEndLvl2SubQuery = (clone $monthEndLvl2Query)->select('month_end_schedule_id', 'branch_id')->distinct();
                    $monthEndLvl2Count = \Illuminate\Support\Facades\DB::table($monthEndLvl2SubQuery, 'sub')->count();
                    
                    if ($monthEndLvl2Count > 0) {
                         $monthEndLvl2Dates = $monthEndLvl2Query->join('month_end_schedules as mes', 'month_end_count_items.month_end_schedule_id', '=', 'mes.id')
                            ->selectRaw('CONVERT(date, mes.calculated_date) as date')
                            ->distinct()
                            ->orderBy('date')
                            ->pluck('date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                            ->values()
                            ->toArray();
                    }
                }
            }
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? $request->user()->load('roles', 'permissions') : null,
                'roles' => $request->user() ? $request->user()->getRoleNames() : [],
                'permissions' => $request->user() ? $request->user()->getAllPermissions()->pluck('name') : [],
                'is_admin' => $request->user() ? $request->user()->hasRole('admin') : false,
            ],
            'notifications' => [
                'massOrdersApprovalCount' => $massOrdersApprovalCount,
                'csMassCommitsCount' => $csMassCommitsCount,
                'csMassCommitsDates' => $csMassCommitsDates,
                'csDtsMassCommitsCount' => $csDtsMassCommitsCount,
                'csDtsMassCommitsBatches' => $csDtsMassCommitsBatches,
                'intercoApprovalCount' => $intercoApprovalCount,
                'intercoApprovalDates' => $intercoApprovalDates,
                'storeCommitsCount' => $storeCommitsCount,
                'storeCommitsDates' => $storeCommitsDates,
                'wastageLvl1Count' => $wastageLvl1Count,
                'wastageLvl1Dates' => $wastageLvl1Dates,
                'wastageLvl2Count' => $wastageLvl2Count,
                'wastageLvl2Dates' => $wastageLvl2Dates,
                'monthEndLvl1Count' => $monthEndLvl1Count,
                'monthEndLvl1Dates' => $monthEndLvl1Dates,
                'monthEndLvl2Count' => $monthEndLvl2Count,
                'monthEndLvl2Dates' => $monthEndLvl2Dates,
            ],
            'flash' => [
                'message' => fn() => $request->session()->get('message'),
                'import_summary' => fn() => $request->session()->get('import_summary'),
                'success' => fn() => $request->session()->get('success'),
                'error' => fn() => $request->session()->get('error'),
                'skippedItems' => fn () => $request->session()->get('skippedItems'),
                'warning' => fn () => $request->session()->get('warning'),
                'skipped_import_rows' => fn () => $request->session()->get('skipped_import_rows'),
                'created_count' => fn () => $request->session()->get('created_count'),
                'skipped_stores' => fn () => $request->session()->get('skipped_stores'),
            ],
            'previous' => fn() => URL::previous(),
        ];
    }
}
