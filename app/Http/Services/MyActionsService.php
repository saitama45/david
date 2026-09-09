<?php

namespace App\Http\Services;

use App\Models\MonthEndCountItem;
use App\Models\MonthEndCountReopen;
use App\Models\MonthEndSchedule;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\Wastage;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MyActionsService
{
    public function __construct(private WorkflowGuidanceService $guidance, private AdoptionRateTrackingService $adoption) {}

    public function get(User $user, array $filters): array
    {
        $branches = $user->store_branches()->where('is_active', true)->get()->keyBy('id');
        $branchIds = $branches->keys();
        $tasks = collect();
        $catalog = $this->guidance->catalog($user);
        $canView = fn (array $keys) => collect($keys)->contains(fn ($key) => $user->can($catalog[$key]['view']));

        // Open work is not restricted to the current month: old handoffs must remain visible.
        if ($canView(['order', 'dts_order', 'order_approval', 'fg_commit', 'other_commit', 'dts_commit', 'receive', 'receipt_approval', 'interco', 'interco_approval', 'interco_commit', 'interco_receive'])) {
            StoreOrder::with(['supplier', 'store_branch', 'sendingStore', 'store_order_items'])
                ->where(fn ($q) => $q->whereIn('store_branch_id', $branchIds)->orWhereIn('sending_store_branch_id', $branchIds))
                ->where(fn ($q) => $q->whereIn('order_status', ['pending', 'approved', 'partial_committed', 'committed', 'incomplete'])
                    ->orWhereIn('interco_status', ['open', 'approved', 'committed', 'in_transit'])
                    ->orWhereHas('ordered_item_receive_dates', fn ($r) => $r->where('status', 'pending')))
                ->withCount(['ordered_item_receive_dates as pending_receipt' => fn ($q) => $q->where('status', 'pending')])
                ->withMin(['ordered_item_receive_dates as receipt_submitted_at' => fn ($q) => $q->where('status', 'pending')], 'created_at')
                ->orderBy('id')->chunkById(200, function ($orders) use ($user, $branchIds, $tasks, $canView) {
                    $pendingCommits = $this->adoption->pendingCommitKeys($orders);
                    foreach ($orders as $order) {
                        $interco = $order->isInterco();
                        if ($interco && ! $canView(['interco', 'interco_approval', 'interco_commit', 'interco_receive'])) {
                            continue;
                        }
                        if (! $interco && ! $canView(['order', 'dts_order', 'order_approval', 'fg_commit', 'other_commit', 'dts_commit', 'receive', 'receipt_approval'])) {
                            continue;
                        }
                        $status = $interco ? $order->interco_status?->value : strtolower($order->order_status);
                        $keys = $interco ? match ($status) {
                            'open' => ['interco_approval'], 'approved' => ['interco_commit'],
                            'committed', 'in_transit' => ['interco_receive'], default => [],
                        } : match ($status) {
                            'pending' => $order->variant === 'mass regular' && $order->supplier?->is_forapproval_massorders ? ['order_approval'] : [],
                            'approved', 'partial_committed' => $order->variant === 'mass dts' ? ['dts_commit'] : ($order->variant === 'mass regular' ? ($pendingCommits[$order->id] ?? []) : []),
                            'committed', 'incomplete' => $order->pending_receipt ? ['receipt_approval'] : ['receive'], default => [],
                        };
                        if (! $interco && $order->pending_receipt) {
                            $keys = ['receipt_approval'];
                        }
                        foreach ($keys as $key) {
                            $branchId = $key === 'interco_commit' ? $order->sending_store_branch_id : $order->store_branch_id;
                            // Do not expose a counterpart branch's people or tasks without branch access.
                            if (! $branchIds->contains($branchId)) {
                                continue;
                            }
                            $since = in_array($key, ['fg_commit', 'other_commit', 'dts_commit', 'interco_commit'])
                                ? ($order->approval_action_date ?? $order->created_at)
                                : ($order->commited_action_date ?? $order->created_at);
                            if ($key === 'receipt_approval') {
                                $since = $order->receipt_submitted_at ?? $since;
                            }
                            $context = [
                                'id' => 'order:'.$order->id.':'.$key,
                                'record_id' => $order->id,
                                'branch_id' => (int) $branchId,
                                'branch' => $key === 'interco_commit' ? $order->sendingStore?->name : $order->store_branch?->name,
                                'supplier_id' => $order->supplier_id,
                                'reference' => $order->interco_number ?: $order->order_number,
                                'date' => substr((string) $order->order_date, 0, 10),
                                'waiting_since' => $since ? Carbon::parse($since)->toIso8601String() : null,
                                'deadline' => in_array($key, ['fg_commit', 'other_commit', 'dts_commit'])
                                    ? Carbon::parse($order->order_date)->subDay()->endOfDay()->format('Y-m-d H:i:s')
                                    : ($key === 'receive' && $order->supplier?->supplier_code !== 'CPO' ? Carbon::parse($order->order_date)->endOfDay()->format('Y-m-d H:i:s') : null),
                            ];
                            $task = $this->guidance->task($user, $key, $context);
                            $task['url'] = $this->orderUrl($user, $key, $order) ?? $task['url'];
                            $tasks->push($task);
                        }
                    }
                });
        }

        if ($canView(['wastage', 'wastage_1', 'wastage_2'])) {
            $groups = Wastage::whereIn('store_branch_id', $branchIds)->whereIn('wastage_status', ['pending', 'approved_lvl1'])
                ->selectRaw('MIN(id) as id, wastage_no, store_branch_id, wastage_status, MIN(created_at) as started_at, MIN(approved_level1_date) as level1_at')
                ->groupBy('wastage_no', 'store_branch_id', 'wastage_status')->get();
            foreach ($groups as $record) {
                $key = $record->wastage_status->value === 'pending' ? 'wastage_1' : 'wastage_2';
                $task = $this->guidance->task($user, $key, [
                    'id' => 'wastage:'.$record->id.':'.$key, 'branch_id' => (int) $record->store_branch_id,
                    'branch' => $branches[$record->store_branch_id]->name, 'reference' => $record->wastage_no,
                    'waiting_since' => Carbon::parse($record->level1_at ?? $record->started_at)->toIso8601String(),
                ]);
                if ($task['url']) {
                    $task['url'] = route(str_replace('.index', '.show', $catalog[$key]['route']), $record->id);
                }
                $tasks->push($task);
            }
        }

        if ($canView(['mec', 'mec_1', 'mec_2'])) {
            $groups = MonthEndCountItem::whereIn('branch_id', $branchIds)
                ->whereIn('status', ['uploaded', 'pending_level1_approval', 'level1_approved'])
                ->selectRaw('month_end_schedule_id, branch_id, status, MIN(created_at) as started_at, MIN(level1_approved_at) as level1_at')
                ->groupBy('month_end_schedule_id', 'branch_id', 'status')->get();
            foreach ($groups as $record) {
                $key = match ($record->status) {
                    'uploaded' => 'mec_review', 'pending_level1_approval' => 'mec_1', default => 'mec_2'
                };
                $task = $this->guidance->task($user, $key, [
                    'id' => 'mec:'.$record->month_end_schedule_id.':'.$record->branch_id.':'.$key,
                    'branch_id' => (int) $record->branch_id, 'branch' => $branches[$record->branch_id]->name,
                    'reference' => 'Month end count #'.$record->month_end_schedule_id,
                    'waiting_since' => Carbon::parse($record->level1_at ?? $record->started_at)->toIso8601String(),
                ]);
                if ($task['url'] && ($key !== 'mec_review' || $user->can('view month end count transaction'))) {
                    $task['url'] = route($key === 'mec_review' ? 'month-end-count.review' : str_replace('.index', '.show', $catalog[$key]['route']), [$record->month_end_schedule_id, $record->branch_id]);
                }
                if ($key === 'mec_review') {
                    $task['action'] = 'Review uploaded count and submit for approval';
                }
                $tasks->push($task);
            }
            $this->missingCounts($user, $branches, $tasks);
        }

        // Missing submissions use the same schedules and exclusions as the adoption report.
        foreach (['ordering_timeliness' => ['order', 'dts_order'], 'sales_upload_timeliness' => ['sales']] as $tab => $keys) {
            if (! $canView($keys) || $branchIds->isEmpty()) {
                continue;
            }
            $data = $this->adoption->getReportData($filters + ['tab' => $tab, 'store_ids' => $branchIds->all()], $user, false);
            foreach ($data['rows'] as $row) {
                foreach ($this->guidance->reportActions($user, $tab, $row) as $task) {
                    $tasks->push($task);
                }
            }
        }

        $now = Carbon::now('Asia/Manila');
        $tasks = $tasks->map(function ($task) use ($now) {
            $deadline = empty($task['deadline']) ? null : Carbon::parse($task['deadline'], 'Asia/Manila');
            $task['urgency'] = $deadline?->lt($now) ? 'overdue' : ($deadline && $deadline->lte($now->copy()->addDay()) ? 'due_soon' : 'open');
            $task['waiting_hours'] = empty($task['waiting_since']) ? null : round(max(0, Carbon::parse($task['waiting_since'])->diffInHours($now, false)), 1);

            return $task;
        })->sortBy(fn ($task) => [$task['urgency'] === 'overdue' ? 0 : 1, $task['deadline'] ?? '9999', $task['waiting_since'] ?? '9999'])->values();

        return [
            'tasks' => $tasks,
            'summary' => [
                'mine' => $tasks->where('ownership', 'mine')->count(), 'waiting' => $tasks->where('ownership', 'waiting')->count(),
                'unassigned' => $tasks->where('ownership', 'unassigned')->count(), 'overdue' => $tasks->where('urgency', 'overdue')->count(),
                'handoffs' => $tasks->whereNotNull('waiting_hours')->groupBy('action_key')->map(fn ($items) => [
                    'action' => $items->first()['action'], 'count' => $items->count(),
                    'average_hours' => round($items->avg('waiting_hours'), 1), 'oldest_hours' => $items->max('waiting_hours'),
                ])->values(),
            ],
        ];
    }

    private function orderUrl(User $user, string $key, StoreOrder $order): ?string
    {
        $route = match ($key) {
            'order_approval' => ['mass-orders-approval.show', 'view mass order approval'],
            'receive' => ['orders-receiving.show', 'view approved order'],
            'interco_approval' => ['interco-approval.show', 'view interco approvals'],
            'interco_commit' => ['store-commits.show', 'view store commits'],
            'interco_receive' => ['interco-receiving.show', 'view interco receiving'], default => null,
        };
        if ($route && $user->can($route[1])) {
            // orders-receiving.show resolves by order_number, interco-receiving.show by interco_number,
            // the remaining show routes by primary key.
            $parameter = match ($key) {
                'receive' => $order->order_number,
                'interco_receive' => $order->interco_number,
                default => $order->id,
            };

            return $parameter ? route($route[0], $parameter) : null;
        }
        if (in_array($key, ['fg_commit', 'other_commit']) && $user->can('view cs mass commits')) {
            return route('cs-mass-commits.index', ['order_date' => substr($order->order_date, 0, 10), 'supplier_id' => $order->supplier?->supplier_code ?? 'all']);
        }
        if ($key === 'dts_commit' && $order->batch_reference && $user->hasAllPermissions(['view cs dts mass commit', 'edit cs dts mass commit'])) {
            return route('cs-dts-mass-commits.edit', $order->batch_reference);
        }

        return null;
    }

    private function missingCounts(User $user, Collection $branches, Collection $tasks): void
    {
        $settingsService = app(MonthEndCountSettingsService::class);
        $settings = $settingsService->current();
        $now = \Illuminate\Support\Carbon::now('Asia/Manila');
        $latestId = (int) MonthEndSchedule::where('calculated_date', '<=', $now->toDateString())->orderByDesc('calculated_date')->value('id');
        $scheduleIds = MonthEndCountReopen::whereIn('branch_id', $branches->keys())->active($now)->pluck('month_end_schedule_id');
        if ($latestId) {
            $scheduleIds->push($latestId);
        }
        foreach (MonthEndSchedule::whereIn('id', $scheduleIds->unique())->get() as $schedule) {
            $uploaded = MonthEndCountItem::where('month_end_schedule_id', $schedule->id)->whereIn('branch_id', $branches->keys())
                ->where('status', '!=', 'rejected')->pluck('branch_id');
            $reopens = MonthEndCountReopen::where('month_end_schedule_id', $schedule->id)->whereIn('branch_id', $branches->keys())->active($now)->get()->keyBy('branch_id');
            $date = \Illuminate\Support\Carbon::parse($schedule->calculated_date, 'Asia/Manila');
            $opens = $settingsService->uploadStart($date, $settings);
            foreach ($branches->except($uploaded->all()) as $branch) {
                $reopen = $reopens->get($branch->id);
                if ($schedule->id !== $latestId && ! $reopen) {
                    continue;
                }
                if (! $reopen && $now->lt($opens)) {
                    continue;
                }
                $deadline = $reopen ? $reopen->reopened_until->format('Y-m-d H:i:s') : $settingsService->uploadCutoff($date, $settings)?->format('Y-m-d H:i:s');
                $closed = $deadline && $now->gt(\Illuminate\Support\Carbon::parse($deadline, 'Asia/Manila'));
                $task = $this->guidance->task($user, 'mec', [
                    'id' => 'mec-missing:'.$schedule->id.':'.$branch->id, 'branch_id' => $branch->id,
                    'branch' => $branch->name, 'reference' => 'Month end count '.$date->format('M Y'), 'deadline' => $deadline,
                ]);
                if ($closed) {
                    $task['action'] = 'Request reopening of the count upload window';
                    $task['rule'] = 'Contact tasservices@tablegroup.com.ph with the branch and count period to request reopening. Upload is unavailable until support reopens the branch window.';
                    $task['blocked'] = true;
                }
                $tasks->push($task);
            }
        }
    }
}
