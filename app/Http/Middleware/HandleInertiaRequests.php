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

        $notifications = [
            'massOrdersApprovalCount' => 0,
            'csMassCommitsCount' => 0,
            'csMassCommitsDates' => [],
            'csDtsMassCommitsCount' => 0,
            'csDtsMassCommitsBatches' => [],
            'intercoApprovalCount' => 0,
            'intercoApprovalDates' => [],
            'storeCommitsCount' => 0,
            'storeCommitsDates' => [],
            'wastageLvl1Count' => 0,
            'wastageLvl1Dates' => [],
            'wastageLvl2Count' => 0,
            'wastageLvl2Dates' => [],
            'monthEndLvl1Count' => 0,
            'monthEndLvl1Dates' => [],
            'monthEndLvl2Count' => 0,
            'monthEndLvl2Dates' => [],
            'orderReceivingCount' => 0,
            'orderReceivingDates' => [],
            'salesUploadReminderCount' => 0,
            'salesUploadMissingDetails' => [],
        ];

        if ($user) {
            $cacheKey = 'user_notifications_v5_' . $user->id;
            
            $notifications = \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(1), function () use ($user) {
                $data = [
                    'massOrdersApprovalCount' => 0,
                    'csMassCommitsCount' => 0,
                    'csMassCommitsDates' => [],
                    'csDtsMassCommitsCount' => 0,
                    'csDtsMassCommitsBatches' => [],
                    'intercoApprovalCount' => 0,
                    'intercoApprovalDates' => [],
                    'storeCommitsCount' => 0,
                    'storeCommitsDates' => [],
                    'wastageLvl1Count' => 0,
                    'wastageLvl1Dates' => [],
                    'wastageLvl2Count' => 0,
                    'wastageLvl2Dates' => [],
                    'monthEndLvl1Count' => 0,
                    'monthEndLvl1Dates' => [],
                    'monthEndLvl2Count' => 0,
                    'monthEndLvl2Dates' => [],
                    'orderReceivingCount' => 0,
                    'orderReceivingDates' => [],
                    'salesUploadReminderCount' => 0,
                    'salesUploadMissingDetails' => [],
                ];

                $assignedStoreIds = \App\Models\UserAssignedStoreBranch::where('user_id', $user->id)
                    ->pluck('store_branch_id');

                if ($user->can('view store transactions') && $assignedStoreIds->isNotEmpty()) {
                    $startDate = \Carbon\Carbon::today()->subDays(30);
                    $endDate = \Carbon\Carbon::yesterday();
                    
                    // CRITICAL FIX: Use distinct and select only needed columns to avoid pulling millions of rows
                    $existingTransactions = \App\Models\StoreTransaction::whereIn('store_branch_id', $assignedStoreIds)
                        ->whereBetween('order_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                        ->select('store_branch_id', 'order_date')
                        ->distinct()
                        ->get()
                        ->groupBy('store_branch_id')
                        ->map(function ($transactions) {
                            return $transactions->pluck('order_date')->map(function($date) {
                                return \Carbon\Carbon::parse($date)->format('Y-m-d');
                            })->toArray();
                        });

                    $branches = \App\Models\StoreBranch::whereIn('id', $assignedStoreIds)
                        ->where('is_active', true)
                        ->pluck('name', 'id');

                    $datesToCheck = [];
                    for ($date = $endDate->copy(); $date->gte($startDate); $date->subDay()) {
                        $datesToCheck[] = $date->format('Y-m-d');
                    }

                    foreach ($branches as $branchId => $branchName) {
                        $branchTransactions = $existingTransactions->get($branchId, []);
                        foreach ($datesToCheck as $checkDate) {
                            if (!in_array($checkDate, $branchTransactions)) {
                                $data['salesUploadMissingDetails'][] = [
                                    'branch' => $branchName,
                                    'date' => \Carbon\Carbon::parse($checkDate)->format('M d, Y'),
                                ];
                                if (count($data['salesUploadMissingDetails']) >= 50) break 2; // Hard limit for memory safety
                            }
                        }
                    }
                    $data['salesUploadReminderCount'] = count($data['salesUploadMissingDetails']);
                }

                if ($user->can('view approved orders')) {
                    $orderReceivingQuery = \App\Models\StoreOrder::where('order_status', \App\Enum\OrderStatus::COMMITTED->value);
                    if (!$user->hasRole('admin')) {
                        $orderReceivingQuery->whereIn('store_branch_id', $assignedStoreIds);
                    }
                    $data['orderReceivingCount'] = $orderReceivingQuery->count();
                    if ($data['orderReceivingCount'] > 0) {
                        $data['orderReceivingDates'] = $orderReceivingQuery->select('order_date')
                            ->distinct()
                            ->orderBy('order_date')
                            ->limit(10)
                            ->pluck('order_date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                            ->values()
                            ->toArray();
                    }
                }

                if ($user->can('view mass order approval')) {
                    $suppliersForApproval = \App\Models\Supplier::where('is_forapproval_massorders', true)->pluck('id');
                    $data['massOrdersApprovalCount'] = \App\Models\StoreOrder::where('variant', 'mass regular')
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
                    $data['csMassCommitsCount'] = $csMassCommitsQuery->count();
                    if ($data['csMassCommitsCount'] > 0) {
                         $data['csMassCommitsDates'] = $csMassCommitsQuery->select('order_date')->distinct()->limit(10)->pluck('order_date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                            ->values()
                            ->toArray();
                    }
                }

                if ($user->can('edit cs dts mass commit')) {
                    $csDtsQuery = \App\Models\StoreOrder::where('variant', 'mass dts')
                        ->where('order_status', 'approved')
                        ->whereNotNull('batch_reference');
                    $data['csDtsMassCommitsCount'] = $csDtsQuery->distinct('batch_reference')->count('batch_reference');
                    if ($data['csDtsMassCommitsCount'] > 0) {
                        $data['csDtsMassCommitsBatches'] = $csDtsQuery->distinct('batch_reference')->limit(10)->pluck('batch_reference')->toArray();
                    }
                }

                if ($user->can('view interco approvals') && $assignedStoreIds->isNotEmpty()) {
                    $intercoApprovalQuery = \App\Models\StoreOrder::whereNotNull('interco_number')
                        ->where('variant', 'INTERCO')
                        ->whereIn('store_branch_id', $assignedStoreIds)
                        ->where('interco_status', 'open');
                    $data['intercoApprovalCount'] = $intercoApprovalQuery->count();
                    if ($data['intercoApprovalCount'] > 0) {
                        $data['intercoApprovalDates'] = $intercoApprovalQuery->select('order_date')->distinct()->limit(10)->pluck('order_date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                            ->values()
                            ->toArray();
                    }
                }

                if ($user->can('view store commits')) {
                    $storeCommitsQuery = \App\Models\StoreOrder::whereNotNull('interco_number')
                        ->where('interco_status', 'approved');
                    if (!$user->hasRole('admin')) {
                        $storeCommitsQuery->whereIn('sending_store_branch_id', $assignedStoreIds);
                    }
                    $data['storeCommitsCount'] = $storeCommitsQuery->count();
                    if ($data['storeCommitsCount'] > 0) {
                        $data['storeCommitsDates'] = $storeCommitsQuery->select('order_date')->distinct()->limit(10)->pluck('order_date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                            ->values()
                            ->toArray();
                    }
                }
                
                if ($user->can('view wastage approval level 1') && $assignedStoreIds->isNotEmpty()) {
                    $baseWastageLvl1Query = \App\Models\Wastage::whereIn('store_branch_id', $assignedStoreIds)
                        ->where('wastage_status', 'pending');
                    
                    $data['wastageLvl1Count'] = \Illuminate\Support\Facades\DB::table(
                        (clone $baseWastageLvl1Query)->select('wastage_no', 'store_branch_id')->distinct(), 
                        'sub'
                    )->count();
                    
                    if ($data['wastageLvl1Count'] > 0) {
                        $data['wastageLvl1Dates'] = (clone $baseWastageLvl1Query)
                            ->selectRaw('CONVERT(date, created_at) as date')
                            ->distinct()
                            ->orderBy('date')
                            ->limit(10)
                            ->pluck('date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                            ->toArray();
                    }
                }

                if ($user->can('view wastage approval level 2') && $assignedStoreIds->isNotEmpty()) {
                    $baseWastageLvl2Query = \App\Models\Wastage::whereIn('store_branch_id', $assignedStoreIds)
                        ->where('wastage_status', 'approved_lvl1');
                    
                    $data['wastageLvl2Count'] = \Illuminate\Support\Facades\DB::table(
                        (clone $baseWastageLvl2Query)->select('wastage_no', 'store_branch_id')->distinct(),
                        'sub'
                    )->count();
                    
                    if ($data['wastageLvl2Count'] > 0) {
                        $data['wastageLvl2Dates'] = (clone $baseWastageLvl2Query)
                            ->selectRaw('CONVERT(date, created_at) as date')
                            ->distinct()
                            ->orderBy('date')
                            ->limit(10)
                            ->pluck('date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                            ->toArray();
                    }
                }
                
                if (($user->can('view month end count approvals') || $user->can('approve month end count level 1')) && $assignedStoreIds->isNotEmpty()) {
                    $monthEndLvl1Query = \App\Models\MonthEndCountItem::whereIn('month_end_count_items.branch_id', $assignedStoreIds)
                        ->where('month_end_count_items.status', 'pending_level1_approval');
                    
                    $data['monthEndLvl1Count'] = \Illuminate\Support\Facades\DB::table(
                        (clone $monthEndLvl1Query)->select('month_end_schedule_id', 'branch_id')->distinct(),
                        'sub'
                    )->count();
                    
                    if ($data['monthEndLvl1Count'] > 0) {
                         $data['monthEndLvl1Dates'] = (clone $monthEndLvl1Query)
                            ->join('month_end_schedules as mes', 'month_end_count_items.month_end_schedule_id', '=', 'mes.id')
                            ->selectRaw('CONVERT(date, mes.calculated_date) as date')
                            ->distinct()
                            ->orderBy('date')
                            ->limit(10)
                            ->pluck('date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                            ->toArray();
                    }
                }

                if (($user->can('view month end count approvals level 2') || $user->can('approve month end count level 2')) && $assignedStoreIds->isNotEmpty()) {
                    $monthEndLvl2Query = \App\Models\MonthEndCountItem::whereIn('month_end_count_items.branch_id', $assignedStoreIds)
                        ->where('month_end_count_items.status', 'level1_approved');
                    
                    $data['monthEndLvl2Count'] = \Illuminate\Support\Facades\DB::table(
                        (clone $monthEndLvl2Query)->select('month_end_schedule_id', 'branch_id')->distinct(),
                        'sub'
                    )->count();
                    
                    if ($data['monthEndLvl2Count'] > 0) {
                         $data['monthEndLvl2Dates'] = (clone $monthEndLvl2Query)
                            ->join('month_end_schedules as mes', 'month_end_count_items.month_end_schedule_id', '=', 'mes.id')
                            ->selectRaw('CONVERT(date, mes.calculated_date) as date')
                            ->distinct()
                            ->orderBy('date')
                            ->limit(10)
                            ->pluck('date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('M d, Y'))
                            ->toArray();
                    }
                }

                return $data;
            });
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? $request->user()->load('roles', 'permissions') : null,
                'roles' => $request->user() ? $request->user()->getRoleNames() : [],
                'permissions' => $request->user() ? $request->user()->getAllPermissions()->pluck('name') : [],
                'is_admin' => $request->user() ? $request->user()->hasRole('admin') : false,
            ],
            'notifications' => $notifications,
            'flash' => [
                'message' => $request->session()->get('message'),
                'import_summary' => $request->session()->get('import_summary'),
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'skippedItems' => $request->session()->get('skippedItems'),
                'warning' => $request->session()->get('warning'),
                'skipped_import_rows' => $request->session()->get('skipped_import_rows'),
                'created_count' => $request->session()->get('created_count'),
                'skipped_stores' => $request->session()->get('skipped_stores'),
                'summary_messages' => $request->session()->get('summary_messages'),
                'summary_counts' => $request->session()->get('summary_counts'),
            ],
            'previous' => fn() => URL::previous(),
        ];
    }
}
