<?php

namespace App\Http\Services;

use App\Models\Entity;
use App\Models\User;
use App\Support\EntityContext;
use Illuminate\Support\Collection;

class WorkflowGuidanceService
{
    private array $catalogs = [];

    public function catalog(User $viewer): array
    {
        $entityId = app(EntityContext::class)->id();
        $cacheKey = $viewer->id.':'.$entityId;
        if (isset($this->catalogs[$cacheKey])) {
            return $this->catalogs[$cacheKey];
        }

        $branchIds = $viewer->store_branches()->pluck('store_branches.id');
        $entityRoleIds = $entityId
            ? Entity::findOrFail($entityId)->roles()->pluck('roles.id')
            : collect();
        // Only expose colleagues in the viewer's branches and active entity.
        $people = User::where('is_active', true)
            ->whereHas('store_branches', fn ($q) => $q->whereIn('store_branches.id', $branchIds))
            ->when($entityId, fn ($q) => $q->whereHas('roles', fn ($r) => $r->whereIn('roles.id', $entityRoleIds)))
            ->with(['roles.permissions', 'permissions', 'store_branches', 'suppliers'])
            ->get();

        $catalog = [];
        $definitions = array_replace_recursive(config('workflow_guidance'), app(WastageApprovalSettingsService::class)->guidanceDefinitions());
        foreach ($definitions as $key => $definition) {
            $required = array_unique([$definition['view'], ...$definition['permissions']]);
            $candidates = $people->filter(fn (User $person) => $person->hasAllPermissions($required));
            $catalog[$key] = $definition + [
                'key' => $key,
                'url' => $viewer->can($definition['view']) ? route($definition['route']) : null,
                'action_url' => ! empty($definition['entry_route']) && $viewer->hasAllPermissions($required) ? route($definition['entry_route']) : null,
                'can_act' => $viewer->hasAllPermissions($required),
                'people' => $candidates->map(fn (User $person) => [
                    'id' => $person->id,
                    'roles' => $person->roles->filter(fn ($role) => ! in_array(strtolower(trim($role->name)), ['admin', 'ct admin'], true)
                        && $role->permissions->pluck('name')->intersect($definition['permissions'])->isNotEmpty())->pluck('name')->unique()->sort()->values()->all(),
                    'branches' => $person->store_branches->pluck('id')->intersect($branchIds)->values()->all(),
                    'suppliers' => $person->suppliers->pluck('id')->all(),
                ])->values()->all(),
            ];
        }

        return $this->catalogs[$cacheKey] = $catalog;
    }

    public function task(User $viewer, string $key, array $context): array
    {
        $definition = $this->catalog($viewer)[$key];
        $people = collect($definition['people'])->filter(function ($person) use ($context, $key) {
            if (! in_array($context['branch_id'], $person['branches'])) {
                return false;
            }

            return ! in_array($key, ['order', 'fg_commit', 'other_commit'])
                || (! empty($context['supplier_id']) && in_array($context['supplier_id'], $person['suppliers']));
        })->values();
        $mine = $definition['can_act'] && $people->contains('id', $viewer->id);

        $task = $context + [
            'action_key' => $key,
            'action' => $definition['label'],
            'rule' => $definition['rule'],
            'people' => $people->all(),
            'roles' => $people->pluck('roles')->flatten()->unique()->sort()->values()->all(),
            'ownership' => $people->isEmpty() ? 'unassigned' : ($mine ? 'mine' : 'waiting'),
            'can_act' => $mine,
            'url' => $definition['url'],
            'deadline' => null,
            'waiting_since' => null,
            'assignment_url' => $viewer->can('view users') ? route('users.index') : null,
        ];
        $deadline = empty($task['deadline']) ? null : \Carbon\Carbon::parse($task['deadline'], 'Asia/Manila');
        $now = \Carbon\Carbon::now('Asia/Manila');
        $task['urgency'] = $deadline?->lt($now) ? 'overdue' : ($deadline && $deadline->lte($now->copy()->addDay()) ? 'due_soon' : 'open');
        if (in_array($key, ['order', 'dts_order']) && $task['urgency'] === 'overdue') {
            $task['blocked'] = true;
            $task['action'] = 'Review missed ordering cutoff';
            $task['rule'] = 'The ordering cutoff has passed for this delivery date. Review the ordering calendar and ask the responsible ordering team to resolve the missed requirement. Use only delivery dates the ordering screen currently permits.';
        }

        return $task;
    }

