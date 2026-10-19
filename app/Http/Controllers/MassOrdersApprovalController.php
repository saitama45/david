<?php

namespace App\Http\Controllers;

use App\Http\Services\MassOrderApprovalLevelService;
use App\Models\StoreOrder;
use App\Models\StoreOrderApproval;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use App\Enum\OrderStatus;

class MassOrdersApprovalController extends Controller
{
    public function __construct(private MassOrderApprovalLevelService $approvalLevels)
    {
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $user->load('store_branches');
        $branchIds = $user->store_branches->pluck('id');

        $suppliersForApproval = Supplier::forMassOrderApproval()->pluck('id');

        $baseQuery = fn () => StoreOrder::where('variant', 'mass regular')
            ->whereIn('supplier_id', $suppliersForApproval)
            ->whereIn('store_branch_id', $branchIds);

        // One "For Level N" tab per level while any template approves in more than one.
        $levels = $this->approvalLevels->levelsInUse();
        $filter = $request->input('filter', $this->defaultFilter($user, $levels));

        $query = $baseQuery()->with(['supplier', 'store_branch']);

        if (preg_match('/^level_(\d+)$/', (string) $filter, $match)) {
            // Waiting for Level N = pending, with the N - 1 levels before it passed.
            $query->where('order_status', OrderStatus::PENDING->value)->where('approved_level', (int) $match[1] - 1);
        } elseif ($filter !== 'all') {
            $query->where('order_status', $filter);
        }

        $orders = $query->latest()->paginate(15)->withQueryString()
            ->through(function (StoreOrder $order) {
                // "Level 1 of 2" under a pending order's status.
                $order->setAttribute('approval_progress', strtolower((string) $order->order_status) === OrderStatus::PENDING->value
                    ? ['level' => $this->approvalLevels->decisionLevel($order), 'of' => $this->approvalLevels->requiredLevels($order)]
                    : null);

                return $order;
            });

        $counts = [
            'all' => $baseQuery()->count(),
            'pending' => $baseQuery()->where('order_status', OrderStatus::PENDING->value)->count(),
            'approved' => $baseQuery()->where('order_status', OrderStatus::APPROVED->value)->count(),
        ];

        if ($levels > 1) {
            $waiting = $baseQuery()->where('order_status', OrderStatus::PENDING->value)
                ->selectRaw('approved_level, COUNT(*) as total')
                ->groupBy('approved_level')
                ->pluck('total', 'approved_level');

            foreach (range(1, $levels) as $level) {
                $counts["level_{$level}"] = (int) ($waiting[$level - 1] ?? 0);
            }
        }

        return Inertia::render('MassOrdersApproval/Index', [
            'orders' => $orders,
            'counts' => $counts,
            'filters' => ['currentFilter' => $filter],
            'approvalLevels' => $levels,
        ]);
    }

    /**
     * Every pending order, except for an approver of later levels only, who starts on the
     * first level they decide.
     */
    private function defaultFilter(User $user, int $levels): string
    {
        $mine = $levels > 1 ? $this->approvalLevels->approvableLevels($user) : [];

        return $mine && ! in_array(1, $mine, true) ? 'level_'.min($mine) : 'pending';
    }

    public function show($id)
    {
        // A CPO order also carries its vendor and the invoice / receipt to check against.
        $order = StoreOrder::with('storeOrderItems.supplierItem', 'supplier', 'store_branch', 'cpoAttachments')->findOrFail($id);
        $user = Auth::user();
        $level = $this->approvalLevels->decisionLevel($order);
        $isFinal = $this->approvalLevels->isFinalLevel($order, $level);

        return Inertia::render('MassOrdersApproval/Show', [
            'order' => $order,
            // Why Approve and Reject are not offered on this order, or null while they are.
            // The page hides both on the same rule approve() and reject() enforce.
            'decisionProblem' => $this->decisionProblem($order),
            // CPO, AO, EO, ALOC: approving also commits the order and opens its receiving,
            // at the order's last approval level.
            'commitsOnApproval' => $isFinal && Supplier::isUnrestrictedTemplate($order->supplier?->supplier_code),
            // The level that decides the order next, and whether this user's roles may.
            'approval' => [
                'level' => $level,
                'levels' => $this->approvalLevels->requiredLevels($order),
                'isFinal' => $isFinal,
                'canApprove' => $user->can(MassOrderApprovalLevelService::approvePermission($level)),
                'canReject' => $user->can(MassOrderApprovalLevelService::rejectPermission($level)),
                'steps' => $this->approvalLevels->steps($order),
            ],
        ]);
    }

    /**
     * Why this order can no longer be approved or rejected, or null while it can.
     *
     * Only an order that is still pending, or approved with nothing committed or received
     * yet, may be decided. Approve used to run on any order: on a received one it put the
     * status back to approved / committed, and on a CPO order it also added a second set of
     * receiving rows that a delivery locked by Final Receive All could never receive.
     */
    private function decisionProblem(StoreOrder $order): ?string
    {
        $received = $order->receiving_finalized_at
            || $order->ordered_item_receive_dates()->whereIn('status', ['received', 'approved'])->exists();

        if ($received) {
            return "Order {$order->order_number} already has received items, so it can no longer be approved or rejected.";
        }

        $status = strtolower((string) $order->order_status);

        if (! in_array($status, [OrderStatus::PENDING->value, OrderStatus::APPROVED->value], true)) {
            return "Order {$order->order_number} is already ".str_replace('_', ' ', $status)
                .', so it can no longer be approved or rejected.';
        }

        return null;
    }

