<?php

namespace App\Http\Controllers;

use App\Models\DTSDeliverySchedule;
use App\Models\StoreBranch;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DSPDeliveryScheduleController extends Controller
{
    public function show($id)
    {
        $supplier = Supplier::findOrFail($id);
        $user = Auth::user();

        $storeBranches = $user->store_branches()
            ->where('is_active', true)
            ->select('store_branches.id', 'store_branches.name', 'store_branches.branch_code')
            ->orderBy('store_branches.name')
            ->get();

        $scheduledMap = DTSDeliverySchedule::where('variant', $supplier->supplier_code)
            ->whereIn('store_branch_id', $storeBranches->pluck('id'))
            ->get(['store_branch_id', 'delivery_schedule_id'])
            ->groupBy('store_branch_id')
            ->map(fn ($rows) => $rows->pluck('delivery_schedule_id')->map(fn ($d) => (int) $d)->values());

        return Inertia::render('DSPDeliverySchedule/Show', [
            'supplier' => $supplier,
            'storeBranches' => $storeBranches,
            'scheduledMap' => $scheduledMap,
            'days' => $this->dayLabels(),
        ]);
    }

    public function index()
    {
        $user = Auth::user();

        $query = Supplier::query()->where('is_active', true);

        $query->whereHas('users', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        });

        $suppliers = $query->when(request('search'), function ($query, $search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('supplier_code', 'like', "%{$search}%");
            });
        })
            ->select('id', 'supplier_code', 'name')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('DSPDeliverySchedule/Index', [
            'suppliers' => $suppliers,
            'filters' => request()->only(['search'])
        ]);
    }

    public function edit($id)
    {
        $supplier = Supplier::findOrFail($id);
        $user = Auth::user();

        // Rows of the matrix: the user's active branches.
        $storeBranches = $user->store_branches()
            ->where('is_active', true)
            ->select('store_branches.id', 'store_branches.name', 'store_branches.branch_code')
            ->orderBy('store_branches.name')
            ->get();

        $branchIds = $storeBranches->pluck('id');

        // Existing assignments for this supplier, keyed by branch id => [dayIds...].
        $scheduledMap = DTSDeliverySchedule::where('variant', $supplier->supplier_code)
            ->whereIn('store_branch_id', $branchIds)
            ->get(['store_branch_id', 'delivery_schedule_id'])
            ->groupBy('store_branch_id')
            ->map(fn ($rows) => $rows->pluck('delivery_schedule_id')->map(fn ($d) => (int) $d)->values());

        return Inertia::render('DSPDeliverySchedule/Edit', [
            'supplier' => $supplier,
            'storeBranches' => $storeBranches,
            'scheduledMap' => $scheduledMap,
            'days' => $this->dayLabels(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);

        $validated = $request->validate([
            'assignments' => 'present|array',
            'assignments.*' => 'array',
            'assignments.*.*' => 'integer|between:1,7',
        ]);

        $user = Auth::user();
        // Only allow writing schedules for branches the user actually owns.
        $allowedBranchIds = $user->store_branches()
            ->where('is_active', true)
            ->pluck('store_branches.id')
            ->all();

        $newSchedules = [];
        foreach ($validated['assignments'] as $branchId => $dayIds) {
            if (! in_array((int) $branchId, $allowedBranchIds, true)) {
                continue;
            }
            foreach (array_unique($dayIds) as $dayId) {
                $newSchedules[] = [
                    'delivery_schedule_id' => $dayId,
                    'store_branch_id' => (int) $branchId,
                    'variant' => $supplier->supplier_code,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::transaction(function () use ($supplier, $allowedBranchIds, $newSchedules) {
            // Replace only this user's branches for this supplier; leave other
            // users' branch assignments for the same supplier untouched.
            DTSDeliverySchedule::where('variant', $supplier->supplier_code)
                ->whereIn('store_branch_id', $allowedBranchIds)
                ->delete();

            if (! empty($newSchedules)) {
                DTSDeliverySchedule::insert($newSchedules);
            }
        });

        return redirect()->route('dsp-delivery-schedules.index')->with('success', 'Delivery schedule updated successfully.');
    }

    private function dayLabels(): array
    {
        return [
            ['id' => 1, 'label' => 'Mon', 'full' => 'MONDAY'],
            ['id' => 2, 'label' => 'Tue', 'full' => 'TUESDAY'],
            ['id' => 3, 'label' => 'Wed', 'full' => 'WEDNESDAY'],
            ['id' => 4, 'label' => 'Thu', 'full' => 'THURSDAY'],
            ['id' => 5, 'label' => 'Fri', 'full' => 'FRIDAY'],
            ['id' => 6, 'label' => 'Sat', 'full' => 'SATURDAY'],
            ['id' => 7, 'label' => 'Sun', 'full' => 'SUNDAY'],
        ];
    }
}
