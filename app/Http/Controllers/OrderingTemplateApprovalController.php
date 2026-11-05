<?php

namespace App\Http\Controllers;

use App\Enum\OrderStatus;
use App\Http\Services\MassOrderApprovalLevelService;
use App\Models\StoreOrder;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;

class OrderingTemplateApprovalController extends Controller
{
    public function __construct(private MassOrderApprovalLevelService $approvalLevels)
    {
    }

    public function index()
    {
        $suppliers = Supplier::where('is_active', 1)
            ->get(['id', 'supplier_code', 'name', 'is_forapproval_massorders', 'mass_order_approval_levels', 'cs_commit_required_from'])
            ->map(fn (Supplier $supplier) => [
                ...$supplier->only(['id', 'supplier_code', 'name', 'is_forapproval_massorders']),
                'mass_order_approval_levels' => MassOrderApprovalLevelService::clamp($supplier->mass_order_approval_levels),
                // CPO: always approved, so the switch stays on.
                'always_for_approval' => Supplier::isAlwaysForApproval($supplier->supplier_code),
                'has_cs_commit' => Supplier::hasCsCommit($supplier->supplier_code),
                'cs_commit_required' => $supplier->cs_commit_required_from !== null,
                'cs_commit_required_since' => $supplier->cs_commit_required_from?->format('M j, Y g:i A'),
            ]);

        return Inertia::render('OrderingTemplateApproval/Index', [
            'suppliers' => $suppliers,
            'maxLevels' => MassOrderApprovalLevelService::MAX_LEVELS,
            // Each level's permissions and the roles holding them.
            'levelRoles' => $this->approvalLevels->levelRoles(),
        ]);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $validated = $request->validate([
            'is_forapproval_massorders' => 'required|boolean',
            'mass_order_approval_levels' => 'sometimes|required|integer|between:1,'.MassOrderApprovalLevelService::MAX_LEVELS,
            'cs_commit_required' => 'sometimes|required|boolean',
        ]);

        $changes = Arr::except($validated, 'cs_commit_required');

        if ($request->has('cs_commit_required')) {
            $required = $request->boolean('cs_commit_required');

            if ($required && ! Supplier::hasCsCommit($supplier->supplier_code)) {
                return redirect()->back()->withErrors(['error' => "{$supplier->supplier_code} orders have no CS commit, so they cannot wait for one."]);
            }

            // Only a real change moves the moment: the page sends this with every save, and
            // orders placed since it was turned on must keep waiting when the levels change.
            if ($required !== ($supplier->cs_commit_required_from !== null)) {
                $changes['cs_commit_required_from'] = $required ? now() : null;
            }
        }

        $switchingOff = $supplier->is_forapproval_massorders && ! $request->boolean('is_forapproval_massorders');

        if ($switchingOff && Supplier::isAlwaysForApproval($supplier->supplier_code)) {
            return redirect()->back()->withErrors(['error' => "{$supplier->supplier_code} orders always go to Mass Orders Approval, whose approval commits them, so this switch stays on."]);
        }

        // Switched off, the template leaves Mass Orders Approval, and its orders still waiting
        // there would be left pending with nowhere to approve them.
        $waiting = $switchingOff
            ? StoreOrder::where('supplier_id', $supplier->id)->where('variant', 'mass regular')->where('order_status', OrderStatus::PENDING->value)->count()
            : 0;

        if ($waiting > 0) {
            return redirect()->back()->withErrors(['error' => "{$supplier->supplier_code} has {$waiting} mass order(s) waiting in Mass Orders Approval. Approve or reject them first, then switch its approval off."]);
        }

        // A new number of levels applies to orders nobody has approved yet; an order
        // already approved at a level keeps the levels it started with.
        $supplier->update($changes);

        return redirect()->back()->with('success', 'Supplier updated successfully.');
    }
}
