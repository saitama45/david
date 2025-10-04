<?php

namespace App\Http\Controllers;

use App\Models\DTSDeliverySchedule;
use App\Models\OrdersCutoff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class OrdersCutoffController extends Controller
{
    // Day mapping constants
    private const DAY_MAP = [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    ];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $assigned_supplier_codes = $user->suppliers()->pluck('suppliers.supplier_code');

        // Special variants that should always be visible
        $specialVariants = ['ICE CREAM', 'SALMON', 'FRUITS AND VEGETABLES'];

        $filters = $request->only('search');
        $query = OrdersCutoff::query()
            ->with('dtsDeliverySchedules')
            ->where(function ($q) use ($assigned_supplier_codes, $specialVariants) {
                $q->whereIn('ordering_template', $assigned_supplier_codes)
                  ->orWhereIn('ordering_template', $specialVariants);
            });

        if ($request->filled('search')) {
            $query->where('ordering_template', 'like', '%' . $request->input('search') . '%');
        }

        $ordersCutoffs = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('OrdersCutoff/Index', [
            'ordersCutoffs' => $ordersCutoffs,
            'filters' => $filters,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $user = Auth::user();

        // Get user's assigned supplier codes
        $assignedSupplierCodes = $user->suppliers()->pluck('suppliers.supplier_code');

        // Get special variants (ICE CREAM, SALMON, FRUITS AND VEGETABLES)
        $specialVariants = ['ICE CREAM', 'SALMON', 'FRUITS AND VEGETABLES'];
        $specialVariantsFromDb = DTSDeliverySchedule::whereIn('variant', $specialVariants)
            ->distinct()
            ->pluck('variant');

        // Merge assigned supplier codes with special variants
        $variants = $assignedSupplierCodes->merge($specialVariantsFromDb)->unique()->values();

        return Inertia::render('OrdersCutoff/Create', compact('variants'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ordering_template' => ['required', 'string', 'max:255'],
            'cutoff_1_day' => ['required', 'integer', 'between:1,7'],
            'cutoff_1_time' => ['required'],
            'days_covered_1' => ['required'], // Allow string or array
            'cutoff_2_day' => ['nullable', 'integer', 'between:1,7'],
            'cutoff_2_time' => ['nullable'],
            'days_covered_2' => ['nullable'], // Allow string or array
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();

        // Convert days_covered arrays to string if they are arrays
        if (is_array($data['days_covered_1'])) {
            $data['days_covered_1'] = $this->convertDayIdsToString($data['days_covered_1']);
        }
        if (isset($data['days_covered_2']) && is_array($data['days_covered_2'])) {
            if (!empty($data['days_covered_2'])) {
                $data['days_covered_2'] = $this->convertDayIdsToString($data['days_covered_2']);
            } else {
                $data['days_covered_2'] = null;
            }
        }

        OrdersCutoff::create($data);

        return redirect()->route('orders-cutoff.index')->with('success', 'Order cutoff created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(OrdersCutoff $ordersCutoff)
    {
        $ordersCutoff->load('dtsDeliverySchedules');
        return Inertia::render('OrdersCutoff/Show', compact('ordersCutoff'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(OrdersCutoff $ordersCutoff)
    {
        $user = Auth::user();

        // Get user's assigned supplier codes
        $assignedSupplierCodes = $user->suppliers()->pluck('suppliers.supplier_code');

        // Get special variants (ICE CREAM, SALMON, FRUITS AND VEGETABLES)
        $specialVariants = ['ICE CREAM', 'SALMON', 'FRUITS AND VEGETABLES'];
        $specialVariantsFromDb = DTSDeliverySchedule::whereIn('variant', $specialVariants)
            ->distinct()
            ->pluck('variant');

        // Merge assigned supplier codes with special variants
        $variants = $assignedSupplierCodes->merge($specialVariantsFromDb)->unique()->values();

        // Convert string to array of days for the form
        $ordersCutoff->days_covered_1 = $this->convertStringToDayIds($ordersCutoff->days_covered_1);
        $ordersCutoff->days_covered_2 = $this->convertStringToDayIds($ordersCutoff->days_covered_2);

        return Inertia::render('OrdersCutoff/Edit', compact('ordersCutoff', 'variants'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, OrdersCutoff $ordersCutoff)
    {
        $validator = Validator::make($request->all(), [
            'ordering_template' => ['required', 'string', 'max:255'],
            'cutoff_1_day' => ['required', 'integer', 'between:1,7'],
            'cutoff_1_time' => ['required'],
            'days_covered_1' => ['required'], // Allow string or array
            'cutoff_2_day' => ['nullable', 'integer', 'between:1,7'],
            'cutoff_2_time' => ['nullable'],
            'days_covered_2' => ['nullable'], // Allow string or array
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();

        // Convert days_covered arrays to string if they are arrays
        if (is_array($data['days_covered_1'])) {
            $data['days_covered_1'] = $this->convertDayIdsToString($data['days_covered_1']);
        }
        if (isset($data['days_covered_2']) && is_array($data['days_covered_2'])) {
             if (!empty($data['days_covered_2'])) {
                $data['days_covered_2'] = $this->convertDayIdsToString($data['days_covered_2']);
            } else {
                $data['days_covered_2'] = null;
            }
        }

        $ordersCutoff->update($data);

        return redirect()->route('orders-cutoff.index')->with('success', 'Order cutoff updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(OrdersCutoff $ordersCutoff)
    {
        $ordersCutoff->delete();

        return redirect()->route('orders-cutoff.index')->with('success', 'Order cutoff deleted successfully.');
    }

    /**
     * Convert an array of day IDs (1-7) to a comma-separated string.
     */
    private function convertDayIdsToString(array $days): string
    {
        sort($days);
        $dayAbbrs = array_map(function ($dayId) {
            return self::DAY_MAP[$dayId] ?? null;
        }, $days);

        return implode(',', array_filter($dayAbbrs));
    }

    /**
     * Convert a comma-separated string of day abbreviations to an array of day IDs.
     */
    private function convertStringToDayIds(?string $dayString): array
    {
        if (is_null($dayString) || $dayString === '') {
            return [];
        }

        $dayAbbrs = explode(',', $dayString);
        $dayIds = [];
        $dayMapFlipped = array_flip(self::DAY_MAP);

        foreach ($dayAbbrs as $abbr) {
            if (isset($dayMapFlipped[$abbr])) {
                $dayIds[] = $dayMapFlipped[$abbr];
            }
        }
        
        return $dayIds;
    }
}