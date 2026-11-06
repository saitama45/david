<?php

namespace App\Http\Services;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Models\CpoVendor;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\SupplierItems;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CPO Orders: a store's purchase from an outside vendor, placed through the mass order
 * template (one column per store). The vendor may be left blank, and its invoice / receipt is
 * not taken here: the store enters the first and uploads the second in Inbound Orders, and
 * receiving needs both (OrderReceivingService::deliveryEvidenceProblem).
 *
 * Unlike a Mass Orders upload, the cost of each line is typed in from the receipt, every
 * order waits in Mass Orders Approval, and approving it commits it for receiving
 * (MassOrdersApprovalController::approve).
 */
class CpoOrderService
{
    /** Columns of the template before the store columns. */
    public const TEMPLATE_HEADERS = ['Category', 'Classification', 'Item Code', 'Item Name', 'Packaging Config', 'Unit', 'Cost'];

    /** The most row problems listed back after a refused upload. */
    private const MAX_LISTED_PROBLEMS = 15;

    /** The most items the Edit page's item picker lists at a time. */
    public const ITEM_SEARCH_LIMIT = 8;

    public function __construct(
        private StoreOrderService $storeOrders,
        private MonthEndClosedPeriodService $closedPeriods,
        private OrderingCutoffService $cutoffs,
    ) {}

    /**
     * Counts, as `receiving_records`, the lines receiving has started on: a quantity banked, or a
     * receiving row filled in or confirmed. An untouched placeholder row does not count. The same
     * test Mass Orders uses to stop an edit.
     */
    public static function withReceivingRecords(Builder $query): Builder
    {
        return $query->withCount(['store_order_items as receiving_records' => fn ($q) => $q
            ->where(fn ($i) => $i
                ->where('quantity_received', '>', 0)
                ->orWhereHas('ordered_item_receive_dates', fn ($r) => $r
                    ->where(fn ($d) => $d->whereNotNull('received_date')
                        ->orWhereIn('status', ['approved', 'received']))))]);
    }

    /**
     * Why a CPO order can no longer be edited, or null while it can. Mass Orders' rules: only
     * while it is pending or approved (a CPO order is committed by its last approval, so until
     * then), never once receiving has started, and not after the template's edit cutoff.
     */
    public function editProblem(StoreOrder $order): ?string
    {
        if (! in_array($order->order_status, $this->cutoffs->massOrderEditableStatuses(Supplier::CPO), true)) {
            return "This order is already {$order->order_status} and can no longer be edited.";
        }

        // A list page counts it for every order at once (withReceivingRecords); otherwise read it here.
        $receiving = array_key_exists('receiving_records', $order->getAttributes())
            ? $order->receiving_records
            : self::withReceivingRecords(StoreOrder::whereKey($order->getKey()))->first()?->receiving_records;

        if ((int) $receiving > 0) {
            return 'Receiving has started on this order, so it can no longer be edited.';
        }

        $deadline = $this->editDeadline($order);
        if ($deadline && $this->cutoffs->now()->gte($deadline)) {
            return 'The edit cutoff for this order passed on '.$deadline->format('M j, Y g:i A').'.';
        }

        return null;
    }

    /** The first CPO cutoff after the order was placed, or null when no CPO cutoff is set. */
    public function editDeadline(StoreOrder $order): ?Carbon
    {
        return $this->cutoffs->massOrderEditDeadline(Supplier::CPO, $order->created_at);
    }

    /**
     * Items of the CPO catalogue whose code or name contains the term, best first, each with the
     * units it may be bought in. A term shorter than MIN_SEARCH_CHARS only finds an exact code or name.
     *
     * @return list<array{item_code: string, item_name: string, units: list<string>}>
     */
    public function itemOptions(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $query = SupplierItems::whereIn('SupplierCode', SupplierItems::catalogueCodesFor(Supplier::CPO))
            ->where('is_active', true);

        if (mb_strlen($term) < CpoVendor::MIN_SEARCH_CHARS) {
            $query->where(fn ($q) => $q->where('ItemCode', $term)->orWhere('item_name', $term));
        } else {
            // SQL Server LIKE has no escape character by default; brackets make a wildcard literal.
            $literal = str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $term);
            $query->where(fn ($q) => $q->where('ItemCode', 'like', "%{$literal}%")->orWhere('item_name', 'like', "%{$literal}%"))
                ->orderByRaw('CASE WHEN [ItemCode] LIKE ? OR [item_name] LIKE ? THEN 0 ELSE 1 END', ["{$literal}%", "{$literal}%"]);
        }