    /**
     * @throws ValidationException when the order can no longer be approved or rejected.
     */
    private function assertCanDecide(StoreOrder $order): void
    {
        if ($problem = $this->decisionProblem($order)) {
            throw ValidationException::withMessages(['error' => $problem]);
        }
    }

    /**
     * Each level has its own permission: the route lets in any level's approver, this
     * checks the level the order is waiting for.
     *
     * @throws ValidationException when the user's roles cannot decide at this level.
     */
    private function assertHoldsLevel(StoreOrder $order, int $level, string $permission, string $verb): void
    {
        if (! Auth::user()->can($permission)) {
            throw ValidationException::withMessages([
                'error' => "Order {$order->order_number} is waiting for its Level {$level} approval, which your role cannot {$verb}.",
            ]);
        }
    }

    public function approve(Request $request, $id)
    {
        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity_approved' => ['required', 'numeric', 'min:0'],
        ]);

        $message = DB::transaction(function () use ($id, $validated) {
            $order = StoreOrder::with('supplier')->lockForUpdate()->findOrFail($id);

            // Checked under the lock, so a store finalizing its delivery at the same moment
            // cannot slip in between this check and the approval.
            $this->assertCanDecide($order);

            $level = $this->approvalLevels->decisionLevel($order);
            $levels = $this->approvalLevels->requiredLevels($order);
            $isFinal = $this->approvalLevels->isFinalLevel($order, $level);
            $this->assertHoldsLevel($order, $level, MassOrderApprovalLevelService::approvePermission($level), 'give');

            // CPO, AO, EO and ALOC have no CS commit: their last level's approval commits them
            // and opens receiving.
            $commitsOnApproval = $isFinal && Supplier::isUnrestrictedTemplate($order->supplier?->supplier_code);
            $now = Carbon::now();

            // Fixed at the first approval: a template changed later leaves this order alone.
            $order->approval_levels = $levels;
            $order->approved_level = $level;

            // Below its last level the order stays pending, waiting for the next level.
            if ($isFinal) {
                $order->order_status = $commitsOnApproval
                    ? OrderStatus::COMMITTED->value
                    : OrderStatus::APPROVED->value;
                $order->approver_id = Auth::id();
                $order->approval_action_date = $now;
            }

            if ($commitsOnApproval) {
                $order->commiter_id = Auth::id();
                $order->commited_action_date = $now;
            }

            $order->save();

            foreach ($validated['items'] as $item) {
                $orderItem = $order->storeOrderItems()->find($item['id']);

                if (!$orderItem) {
                    continue;
                }

                $approvedQuantity = $item['quantity_approved'];
                $orderItem->quantity_approved = $approvedQuantity;

                // The last level's quantity is what the store receives: Inbound Orders reads the
                // committed quantity and needs no CS commit. No CS commit can have happened yet
                // (it moves the order past approved, which decisionProblem() refuses), so this
                // only replaces the ordered quantity copied when the order was placed.
                if ($isFinal) {
                    $orderItem->quantity_commited = $approvedQuantity;
                }

                if ($commitsOnApproval) {
                    $orderItem->committed_by = Auth::id();
                    $orderItem->committed_date = $now;
                }

                $orderItem->save();

                if ($isFinal && ! $commitsOnApproval) {
                    // A receiving row Inbound Orders opened before a re-approval, nobody filled in.
                    $orderItem->ordered_item_receive_dates()->where('status', 'pending')
                        ->update(['quantity_received' => $approvedQuantity]);
                }

                if ($commitsOnApproval) {
                    $orderItem->ordered_item_receive_dates()->updateOrCreate(
                        ['status' => 'pending'],
                        [
                            'received_by_user_id' => Auth::id(),
                            'quantity_received' => $approvedQuantity,
                            'received_date' => null,
                            'remarks' => null,
                        ]
                    );
                }
            }

            $order->approvalSteps()->create([
                'level' => $level,
                'action' => StoreOrderApproval::APPROVED,
                'user_id' => Auth::id(),
                'acted_at' => $now,
            ]);

            if (! $isFinal) {
                return "Level {$level} approval saved. Order {$order->order_number} now waits for Level ".($level + 1)." of {$levels}.";
            }

            return $commitsOnApproval
                ? 'Order approved and committed successfully.'
                : 'Order approved successfully.';
        });

        return redirect()->route('mass-orders-approval.index')->with('success', $message);
    }

    public function reject(Request $request, $id)
    {
        DB::transaction(function () use ($id) {
            $order = StoreOrder::with('supplier')->lockForUpdate()->findOrFail($id);

            $this->assertCanDecide($order);

            // Any level may reject; the order is then rejected for good.
            $level = $this->approvalLevels->decisionLevel($order);
            $this->assertHoldsLevel($order, $level, MassOrderApprovalLevelService::rejectPermission($level), 'reject');

            $order->order_status = OrderStatus::REJECTED->value;
            $order->save();

            $order->approvalSteps()->create([
                'level' => $level,
                'action' => StoreOrderApproval::REJECTED,
                'user_id' => Auth::id(),
                'acted_at' => Carbon::now(),
            ]);
        });

        return redirect()->route('mass-orders-approval.index')->with('success', 'Order rejected successfully.');
    }
}