    public function reportActions(User $viewer, string $tab, array $row): array
    {
        $keys = match ($tab) {
            'ordering_timeliness' => empty($row['order_exists']) && ($row['plotted'] ?? '') === 'No' ? [($row['ordering_template'] ?? '') === 'ICE CREAM' ? 'dts_order' : 'order'] : [],
            'commit_order_timeliness' => ($row['order_status'] ?? '') === 'pending'
                ? ['order_approval'] : (in_array($row['order_status'] ?? '', ['approved', 'partial_committed']) ? ($row['pending_commit_keys'] ?? []) : []),
            'delivery_logging_timeliness' => ($row['on_time'] ?? '') !== 'NA' && empty($row['david_logging_date'])
                ? (! empty($row['pending_receipt']) ? ['receipt_approval'] : (in_array($row['order_status'] ?? '', ['committed', 'incomplete']) ? ['receive'] : [])) : [],
            'sales_upload_timeliness' => ($row['sales_report_uploaded'] ?? '') === 'No' ? ['sales'] : [],
            'wastage_upload_timeliness' => match ($row['workflow_status'] ?? '') {
                'pending' => ['wastage_1'], 'approved_lvl1' => ['wastage_2'], default => [],
            },
            default => [],
        };

        return array_map(function ($key) use ($viewer, $row) {
            $task = $this->task($viewer, $key, [
                'id' => $row['row_key'].':'.$key,
                'branch_id' => (int) $row['store_branch_id'],
                'branch' => $row['store'],
                'supplier_id' => $row['supplier_id'] ?? null,
                'reference' => $row['wastage_no'] ?? $row['ordering_template'] ?? '',
                'date' => $row['david_delivery_date'] ?? $row['delivery_date'] ?? $row['sap_dr_date'] ?? $row['date_of_sales'] ?? $row['date_of_wastage'] ?? null,
                'deadline' => in_array($key, ['order_approval', 'receipt_approval', 'wastage_1', 'wastage_2']) ? null : ($row['action_deadline'] ?? null),
            ]);
            if ($task['url']) {
                $route = $this->catalog($viewer)[$key]['route'];
                $params = match ($key) {
                    'fg_commit', 'other_commit' => ['order_date' => $task['date'], 'supplier_id' => $row['supplier_code'] ?? 'all'],
                    'receive' => ['store_ids' => [$task['branch_id']], 'delivery_date_from' => $task['date'], 'delivery_date_to' => $task['date']],
                    'sales' => ['from' => $task['date'], 'to' => $task['date'], 'branchId' => $task['branch_id']],
                    default => [],
                };
                if ($key === 'sales') {
                    $route = 'store-transactions.main-index';
                }
                if (in_array($key, ['wastage_1', 'wastage_2']) && ! empty($row['wastage_id'])) {
                    $route = str_replace('.index', '.show', $route);
                    $params = [$row['wastage_id']];
                }
                if ($key === 'order_approval' && ! empty($row['order_id'])) {
                    $route = 'mass-orders-approval.show';
                    $params = [$row['order_id']];
                }
                $task['url'] = route($route, $params);
            }

            return $task;
        }, $keys);
    }

    public function reportMetrics(string $tab, Collection $rows): array
    {
        $observations = $rows->flatMap(fn ($row) => match ($tab) {
            'ordering_timeliness' => ($row['plotted'] ?? '') === 'No order' ? [] : [[(bool) ($row['order_exists'] ?? false), $row['plotted'] === 'Yes']],
            'commit_order_timeliness' => collect(['fg', 'traded'])->filter(fn ($category) => $row[$category.'_on_time'] !== 'NA')->map(fn ($category) => [! in_array($row[$category.'_commit_date_display'], ['No Commit', 'NA', '']), $row[$category.'_on_time'] === 'Yes'])->all(),
            'delivery_logging_timeliness' => $row['on_time'] === 'NA' ? [] : [[! empty($row['david_logging_date']), $row['on_time'] === 'Yes']],
            'sales_upload_timeliness' => [[$row['sales_report_uploaded'] === 'Yes', $row['sales_report_uploaded_on_time'] === 'Yes']],
            'wastage_upload_timeliness' => [[true, $row['wastage_report_uploaded'] === 'Yes']],
            default => [],
        });
        $total = $observations->count();
        $completed = $observations->filter(fn ($observation) => $observation[0])->count();

        return [
            'applicable' => $total,
            'completed' => $completed,
            'outstanding' => $total - $completed,
            'completion_rate' => $total ? round($completed / $total * 100, 1) : null,
            'on_time_rate' => $total ? round($observations->filter(fn ($observation) => $observation[1])->count() / $total * 100, 1) : null,
            'scope' => 'Selected report period and filters; each applicable row/category has equal weight. Existing adoption totals retain their original weighting.',
            'limitation' => $tab === 'wastage_upload_timeliness' ? 'Wastage currently uses the record creation time for both occurrence and upload. Missing wastage and true upload delays cannot be inferred from these records.' : null,
        ];
    }
}
