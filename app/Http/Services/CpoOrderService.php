<?php

namespace App\Http\Services;

use App\Enum\OrderRequestStatus;
use App\Enum\OrderStatus;
use App\Models\CpoOrderAttachment;
use App\Models\CpoVendor;
use App\Models\SAPMasterfile;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\SupplierItems;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CPO Orders: a store's purchase from an outside vendor, placed through the mass order
 * template (one column per store) with the vendor and its invoice / receipt attached.
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

    public function __construct(
        private StoreOrderService $storeOrders,
        private MonthEndClosedPeriodService $closedPeriods,
    ) {}

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
     * @param  UploadedFile[]  $attachments  the vendor's invoice / receipt, linked to every order created
     * @return array{success: bool, message: string, skipped_stores: array, created_count: int}
     *
     * @throws ValidationException when the file is not a filled CPO template, a line misses
     *                             its item, unit or cost, or no store in it may order
     */
    public function processUpload(Collection $rows, string $orderDate, CpoVendor $vendor, array $attachments, User $user): array
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

        // The files are stored once and linked to every order the upload creates.
        $stored = array_map(fn (UploadedFile $file) => [
            'file_path' => $file->store(CpoOrderAttachment::DIRECTORY, CpoOrderAttachment::DISK),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $user->id,
        ], $attachments);

        $supplier = Supplier::where('supplier_code', Supplier::CPO)->firstOrFail();
        $created = 0;

        DB::transaction(function () use ($linesByStore, $usable, $supplier, $vendor, $orderDate, $stored, $user, &$skipped, &$created) {
            foreach ($linesByStore as $header => $lines) {
                $store = $usable[$header];

                // One purchase per store, date and vendor; a rejected one may be placed again.
                $existing = StoreOrder::where('store_branch_id', $store->id)
                    ->where('supplier_id', $supplier->id)
                    ->where('variant', 'mass regular')
                    ->where('cpo_vendor_id', $vendor->id)
                    ->whereDate('order_date', $orderDate)
                    ->where('order_status', '!=', OrderStatus::REJECTED->value)
                    ->value('order_number');

                if ($existing) {
                    $skipped[$store->brand_code] = "A CPO order from {$vendor->name} for this date already exists ({$existing}).";
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
                    'cpo_vendor_id' => $vendor->id,
                    'cpo_vendor_name' => $vendor->name,
                    'cpo_vendor_tin' => $vendor->tin,
                    'cpo_vendor_address' => $vendor->address,
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

                foreach ($stored as $file) {
                    $order->cpoAttachments()->create($file);
                }

                $created++;
            }
        });

        $message = $created
            ? "Successfully created {$created} CPO order(s). They are now waiting in Mass Orders Approval."
            : 'No CPO order was created.';

        if ($skipped) {
            $message .= ' '.count($skipped).' store(s) were skipped.';
        }

        return $this->result($created > 0, $message, $skipped, $created);
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
    private function unitsByItem(Collection $itemCodes): array
    {
        if ($itemCodes->isEmpty()) {
            return [];
        }

        $units = [];

        foreach ($itemCodes->chunk(500) as $chunk) {
            SupplierItems::whereIn('SupplierCode', SupplierItems::CPO_CONSOLIDATED_SUPPLIER_CODES)
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
