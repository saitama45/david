<?php

namespace App\Http\Controllers;

use App\Http\Services\SohAdjustmentService;
use App\Models\ProductInventoryStockManager;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Support\ItemStockUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SOHAdjustmentController extends Controller
{
    public const TAB_ITEMS = 'items';

    public const TAB_PENDING = 'pending';

    public function __construct(private SohAdjustmentService $adjustments) {}

    /**
     * Two tabs for one store: its items with their stock on hand, to file a correction,
     * and the corrections that wait for approval.
     */
    public function index()
    {
        $search = request('search');
        $tab = request('tab') === self::TAB_PENDING ? self::TAB_PENDING : self::TAB_ITEMS;
        $branches = $this->branches();
        $branchId = (int) (request('branchId') ?: ($branches->first()['value'] ?? 0));

        if (! $branches->contains('value', $branchId)) {
            $branchId = (int) ($branches->first()['value'] ?? 0);
        }

        $pending = $branchId ? $this->adjustments->pending($branchId, null, $tab === self::TAB_PENDING ? $search : null) : collect();

        return Inertia::render('SOHAdjustment/Index', [
            'branches' => $branches,
            'tab' => $tab,
            'items' => $tab === self::TAB_ITEMS && $branchId ? $this->items($branchId, $search) : null,
            'pending' => $tab === self::TAB_PENDING ? $this->pendingRows($branchId, $pending) : [],
            'pendingCount' => $branchId ? $this->adjustments->pending($branchId)->count() : 0,
            'filters' => ['search' => $search, 'branchId' => $branchId, 'tab' => $tab],
        ]);
    }

    /**
     * File a correction: the new stock on hand of one item at one store, in the unit of the
     * row picked. It changes no stock until it is approved.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'branchId' => ['required', 'integer'],
            'sap_masterfile_id' => ['required', 'integer'],
            'new_quantity' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'remarks' => ['required', 'string', 'max:255'],
        ], [
            'new_quantity.required' => 'Enter the new SOH.',
            'new_quantity.min' => 'The new SOH cannot be below 0.',
            'remarks.required' => 'Say why the SOH is being adjusted.',
        ]);

        $this->authorizeBranch((int) $validated['branchId']);

        $this->adjustments->requestNewQuantity(
            SAPMasterfile::findOrFail($validated['sap_masterfile_id']),
            (int) $validated['branchId'],
            (float) $validated['new_quantity'],
            trim($validated['remarks']),
            $request->user()
        );

        return back()->with('success', 'SOH adjustment sent for approval.');
    }

    public function approveSelectedItems(Request $request)
    {
        foreach ($this->selectedAdjustments($request) as $adjustment) {
            $this->adjustments->approve($adjustment, $request->user());
        }

        return back()->with('success', 'SOH adjustment approved. Stock on hand updated.');
    }

    public function rejectSelectedItems(Request $request)
    {
        foreach ($this->selectedAdjustments($request) as $adjustment) {
            $this->adjustments->reject($adjustment, $request->user());
        }

        return back()->with('success', 'SOH adjustment rejected. Stock on hand was not changed.');
    }

    /** The stores the user may work on, without the "All Branches" choice: an adjustment is for one store. */
    private function branches()
    {
        return StoreBranch::options()->reject(fn ($option) => $option['value'] === 'all')->values();
    }

    private function authorizeBranch(int $branchId): void
    {
        abort_unless($this->branches()->contains('value', $branchId), 403, 'You are not assigned to this store.');
    }

    /** The adjustments named in the request, all of the one store the user may work on. */
    private function selectedAdjustments(Request $request)
    {
        $validated = $request->validate([
            'selectedItems' => ['required', 'array', 'min:1'],
            'selectedItems.*' => ['integer'],
            'branchId' => ['required', 'integer'],
        ]);

        $this->authorizeBranch((int) $validated['branchId']);

        return ProductInventoryStockManager::whereIn('id', $validated['selectedItems'])
            ->where('store_branch_id', $validated['branchId'])
            ->orderBy('id')
            ->get();
    }

    /**
     * The store's items, one row per item + unit as Stock Management lists them, each with
     * its stock on hand in that unit and the adjustment already waiting on it, if any.
     */
    private function items(int $branchId, ?string $search)
    {
        // A unit's own base row wins over a conversion row that restates it.
        $unitRows = SAPMasterfile::query()
            ->select('sap_masterfiles.id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY sap_masterfiles.ItemCode, UPPER(LTRIM(RTRIM(sap_masterfiles.AltUOM)))
                ORDER BY CASE WHEN UPPER(LTRIM(RTRIM(sap_masterfiles.BaseUOM))) = UPPER(LTRIM(RTRIM(sap_masterfiles.AltUOM))) THEN 0 ELSE 1 END, sap_masterfiles.id) as unit_rn')
            ->whereNotNull('sap_masterfiles.AltUOM')
            ->where('sap_masterfiles.AltUOM', '!=', '')
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('sap_masterfiles.ItemDescription', 'like', "%{$search}%")
                        ->orWhere('sap_masterfiles.ItemCode', 'like', "%{$search}%");
                });
            });

        $items = SAPMasterfile::query()
            ->whereIn('sap_masterfiles.id', DB::query()->fromSub($unitRows, 'unit_rows')->where('unit_rn', 1)->select('id'))
            ->orderBy('ItemDescription')
            ->orderBy('ItemCode')
            ->orderBy('AltUOM')
            ->paginate(10, ['id', 'ItemCode', 'ItemDescription', 'AltUOM', 'is_active'])
            ->withQueryString();

        $page = $items->getCollection();
        $stockUnits = SAPMasterfile::whereIn('ItemCode', $page->pluck('ItemCode')->unique()->values()->all())
            ->orderBy('id')->get()->groupBy('ItemCode')
            ->map(fn ($rows) => ItemStockUnit::fromRows($rows));
        $stockRowIds = $page->map(fn ($item) => $stockUnits->get($item->ItemCode)?->stockRowFor($item->AltUOM)?->id)
            ->filter()->unique()->values()->all();
        $balances = $this->adjustments->balances($branchId, $stockRowIds);
        $pending = $this->adjustments->pending($branchId, $stockRowIds)->keyBy('product_inventory_id');

        return $items->through(function ($item) use ($stockUnits, $balances, $pending) {
            $stockUnit = $stockUnits->get($item->ItemCode);
            $stockRow = $stockUnit?->stockRowFor($item->AltUOM);
            $factor = $stockUnit?->factor($item->AltUOM);
            $waiting = $stockRow ? $pending->get($stockRow->id) : null;

            return [
                'id' => $item->id,
                'item_code' => $item->ItemCode,
                'name' => $item->ItemDescription,
                'uom' => $item->AltUOM,
                'is_active' => (bool) $item->is_active,
                // No stock row or conversion: the unit cannot be adjusted.
                'adjustable' => (bool) ($stockRow && $factor),
                'soh' => $stockRow && $factor ? round(($balances->get($stockRow->id) ?? 0) / $factor, 4) : null,
                // The difference waiting for approval, in this row's unit.
                'pending_difference' => $waiting && $factor ? round((float) $waiting->quantity / $factor, 4) : null,
            ];
        });
    }

    /** The waiting adjustments as the approval tab shows them, in each item's stock unit. */
    private function pendingRows(int $branchId, $pending): array
    {
        $balances = $this->adjustments->balances($branchId, $pending->pluck('product_inventory_id')->unique()->values()->all());

        return $pending->map(function ($adjustment) use ($balances) {
            $soh = (float) ($balances->get($adjustment->product_inventory_id) ?? 0);
            $difference = (float) $adjustment->quantity;

            return [
                'id' => $adjustment->id,
                'item_code' => $adjustment->sapMasterfile?->ItemCode,
                'name' => $adjustment->sapMasterfile?->ItemDescription,
                'uom' => $adjustment->sapMasterfile?->AltUOM,
                'soh' => round($soh, 4),
                'difference' => round($difference, 4),
                'new_soh' => round($soh + $difference, 4),
                'remarks' => $adjustment->remarks,
                'requested_at' => $adjustment->created_at?->timezone('Asia/Manila')->format('M j, Y g:i A'),
            ];
        })->values()->all();
    }
}
