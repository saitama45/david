<?php

namespace App\Http\Controllers;

use App\Models\DTSDeliverySchedule;
use App\Models\StoreBranch;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class DSPDeliveryScheduleController extends Controller
{
    public function show($id)
    {
        $supplier = Supplier::findOrFail($id);

        // Get existing schedules for this supplier, similar to edit method
        $schedules = DTSDeliverySchedule::where('variant', $supplier->supplier_code)
            ->with('store_branch:id,name,branch_code') // Eager load branch details
            ->get();

        // Group schedules by day of the week
        $days = [
            1 => 'MONDAY', 2 => 'TUESDAY', 3 => 'WEDNESDAY',
            4 => 'THURSDAY', 5 => 'FRIDAY', 6 => 'SATURDAY', 7 => 'SUNDAY'
        ];

        $schedulesByDay = [];
        foreach ($days as $dayId => $dayName) {
            $branchesForDay = $schedules->where('delivery_schedule_id', $dayId)->pluck('store_branch')->filter();
            if ($branchesForDay->isNotEmpty()) {
                $schedulesByDay[$dayName] = $branchesForDay->values();
            }
        }

        return Inertia::render('DSPDeliverySchedule/Show', [
            'supplier' => $supplier,
            'schedulesByDay' => $schedulesByDay,
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

        $storeBranches = $user->store_branches()
            ->where('is_active', true)
            ->select('store_branches.id', 'store_branches.name', 'store_branches.branch_code')
            ->get();

        // Get existing schedules for this supplier
        $existingSchedules = DTSDeliverySchedule::where('variant', $supplier->supplier_code)
            ->get()
            ->groupBy('delivery_schedule_id'); // Group by day (1-6)

        // Prepare schedules data for the view
        $days = [
            1 => 'MONDAY', 2 => 'TUESDAY', 3 => 'WEDNESDAY',
            4 => 'THURSDAY', 5 => 'FRIDAY', 6 => 'SATURDAY', 7 => 'SUNDAY'
        ];

        $schedulesByDay = [];
        foreach ($days as $dayId => $dayName) {
            $scheduledBranchesForDay = $existingSchedules->get($dayId, collect())->map(function ($schedule) use ($storeBranches) {
                return $storeBranches->firstWhere('id', $schedule->store_branch_id);
            })->filter()->values(); // Filter out nulls and re-index
            
            $schedulesByDay[$dayName] = $scheduledBranchesForDay;
        }


        return Inertia::render('DSPDeliverySchedule/Edit', [
            'supplier' => $supplier,
            'storeBranches' => $storeBranches,
            'schedulesByDay' => $schedulesByDay,
        ]);
    }

    public function update(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);
        $schedules = $request->input('schedules');

        // Delete old schedules for this supplier
        DTSDeliverySchedule::where('variant', $supplier->supplier_code)->delete();

        $dayMap = [
            'MONDAY' => 1, 'TUESDAY' => 2, 'WEDNESDAY' => 3,
            'THURSDAY' => 4, 'FRIDAY' => 5, 'SATURDAY' => 6, 'SUNDAY' => 7
        ];

        $newSchedules = [];
        if ($schedules) {
            foreach ($schedules as $dayName => $branchIds) {
                if (isset($dayMap[$dayName]) && is_array($branchIds)) {
                    $dayId = $dayMap[$dayName];
                    foreach ($branchIds as $branchId) {
                        $newSchedules[] = [
                            'delivery_schedule_id' => $dayId,
                            'store_branch_id' => $branchId,
                            'variant' => $supplier->supplier_code,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }
        }

        // Insert new schedules
        if (!empty($newSchedules)) {
            DTSDeliverySchedule::insert($newSchedules);
        }

        return redirect()->route('dsp-delivery-schedules.index');
    }
}
