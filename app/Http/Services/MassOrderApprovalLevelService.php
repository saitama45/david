<?php

namespace App\Http\Services;

use App\Enum\OrderStatus;
use App\Models\StoreOrder;
use App\Models\StoreOrderApproval;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Mass Orders Approval in levels.
 *
 * Each ordering template sets how many levels approve its mass orders, 1 to MAX_LEVELS
 * (Ordering Template Approval, suppliers.mass_order_approval_levels). An order stays pending
 * while it waits for a level and becomes approved (or committed, for CPO / AO / EO / ALOC)
 * only when its last level approves. store_orders.approved_level counts the levels passed;
 * store_orders.approval_levels fixes the number of levels at the order's first approval, so a
 * template changed later only affects orders nobody has approved yet.
 *
 * Every level has its own approve and reject permission. Level 1 keeps the permissions of the
 * single-level flow ("approve mass order", "reject mass order").
 */
class MassOrderApprovalLevelService
{
    public const MAX_LEVELS = 3;

    public static function approvePermission(int $level): string
    {
        return $level <= 1 ? 'approve mass order' : "approve mass order level {$level}";
    }

    public static function rejectPermission(int $level): string
    {
        return $level <= 1 ? 'reject mass order' : "reject mass order level {$level}";
    }

    /** @return list<string> every level's approve permission, for the route guard */
    public static function approvePermissions(): array
    {
        return array_map(fn (int $level) => self::approvePermission($level), range(1, self::MAX_LEVELS));
    }

    /** @return list<string> every level's reject permission, for the route guard */
    public static function rejectPermissions(): array
    {
        return array_map(fn (int $level) => self::rejectPermission($level), range(1, self::MAX_LEVELS));
    }

    public static function clamp(?int $levels): int
    {
        return max(1, min(self::MAX_LEVELS, (int) ($levels ?: 1)));
    }

    private function isPending(StoreOrder $order): bool
    {
        return strtolower((string) $order->order_status) === OrderStatus::PENDING->value;
    }

    /**
     * How many levels approve this order: the number fixed at its first approval; before
     * that, its template's setting today. An order decided before levels existed had one.
     */
    public function requiredLevels(StoreOrder $order): int
    {
        if ($order->approval_levels) {
            return self::clamp($order->approval_levels);
        }

        return $this->isPending($order)
            ? self::clamp($order->supplier?->mass_order_approval_levels)
            : 1;
    }

    /**
     * The level that decides this order next. A pending order waits for the level after the
     * last one it passed. An order already approved (decided again while nothing is committed)
     * is decided at its last level.
     */
    public function decisionLevel(StoreOrder $order): int
    {
        $levels = $this->requiredLevels($order);

        return $this->isPending($order)
            ? min((int) $order->approved_level + 1, $levels)
            : $levels;
    }

    /** Whether approving at $level finishes the approval (the order becomes approved / committed). */
    public function isFinalLevel(StoreOrder $order, int $level): bool
    {
        return ! $this->isPending($order) || $level >= $this->requiredLevels($order);
    }

    /** @return list<int> the levels this user may approve */
    public function approvableLevels(User $user): array
    {
        return array_values(array_filter(
            range(1, self::MAX_LEVELS),
            fn (int $level) => $user->can(self::approvePermission($level))
        ));
    }

    /**
     * The most levels any order on Mass Orders Approval can have: one "For Level N" tab each.
     * Counts orders already part-way through, so a template turned down does not hide them.
     */
    public function levelsInUse(): int
    {
        $templates = (int) Supplier::forMassOrderApproval()->max('mass_order_approval_levels');
        $inFlight = (int) StoreOrder::where('variant', 'mass regular')
            ->where('order_status', OrderStatus::PENDING->value)
            ->max('approval_levels');

        return self::clamp(max($templates, $inFlight));
    }

    /**
     * Each level's permissions and the roles that hold them, so Ordering Template Approval can
     * show who approves a level and warn when nobody can.
     *
     * @return list<array{level: int, approve_permission: string, reject_permission: string, approve_roles: Collection, reject_roles: Collection}>
     */
    public function levelRoles(): array
    {
        $rolesWith = fn (string $permission) => Role::whereHas('permissions', fn ($q) => $q->where('name', $permission))
            ->orderBy('name')
            ->pluck('name');

        return array_map(fn (int $level) => [
            'level' => $level,
            'approve_permission' => self::approvePermission($level),
            'reject_permission' => self::rejectPermission($level),
            'approve_roles' => $rolesWith(self::approvePermission($level)),
            'reject_roles' => $rolesWith(self::rejectPermission($level)),
        ], range(1, self::MAX_LEVELS));
    }

    /**
     * The order's approval levels for the Show page, one entry per level: approved / rejected
     * (with who and when, from its latest decision), waiting (the level that decides next) or
     * upcoming.
     *
     * @return list<array{level: int, state: string, by: ?string, at: ?string}>
     */
    public function steps(StoreOrder $order): array
    {
        $levels = $this->requiredLevels($order);
        $decisions = $order->approvalSteps()->with('user:id,first_name,last_name')->get()->keyBy('level');
        $pending = $this->isPending($order);
        $rejected = strtolower((string) $order->order_status) === OrderStatus::REJECTED->value;

        return array_map(function (int $level) use ($order, $decisions, $pending, $rejected) {
            $decision = $decisions->get($level);

            if ($decision) {
                return [
                    'level' => $level,
                    'state' => $decision->action,
                    'by' => $this->userName($decision->user),
                    'at' => $decision->acted_at?->format('Y-m-d H:i:s'),
                ];
            }

            // Decided before levels were recorded: the order's own approver stands for Level 1.
            if (! $pending && ! $rejected && $level === 1 && $order->approver_id && $decisions->isEmpty()) {
                return [
                    'level' => 1,
                    'state' => StoreOrderApproval::APPROVED,
                    'by' => $this->userName($order->approver),
                    'at' => $order->getRawOriginal('approval_action_date'),
                ];
            }

            return [
                'level' => $level,
                'state' => $pending && $level === (int) $order->approved_level + 1 ? 'waiting' : 'upcoming',
                'by' => null,
                'at' => null,
            ];
        }, range(1, $levels));
    }

    private function userName(?User $user): ?string
    {
        return $user ? trim("{$user->first_name} {$user->last_name}") : null;
    }
}
