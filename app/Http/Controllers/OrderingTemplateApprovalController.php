<?php

namespace App\Http\Controllers;

use App\Http\Services\MassOrderApprovalLevelService;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrderingTemplateApprovalController extends Controller
{
    public function __construct(private MassOrderApprovalLevelService $approvalLevels)
    {
    }

    public function index()
    {
        $suppliers = Supplier::where('is_active', 1)
            ->get(['id', 'supplier_code', 'name', 'is_forapproval_massorders', 'mass_order_approval_levels'])
            ->map(fn (Supplier $supplier) => [
                ...$supplier->only(['id', 'supplier_code', 'name', 'is_forapproval_massorders']),
                'mass_order_approval_levels' => MassOrderApprovalLevelService::clamp($supplier->mass_order_approval_levels),
                // CPO, AO, EO, ALOC: always approved, so the switch stays on.
                'always_for_approval' => Supplier::isUnrestrictedTemplate($supplier->supplier_code),
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
        ]);

        if (Supplier::isUnrestrictedTemplate($supplier->supplier_code) && ! $request->boolean('is_forapproval_massorders')) {
            return redirect()->back()->withErrors(['error' => "{$supplier->supplier_code} orders always go to Mass Orders Approval, whose approval commits them, so this switch stays on."]);
        }

        // A new number of levels applies to orders nobody has approved yet; an order
        // already approved at a level keeps the levels it started with.
        $supplier->update($validated);

        return redirect()->back()->with('success', 'Supplier updated successfully.');
    }
}