        // An item sits in several catalogues: read a few more rows than shown, then one per item.
        $items = $query->orderBy('item_name')
            ->limit(self::ITEM_SEARCH_LIMIT * 5)
            ->get(['ItemCode', 'item_name'])
            ->unique('ItemCode')
            ->take(self::ITEM_SEARCH_LIMIT)
            ->values();

        $units = $this->unitsByItem($items->pluck('ItemCode'));

        return $items->map(fn ($item) => [
            'item_code' => $item->ItemCode,
            'item_name' => $item->item_name,
            'units' => $units[$item->ItemCode] ?? [],
        ])->all();
    }

    /**
     * Change a CPO order that may still be edited: its store, delivery date, vendor and lines, each
     * line with its typed cost. The same rules as placing it, and as editing a mass order.
     *
     * @param  array{order_date: string, store_branch_id: int|string, lines: list<array{id?: ?int, item_code: string, uom: string, quantity: numeric, cost: numeric}>}  $data
     *
     * @throws ValidationException when the order can no longer be edited, or a change breaks a rule
     */
    public function update(StoreOrder $order, array $data, ?CpoVendor $vendor, User $user): StoreOrder
    {
        if ($problem = $this->editProblem($order)) {
            throw ValidationException::withMessages(['error' => $problem]);
        }

        $date = Carbon::parse($data['order_date'])->toDateString();
        $storeId = (int) $data['store_branch_id'];
        $storeChanged = $storeId !== (int) $order->store_branch_id;
        $dateChanged = $date !== Carbon::parse($order->order_date)->toDateString();
        $vendorChanged = $vendor?->id !== ($order->cpo_vendor_id === null ? null : (int) $order->cpo_vendor_id);

        // A new store must be one the user may place a CPO order for.
        if ($storeChanged && ! $this->scheduledStores($user)->contains('id', $storeId)) {
            throw ValidationException::withMessages(['store_branch_id' => 'Choose one of your stores that is set up to order CPO.']);
        }

        // The order keeps a date it already has, but cannot be moved into a period its store
        // closed with a final approved month end count.
        if (($storeChanged || $dateChanged) && ($closed = $this->closedPeriods->problem($storeId, $date))) {
            throw ValidationException::withMessages(['order_date' => $closed]);
        }

        $lines = $this->editedLines($order, $data['lines']);

        // One purchase per store, date and vendor (or no vendor yet), as when it was placed.
        if ($storeChanged || $dateChanged || $vendorChanged) {
            $existing = StoreOrder::where('store_branch_id', $storeId)
                ->where('supplier_id', $order->supplier_id)
                ->where('variant', 'mass regular')
                ->where('cpo_vendor_id', $vendor?->id)
                ->whereDate('order_date', $date)
                ->where('order_status', '!=', OrderStatus::REJECTED->value)
                ->whereKeyNot($order->getKey())
                ->value('order_number');

            if ($existing) {
                throw ValidationException::withMessages(['error' => $vendor
                    ? "A CPO order from {$vendor->name} for this store and date already exists ({$existing})."
                    : "A CPO order with no vendor yet for this store and date already exists ({$existing})."]);
            }
        }

        return DB::transaction(function () use ($order, $date, $storeId, $storeChanged, $vendor, $vendorChanged, $lines) {
            // Checked again under the lock, so an approval at the same moment cannot slip in.
            $order = StoreOrder::lockForUpdate()->findOrFail($order->getKey());
            if ($problem = $this->editProblem($order)) {
                throw ValidationException::withMessages(['error' => $problem]);
            }

            $changes = ['store_branch_id' => $storeId, 'order_date' => $date];

            // An order moved to another store takes that store's next order number, as in Mass Orders.
            if ($storeChanged) {
                $changes['order_number'] = $this->storeOrders->getOrderNumber($storeId);
            }

            // A vendor left as it was keeps the details the order was placed with.
            if ($vendorChanged) {
                $changes += [
                    'cpo_vendor_id' => $vendor?->id,
                    'cpo_vendor_name' => $vendor?->name,
                    'cpo_vendor_tin' => $vendor?->tin,
                    'cpo_vendor_address' => $vendor?->address,
                ];
            }

            $order->update($changes);

            // Lines taken off the order. One by one, so each removal is audited.
            $kept = collect($lines)->pluck('id')->filter()->all();
            $order->store_order_items()->whereNotIn('id', $kept ?: [0])->get()->each->delete();

            foreach ($lines as $line) {
                // Approval and receiving read the approved and committed quantities: an edited
                // line restarts from what is ordered, as a new order does.
                $values = [
                    'item_code' => $line['item_code'],
                    'uom' => $line['uom'],
                    'quantity_ordered' => $line['quantity'],
                    'quantity_approved' => $line['quantity'],
                    'quantity_commited' => $line['quantity'],
                    'cost_per_quantity' => $line['cost'],
                    'total_cost' => $line['total_cost'],
                ];

                if ($line['id']) {
                    $order->store_order_items()->whereKey($line['id'])->first()?->update($values);
                } else {
                    $order->store_order_items()->create($values);
                }
            }

            return $order->refresh();
        });
    }

    /**
     * The edited lines, checked as an upload checks its rows: an item of the CPO list, one of its
     * units, a quantity and a cost above 0, and each item and unit once. Quantities and costs are
     * kept to 4 decimals and sent as fixed-point text.
     *
     * @throws ValidationException listing each line's problem under `lines.N.field`
     */
    private function editedLines(StoreOrder $order, array $lines): array
    {
        $ownIds = $order->store_order_items()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $units = $this->unitsByItem(collect($lines)->pluck('item_code')->map(fn ($code) => trim((string) $code))->filter()->unique()->values());

        $clean = [];
        $problems = [];
        $seen = [];

        foreach (array_values($lines) as $i => $line) {
            $itemCode = trim((string) ($line['item_code'] ?? ''));
            $uom = trim((string) ($line['uom'] ?? ''));
            $quantity = round((float) ($line['quantity'] ?? 0), 4);
            $cost = round((float) ($line['cost'] ?? 0), 4);
            $id = isset($line['id']) && $line['id'] !== null && $line['id'] !== '' ? (int) $line['id'] : null;

            if ($id !== null && ! in_array($id, $ownIds, true)) {
                $problems["lines.{$i}.item_code"] = 'This line is not on the order any more. Reload the page.';
            }

            if (! isset($units[$itemCode])) {
                $problems["lines.{$i}.item_code"] = "'{$itemCode}' is not in the CPO list of items.";
            } elseif (! in_array(strtoupper($uom), $units[$itemCode], true)) {
                $problems["lines.{$i}.uom"] = "'{$uom}' is not a unit of this item.";
            }

            $key = strtoupper($itemCode.'|'.$uom);
            if (isset($seen[$key])) {
                $problems["lines.{$i}.uom"] = 'This item and unit are already on line '.($seen[$key] + 1).'.';
            }
            $seen[$key] ??= $i;

            if ($quantity <= 0) {
                $problems["lines.{$i}.quantity"] = 'The quantity must be greater than 0.';
            }
            if ($cost <= 0) {
                $problems["lines.{$i}.cost"] = 'The cost must be greater than 0.';
            }

            $clean[] = [
                'id' => $id,
                'item_code' => $itemCode,
                'uom' => $uom,
                'quantity' => number_format($quantity, 4, '.', ''),
                'cost' => number_format($cost, 4, '.', ''),
                'total_cost' => number_format(round($quantity * $cost, 4), 4, '.', ''),
            ];
        }

        if ($problems) {
            throw ValidationException::withMessages(
                ['error' => 'Nothing was saved. Correct the lines marked below.'] + $problems
            );
        }

        return $clean;
    }

    /** The items a CPO order can carry: the catalogues CPO consolidates. */
    public function catalogue(): Collection
    {
        return SupplierItems::forSupplierCode(Supplier::CPO)
            ->sortBy(fn ($item) => ($item->sort_order ?? 0) == 0 ? PHP_INT_MAX : $item->sort_order)
            ->values();
    }

    /** The user's active stores set up to order CPO (on any day). */
    public function scheduledStores(User $user): Collection
    {
        return $user->store_branches()
            ->where('store_branches.is_active', true)
            ->whereHas('delivery_schedules', fn ($q) => $q->where('d_t_s_delivery_schedules.variant', Supplier::CPO))
            ->get();
    }

    /** Of those, the stores whose period is still open on the date. */
    public function openStores(User $user, string $date): Collection
    {
        $stores = $this->scheduledStores($user);
        $closedThrough = $this->closedPeriods->closedThrough($stores->pluck('id'));

        return $stores->reject(fn ($store) => $date <= ($closedThrough[$store->id] ?? ''))->values();
    }

    /**
     * Create one pending CPO order per store with quantities in the file.
     *
     * @return array{success: bool, message: string, skipped_stores: array, created_count: int}
     *
     * @throws ValidationException when the file is not a filled CPO template, a line misses
     *                             its item, unit or cost, or no store in it may order
     */
    public function processUpload(Collection $rows, string $orderDate, ?CpoVendor $vendor, User $user): array
    {
        $orderDate = Carbon::parse($orderDate)->toDateString();

        if ($rows->isEmpty()) {
            $this->refuse('The uploaded file is empty. Download the CPO Orders template and fill it in.');
        }

        $headers = collect($rows->first())->keys();

        foreach ($rows as $row) {
            $template = trim((string) ($row['ordering_template'] ?? ''));
            if ($template !== '' && strcasecmp($template, Supplier::CPO) !== 0) {
                $this->refuse("This file is the '{$template}' template, not CPO. Download the CPO Orders template and fill it in.");
            }
        }

        if (! $headers->contains('cost')) {
            $this->refuse('This file has no Cost column. Download the CPO Orders template and fill it in.');
        }

        // Store columns: headers matching a store's brand code.
        $storeColumns = [];
        foreach (StoreBranch::pluck('brand_code')->filter() as $brandCode) {
            $header = Str::slug($brandCode, '_');
            if ($headers->contains($header)) {
                $storeColumns[$header] = $brandCode;
            }
        }

        if (! $storeColumns) {
            $this->refuse('No store columns were found in the file. Download the CPO Orders template and fill it in.');
        }

        $scheduled = $this->scheduledStores($user)->keyBy('brand_code');
        $open = $this->openStores($user, $orderDate)->keyBy('brand_code');

        $skipped = [];
        $usable = [];
        foreach ($storeColumns as $header => $brandCode) {
            if (! $scheduled->has($brandCode)) {
                $skipped[$brandCode] = 'Store is not assigned to you or is not set up to order CPO.';
            } elseif (! $open->has($brandCode)) {
                $skipped[$brandCode] = $this->closedPeriods->problem((int) $scheduled[$brandCode]->id, $orderDate)
                    ?? 'The selected date is closed for this store.';
            } else {
                $usable[$header] = $open[$brandCode];
            }
        }

        [$linesByStore, $problems] = $this->readLines($rows, $usable);

        if ($problems) {
            $listed = array_slice($problems, 0, self::MAX_LISTED_PROBLEMS);
            if (count($problems) > count($listed)) {
                $listed[] = 'And '.(count($problems) - count($listed)).' more.';
            }
            // One key per problem: Inertia passes on only the first message of a key.
            throw ValidationException::withMessages(
                ['mass_order_file' => 'Nothing was saved. Correct these rows and upload the file again.']
                + collect($listed)->mapWithKeys(fn ($problem, $i) => ["rows.{$i}" => $problem])->all()
            );
        }

        // Stores skipped above may still carry quantities; report them only then.
        $skipped = array_intersect_key($skipped, $this->storesWithQuantities($rows, $storeColumns));

        if (! $linesByStore) {
            if ($skipped) {
                return $this->result(false, 'No CPO order was created. No store in the file may order for the selected date.', $skipped, 0);
            }
            $this->refuse('The file has no quantities. Enter a quantity under a store for at least one item.');
        }

        $supplier = Supplier::where('supplier_code', Supplier::CPO)->firstOrFail();
        $created = 0;

        DB::transaction(function () use ($linesByStore, $usable, $supplier, $vendor, $orderDate, $user, &$skipped, &$created) {
            foreach ($linesByStore as $header => $lines) {
                $store = $usable[$header];

                // One purchase per store, date and vendor, or with no vendor yet, which catches the
                // same file uploaded twice; a rejected one may be placed again.
                $existing = StoreOrder::where('store_branch_id', $store->id)
                    ->where('supplier_id', $supplier->id)
                    ->where('variant', 'mass regular')
                    ->where('cpo_vendor_id', $vendor?->id)
                    ->whereDate('order_date', $orderDate)
                    ->where('order_status', '!=', OrderStatus::REJECTED->value)
                    ->value('order_number');

                if ($existing) {
                    $skipped[$store->brand_code] = $vendor
                        ? "A CPO order from {$vendor->name} for this date already exists ({$existing})."
                        : "A CPO order with no vendor yet for this date already exists ({$existing}).";
                    continue;
                }

                $order = StoreOrder::create([
                    'encoder_id' => $user->id,
                    'supplier_id' => $supplier->id,
                    'store_branch_id' => $store->id,
                    'order_number' => $this->storeOrders->getOrderNumber($store->id),
                    'order_date' => $orderDate,
                    'order_status' => OrderStatus::PENDING->value,
                    'order_request_status' => OrderRequestStatus::PENDING->value,
                    'variant' => 'mass regular',
                    'cpo_vendor_id' => $vendor?->id,
                    'cpo_vendor_name' => $vendor?->name,
                    'cpo_vendor_tin' => $vendor?->tin,
                    'cpo_vendor_address' => $vendor?->address,
                ]);

                foreach ($lines as $line) {
                    $order->store_order_items()->create([
                        'item_code' => $line['item_code'],
                        'quantity_ordered' => $line['quantity'],
                        'quantity_approved' => $line['quantity'],
                        'quantity_commited' => $line['quantity'],
                        'cost_per_quantity' => $line['cost'],
                        'total_cost' => round($line['quantity'] * $line['cost'], 4),
                        'uom' => $line['uom'],
                    ]);
                }

                $created++;
            }
        });

        $message = $created
            ? "Successfully created {$created} CPO order(s). They are now waiting in Mass Orders Approval. The store completes the vendor details and uploads the invoice / receipt in Inbound Orders when it receives the order."
            : 'No CPO order was created.';

        if ($skipped) {
            $message .= ' '.count($skipped).' store(s) were skipped.';
        }

        return $this->result($created > 0, $message, $skipped, $created);
    }

    /**
     * The vendor details of a CPO order, entered by the store in Inbound Orders. The order keeps
     * what was entered. A name not on the vendor list yet is added to it, to be picked next time;
     * a vendor already on the list keeps its own details, which only CPO Orders' Edit changes.
     *
     * @param  array{vendor_name: string, vendor_tin?: ?string, vendor_address: string}  $details
     */
    public function saveVendorDetails(StoreOrder $order, array $details, User $user): void
    {
        $name = trim($details['vendor_name']);
        $tin = trim((string) ($details['vendor_tin'] ?? '')) ?: null;
        $address = trim($details['vendor_address']);

        DB::transaction(function () use ($order, $name, $tin, $address, $user) {
            $vendor = CpoVendor::where('name', $name)->first() ?? CpoVendor::create([
                'name' => $name,
                'tin' => $tin,
                'address' => $address,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $order->update([
                'cpo_vendor_id' => $vendor->id,
                'cpo_vendor_name' => $vendor->name,
                'cpo_vendor_tin' => $tin,
                'cpo_vendor_address' => $address,
            ]);
        });
    }

    /**
     * The order lines per usable store column, and every problem with the rows that carry a
     * quantity for one: item, unit and cost are required and must be valid.
     *
     * @return array{0: array<string, list<array>>, 1: list<string>}
     */
    private function readLines(Collection $rows, array $usable): array
    {
        $itemCodes = $rows->map(fn ($row) => trim((string) ($row['item_code'] ?? '')))->filter()->unique()->values();
        $units = $this->unitsByItem($itemCodes);

        $lines = [];
        $problems = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // the heading is row 1
            $quantities = [];

            foreach ($usable as $header => $store) {
                $value = $row[$header] ?? null;
                if ($value === null || trim((string) $value) === '') {
                    continue;
                }
                if (! is_numeric($value) || (float) $value < 0) {
                    $problems[] = "Row {$rowNumber}: the quantity for {$store->brand_code} must be a number of 0 or more.";
                    continue;
                }
                if ((float) $value > 0) {
                    $quantities[$header] = round((float) $value, 4);
                }
            }

            if (! $quantities) {
                continue;
            }

            $itemCode = trim((string) ($row['item_code'] ?? ''));
            $uom = trim((string) ($row['unit'] ?? ''));
            $cost = $row['cost'] ?? null;
            $label = $itemCode !== '' ? "Row {$rowNumber} ({$itemCode})" : "Row {$rowNumber}";

            if ($itemCode === '') {
                $problems[] = "{$label}: the Item Code is required.";
            } elseif (! isset($units[$itemCode])) {
                $problems[] = "{$label}: the item is not in the CPO list of items.";
            }

            if ($uom === '') {
                $problems[] = "{$label}: the Unit is required.";
            } elseif (isset($units[$itemCode]) && ! in_array(strtoupper($uom), $units[$itemCode], true)) {
                $problems[] = "{$label}: '{$uom}' is not a unit of this item.";
            }

            if ($cost === null || trim((string) $cost) === '') {
                $problems[] = "{$label}: the Cost is required.";
            } elseif (! is_numeric($cost) || (float) $cost <= 0) {
                $problems[] = "{$label}: the Cost must be a number greater than 0.";
            }

            $key = strtoupper($itemCode.'|'.$uom);
            if ($itemCode !== '' && isset($seen[$key])) {
                $problems[] = "{$label}: the item and unit are already on row {$seen[$key]}.";
            }
            $seen[$key] ??= $rowNumber;

            foreach ($quantities as $header => $quantity) {
                $lines[$header][] = [
                    'item_code' => $itemCode,
                    'uom' => $uom,
                    'quantity' => $quantity,
                    'cost' => is_numeric($cost) ? round((float) $cost, 4) : 0,
                ];
            }
        }

        return [$problems ? [] : $lines, $problems];
    }

    /**
     * The units each item may be bought in: its units in the catalogues CPO consolidates and
     * its SAP units. Upper-cased.
     *
     * @return array<string, list<string>>
     */
    public function unitsByItem(Collection $itemCodes): array
    {
        if ($itemCodes->isEmpty()) {
            return [];
        }

        $units = [];

        foreach ($itemCodes->chunk(500) as $chunk) {
            SupplierItems::whereIn('SupplierCode', SupplierItems::catalogueCodesFor(Supplier::CPO))
                ->where('is_active', true)
                ->whereIn('ItemCode', $chunk->all())
                ->get(['ItemCode', 'uom'])
                ->each(function ($item) use (&$units) {
                    $units[$item->ItemCode][] = strtoupper(trim((string) $item->uom));
                });
        }

        // SAP units count only for items that are in the catalogue.
        foreach (collect(array_keys($units))->chunk(500) as $chunk) {
            SAPMasterfile::whereIn('ItemCode', $chunk->all())
                ->get(['ItemCode', 'AltUOM'])
                ->each(function ($row) use (&$units) {
                    $units[$row->ItemCode][] = strtoupper(trim((string) $row->AltUOM));
                });
        }

        return array_map(fn ($list) => array_values(array_unique(array_filter($list))), $units);
    }

    /** Brand codes of store columns holding at least one quantity, keyed by brand code. */
    private function storesWithQuantities(Collection $rows, array $storeColumns): array
    {
        $found = [];
        foreach ($storeColumns as $header => $brandCode) {
            if ($rows->contains(fn ($row) => is_numeric($row[$header] ?? null) && (float) $row[$header] > 0)) {
                $found[$brandCode] = true;
            }
        }

        return $found;
    }

    private function result(bool $success, string $message, array $skipped, int $created): array
    {
        return [
            'success' => $success,
            'message' => $message,
            'skipped_stores' => collect($skipped)->map(fn ($reason, $brandCode) => ['brand_code' => $brandCode, 'reason' => $reason])->values()->all(),
            'created_count' => $created,
        ];
    }

    /** @throws ValidationException */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['mass_order_file' => $message]);
    }
}
