<?php

namespace App\Http\Services;

use App\Enums\WastageStatus;
use App\Models\MonthEndSchedule;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The transactions behind one figure of the Inventory Movement Report, each with the
 * reference that opens it.
 *
 * Every query here is the row-by-row twin of the one InventoryMovementService sums for
 * the same column - same source, same filters, same unit conversion - so the lines of a
 * popup add up to the figure that was clicked. Change one, change the other.
 */
class InventoryMovementDetailService
{
    /** The clickable columns, by the key the page sends. */
    public const METRICS = ['ordered', 'committed', 'received', 'beg_bal', 'sales', 'wastage', 'supplies', 'interco_in', 'interco_out', 'interco_pending'];

    public const PER_PAGE = 25;

    /**
     * Each column as the page heads it, and the date its lines are filed under. The twin of
     * detailColumns in resources/js/Pages/Reports/InventoryMovementReport/Index.vue, for the
     * Excel export of the popup.
     */
    public const LABELS = [
        'ordered' => ['Ordered', 'Order Date'],
        'committed' => ['Committed', 'Order Date'],
        'received' => ['Received', 'Order Date'],
        'beg_bal' => ['Beg Bal Qty', 'MEC Date'],
        'sales' => ['Sales Qty', 'Sales Date'],
        'wastage' => ['Wastage Qty', 'Date Filed'],
        'supplies' => ['Supplies Used', 'MEC Date'],
        'interco_in' => ['Inbound Interco', 'Order Date'],
        'interco_out' => ['Outbound Interco', 'Order Date'],
        'interco_pending' => ['Pending Interco Transactions', 'Order Date'],
    ];

    public function __construct(private InventoryMovementService $movement) {}

    /**
     * @param  Collection  $sapRows  the item's active sap_masterfiles rows, as the report reads them
     * @param  array{date_from: string, date_to: string}  $filters
     * @param  int|null  $perPage  lines per page; null for every line at once, as the Excel export of the popup needs
     * @return array{uom: string, total: float, total_rows: int, page: int, per_page: ?int, rows: array, unconverted_units: array, calculation: array, note: ?string}
     */
    public function details(Collection $sapRows, int $branchId, array $filters, string $metric, int $page = 1, ?int $perPage = self::PER_PAGE): array
    {
        [$displayUnit, $unitSizes, $blankUnit] = $this->movement->itemUnits($sapRows);
        $itemCode = (string) $sapRows->first()->ItemCode;
        $size = fn ($unit) => $unitSizes[$unit === '' ? $blankUnit : $unit] ?? null;
        $result = [
            'uom' => $displayUnit, 'total' => 0.0, 'total_rows' => 0, 'page' => $page, 'per_page' => $perPage,
            'rows' => [], 'unconverted_units' => [], 'calculation' => [], 'note' => null,
        ];

        if ($metric === 'supplies') {
            return $this->supplies($sapRows, $branchId, $filters, $result);
        }

        $base = $this->source($metric, $itemCode, $branchId, $filters);

        if ($base === null) {
            return $result;
        }

        // The figure itself: summed per unit and converted, exactly as the report does.
        foreach (DB::query()->fromSub($base, 'd')->groupBy('unit')->selectRaw('unit, SUM(qty) as qty, COUNT(*) as lines')->get() as $unitTotal) {
            $result['total_rows'] += (int) $unitTotal->lines;

            if ($size($unitTotal->unit) === null) {
                $result['unconverted_units'][] = $unitTotal->unit === '' ? '(blank)' : $unitTotal->unit;
                continue;
            }

            $result['total'] += (float) $unitTotal->qty * $size($unitTotal->unit);
        }

        $result['total'] = round($result['total'], 6);

        if ($metric === 'interco_pending') {
            $result['note'] = 'Interco transfers approved for this period that are not in stock yet: + coming into this store, - going out of it. '
                .'They are not part of Theoretical SOH, Variance or Final Variance. Each moves into Inbound or Outbound Interco once it is received.';
        }

        $labels = $sapRows->flatMap(fn ($row) => [$row->AltUOM, $row->BaseUOM])
            ->filter()->mapWithKeys(fn ($label) => [strtoupper(trim((string) $label)) => trim((string) $label)]);

        $result['rows'] = DB::query()->fromSub($base, 'd')
            ->orderByDesc('tx_date')->orderByDesc('ref_no')->orderBy('unit')
            ->when($perPage !== null, fn ($query) => $query->forPage($page, $perPage))
            ->get()
            ->map(fn ($row) => [
                'date' => $row->tx_date ? Carbon::parse($row->tx_date)->format('M j, Y') : null,
                'ref_no' => $row->ref_no,
                'ref_url' => $this->url($metric, $row, $branchId),
                'details' => $this->describe($metric, $row),
                'quantity' => (float) $row->qty,
                'uom' => $labels->get($row->unit, $row->unit),
                // In the report's unit; null when the unit has no conversion and is left out of the figure.
                'converted' => $size($row->unit) === null ? null : round((float) $row->qty * $size($row->unit), 6),
            ])
            ->all();

        return $result;
    }

    /**
     * One row per source line: tx_date, ref_no, ref_id (what the link needs), d1 and d2
     * (what describe() words), unit (UPPER, as the report keys it) and qty.
     */
    private function source(string $metric, string $itemCode, int $branchId, array $filters): ?Builder
    {
        $from = $filters['date_from'];
        $to = $filters['date_to'];
        $unit = fn (string $column) => "UPPER(LTRIM(RTRIM(COALESCE({$column}, ''))))";

        $procurement = fn (string $branchColumn) => DB::table('store_order_items as soi')
            ->join('store_orders as so', 'soi.store_order_id', '=', 'so.id')
            ->where('soi.item_code', $itemCode)
            ->where($branchColumn, $branchId)
            ->whereBetween('so.order_date', [$from, $to]);

        $columns = fn (string $refNo, string $refId, string $d1, string $d2, string $unitColumn, string $qty) =>
            // kind and batch tell which ordering page the order belongs to (see orderUrl()).
            "so.order_date as tx_date, {$refNo} as ref_no, {$refId} as ref_id, {$d1} as d1, {$d2} as d2, {$unit($unitColumn)} as unit, {$qty} as qty, so.variant as kind, so.batch_reference as batch";

        return match ($metric) {
            'ordered' => $procurement('so.store_branch_id')
                ->leftJoin('suppliers as sup', 'sup.id', '=', 'so.supplier_id')
                ->whereNull('so.interco_number')
                ->whereRaw('COALESCE(soi.quantity_approved, 0) <> 0')
                ->selectRaw($columns('so.order_number', 'so.order_number', 'sup.name', 'so.order_status', 'soi.uom', 'COALESCE(soi.quantity_approved, 0)')),

            'committed' => $procurement('so.store_branch_id')
                ->leftJoin('suppliers as sup', 'sup.id', '=', 'so.supplier_id')
                ->whereNull('so.interco_number')
                ->where(function ($query) {
                    $query->whereNotNull('soi.committed_by')
                        ->orWhereIn('so.order_status', OrderReceivingService::RECEIVING_STATUSES);
                })
                ->whereRaw('COALESCE(soi.quantity_commited, 0) <> 0')
                ->selectRaw($columns('so.order_number', 'so.order_number', 'sup.name', 'so.order_status', 'soi.uom', 'COALESCE(soi.quantity_commited, 0)')),

            'received' => $procurement('so.store_branch_id')
                ->join('ordered_item_receive_dates as oird', 'oird.store_order_item_id', '=', 'soi.id')
                ->leftJoin('suppliers as sup', 'sup.id', '=', 'so.supplier_id')
                ->whereNull('so.interco_number')
                ->where('oird.status', 'approved')
                ->whereRaw('COALESCE(oird.quantity_received, 0) <> 0')
                ->selectRaw($columns('so.order_number', 'so.order_number', 'sup.name', 'CONVERT(varchar(10), oird.received_date, 23)', 'soi.uom', 'COALESCE(oird.quantity_received, 0)')),

            'interco_in' => $procurement('so.store_branch_id')
                ->join('ordered_item_receive_dates as oird', 'oird.store_order_item_id', '=', 'soi.id')
                ->leftJoin('store_branches as other', 'other.id', '=', 'so.sending_store_branch_id')
                ->whereNotNull('so.interco_number')
                ->where('oird.status', 'approved')
                ->whereRaw('COALESCE(oird.quantity_received, 0) <> 0')
                ->selectRaw($columns('so.interco_number', 'so.interco_number', 'other.name', 'CONVERT(varchar(10), oird.received_date, 23)', 'soi.uom', 'COALESCE(oird.quantity_received, 0)')),

            'interco_out' => $procurement('so.sending_store_branch_id')
                ->join('ordered_item_receive_dates as oird', 'oird.store_order_item_id', '=', 'soi.id')
                ->leftJoin('store_branches as other', 'other.id', '=', 'so.store_branch_id')
                ->whereNotNull('so.interco_number')
                ->where('oird.status', 'approved')
                ->whereRaw('COALESCE(oird.quantity_received, 0) <> 0')
                ->selectRaw($columns('so.interco_number', 'CAST(so.id AS varchar(20))', 'other.name', 'CONVERT(varchar(10), oird.received_date, 23)', 'soi.uom', 'COALESCE(oird.quantity_received, 0)')),

            // One line per receipt and product; the BOM is matched within the product's entity.
            'sales' => DB::table('store_transaction_items as sti')
                ->join('store_transactions as st', 'sti.store_transaction_id', '=', 'st.id')
                ->join('pos_masterfiles as pm', 'sti.product_id', '=', 'pm.id')
                ->join('pos_masterfiles_bom as bom', function ($join) {
                    $join->on('pm.POSCode', '=', 'bom.POSCode')
                        ->where(function ($entity) {
                            $entity->whereColumn('pm.entity_id', 'bom.entity_id')
                                ->orWhere(fn ($legacy) => $legacy->whereNull('pm.entity_id')->whereNull('bom.entity_id'));
                        });
                })
                ->where('st.store_branch_id', $branchId)
                ->whereBetween('st.order_date', [$from, $to])
                ->where('bom.ItemCode', $itemCode)
                ->groupBy('st.id', 'st.order_date', 'st.receipt_number', 'pm.POSCode', 'pm.POSDescription', DB::raw($unit('bom.BOMUOM')))
                ->selectRaw("st.order_date as tx_date, st.receipt_number as ref_no, CAST(st.id AS varchar(20)) as ref_id, pm.POSCode as d1, pm.POSDescription as d2, {$unit('bom.BOMUOM')} as unit, SUM(COALESCE(sti.quantity, 0) * COALESCE(bom.BOMQty, 0)) as qty"),

            // Filed for the item itself, then what a wasted Sub-Prep used of it through its BOM.
            'wastage' => DB::table('wastages as w')
                ->join('sap_masterfiles as sap', 'w.sap_masterfile_id', '=', 'sap.id')
                ->where('w.store_branch_id', $branchId)
                ->where('w.wastage_status', WastageStatus::APPROVED_LVL2->value)
                ->where('sap.ItemCode', $itemCode)
                ->whereBetween('w.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->selectRaw("CONVERT(date, w.created_at) as tx_date, w.wastage_no as ref_no, w.wastage_no as ref_id, w.reason as d1, CAST(NULL AS nvarchar(255)) as d2, {$unit('sap.AltUOM')} as unit, COALESCE(w.approverlvl2_qty, 0) as qty")
                ->unionAll(DB::table('wastages as w')
                    ->join('pos_masterfiles as pm', 'w.pos_masterfile_id', '=', 'pm.id')
                    ->join('pos_masterfiles_bom as bom', function ($join) {
                        $join->on('pm.POSCode', '=', 'bom.POSCode')
                            ->where(function ($entity) {
                                $entity->whereColumn('pm.entity_id', 'bom.entity_id')
                                    ->orWhere(fn ($legacy) => $legacy->whereNull('pm.entity_id')->whereNull('bom.entity_id'));
                            });
                    })
                    ->whereNull('w.sap_masterfile_id')
                    ->where('w.store_branch_id', $branchId)
                    ->where('w.wastage_status', WastageStatus::APPROVED_LVL2->value)
                    ->where('bom.ItemCode', $itemCode)
                    ->whereBetween('w.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                    ->selectRaw("CONVERT(date, w.created_at) as tx_date, w.wastage_no as ref_no, w.wastage_no as ref_id, w.reason as d1, CAST(pm.POSCode + ' ' + COALESCE(pm.POSDescription, '') AS nvarchar(255)) as d2, {$unit('bom.BOMUOM')} as unit, COALESCE(w.approverlvl2_qty, 0) * COALESCE(bom.BOMQty, 0) as qty")),

            'beg_bal' => $this->count($this->schedule(Carbon::parse($from)->subMonth()), $itemCode, $branchId, $unit),

            'interco_pending' => $this->pendingInterco($procurement, $unit),

            default => null,
        };
    }

    /**
     * Pending Interco, line by line: the three sums InventoryMovementService adds up. Coming in,
     * one row per receipt recorded (or the line at the quantity sent while it has none); going
     * out, the line at the quantity sent, as a minus. kind says which way, for the link.
     */
    private function pendingInterco(\Closure $procurement, \Closure $unit): Builder
    {
        $pendingLine = fn ($query) => $query
            ->whereNotNull('so.interco_number')
            ->whereIn('so.interco_status', InventoryMovementService::PENDING_INTERCO_STATUSES)
            ->whereNotExists(fn ($posted) => $posted->select(DB::raw(1))
                ->from('ordered_item_receive_dates as posted')
                ->whereColumn('posted.store_order_item_id', 'soi.id')
                ->where('posted.status', 'approved'));
        $columns = fn (string $refId, string $state, string $qty, string $direction) =>
            "so.order_date as tx_date, so.interco_number as ref_no, {$refId} as ref_id, other.name as d1, CAST({$state} AS nvarchar(255)) as d2, {$unit('soi.uom')} as unit, {$qty} as qty, CAST('{$direction}' AS nvarchar(255)) as kind, CAST(NULL AS nvarchar(255)) as batch";

        $recorded = $procurement('so.store_branch_id')
            ->tap($pendingLine)
            ->join('ordered_item_receive_dates as oird', 'oird.store_order_item_id', '=', 'soi.id')
            ->leftJoin('store_branches as other', 'other.id', '=', 'so.sending_store_branch_id')
            ->selectRaw($columns('so.interco_number', "CASE oird.status WHEN 'pending' THEN 'to_receive' WHEN 'for_confirmation' THEN 'for_confirmation' ELSE 'recorded' END", 'COALESCE(oird.quantity_received, 0)', 'in'));

        $notOpened = $procurement('so.store_branch_id')
            ->tap($pendingLine)
            ->whereNotExists(fn ($rows) => $rows->select(DB::raw(1))
                ->from('ordered_item_receive_dates as opened')
                ->whereColumn('opened.store_order_item_id', 'soi.id'))
            ->leftJoin('store_branches as other', 'other.id', '=', 'so.sending_store_branch_id')
            ->selectRaw($columns('so.interco_number', "'to_receive'", 'COALESCE(soi.quantity_commited, 0)', 'in'));

        $outgoing = $procurement('so.sending_store_branch_id')
            ->tap($pendingLine)
            ->leftJoin('store_branches as other', 'other.id', '=', 'so.store_branch_id')
            ->selectRaw($columns(
                'CAST(so.id AS varchar(20))',
                "CASE WHEN EXISTS (SELECT 1 FROM ordered_item_receive_dates waiting WHERE waiting.store_order_item_id = soi.id AND waiting.status = 'for_confirmation') THEN 'for_confirmation' ELSE 'to_receive' END",
                '-COALESCE(soi.quantity_commited, 0)',
                'out'
            ));

        return $recorded->unionAll($notOpened)->unionAll($outgoing);
    }

    /** The lines of one month end count for the item; a rejected count went back to the store and is not one. */
    private function count(?MonthEndSchedule $schedule, string $itemCode, int $branchId, \Closure $unit): ?Builder
    {
        if (! $schedule) {
            return null;
        }

        return DB::table('month_end_count_items as meci')
            ->join('sap_masterfiles as sap', 'meci.sap_masterfile_id', '=', 'sap.id')
            ->where('meci.month_end_schedule_id', $schedule->id)
            ->where('meci.branch_id', $branchId)
            ->where('meci.status', '!=', 'rejected')
            ->where('sap.ItemCode', $itemCode)
            ->selectRaw(
                "CAST(? AS date) as tx_date, ? as ref_no, CAST(? AS varchar(20)) as ref_id, meci.status as d1, CAST(NULL AS nvarchar(255)) as d2, {$unit("NULLIF(meci.uom, ''), sap.AltUOM")} as unit, COALESCE(meci.total_qty, 0) as qty",
                [$schedule->calculated_date->toDateString(), $this->countName($schedule), $schedule->id]
            );
    }

    /**
     * Supplies Used has no transaction: it is the book balance the month end count did not
     * find. The popup shows that sum, and the count is its reference.
     */
    private function supplies(Collection $sapRows, int $branchId, array $filters, array $result): array
    {
        $row = collect($this->movement->movementData($sapRows, $filters + ['branch_id' => $branchId]))->first();

        if (! $row || ! $row['supplies_type']) {
            $result['note'] = 'Supplies Used applies only to Operating Supplies, Cleaning Supplies and items of the Supplies category.';

            return $result;
        }

        if (! $row['supplies_counted']) {
            $result['note'] = 'This item has not been counted yet for the period. Supplies Used appears once its month end count is in.';

            return $result;
        }

        $book = round($row['beg_bal_qty'] + $row['received_qty'] + $row['interco_in_qty'] - $row['sales_qty'] - $row['wastage_qty'] - $row['interco_out_qty'], 6);
        $schedule = $this->schedule(Carbon::parse($filters['date_to']));

        $result['calculation'] = [
            ['label' => 'Beg Bal Qty', 'sign' => '', 'value' => $row['beg_bal_qty']],
            ['label' => 'Received', 'sign' => '+', 'value' => $row['received_qty']],
            ['label' => 'Inbound Interco', 'sign' => '+', 'value' => $row['interco_in_qty']],
            ['label' => 'Sales Qty', 'sign' => '-', 'value' => $row['sales_qty']],
            ['label' => 'Wastage Qty', 'sign' => '-', 'value' => $row['wastage_qty']],
            ['label' => 'Outbound Interco', 'sign' => '-', 'value' => $row['interco_out_qty']],
            ['label' => 'Stock the books expect', 'sign' => '=', 'value' => $book],
            ['label' => 'Actual MEC', 'sign' => '-', 'value' => $row['actual_mec']],
            ['label' => 'Supplies Used', 'sign' => '=', 'value' => $row['supplies_qty']],
        ];
        $result['note'] = 'Supplies are used up without a transaction, so this is the stock the count did not find. A count above the books is a gain, not usage, and leaves Supplies Used at 0.';
        $result['total'] = (float) $row['supplies_qty'];

        if ($schedule && $row['supplies_qty'] > 0) {
            $result['total_rows'] = 1;
            $result['rows'][] = [
                'date' => $schedule->calculated_date->format('M j, Y'),
                'ref_no' => $this->countName($schedule),
                'ref_url' => route('month-end-count-approvals.show', [$schedule->id, $branchId]),
                'details' => 'Not found by the month end count',
                'quantity' => (float) $row['supplies_qty'],
                'uom' => $result['uom'],
                'converted' => (float) $row['supplies_qty'],
            ];
        }

        return $result;
    }

    /** The count of a calendar month, as the report picks it for Beg Bal (the month before) and Actual MEC. */
    private function schedule(Carbon $month): ?MonthEndSchedule
    {
        return MonthEndSchedule::where('year', $month->year)->where('month', $month->month)->first();
    }

    private function countName(MonthEndSchedule $schedule): string
    {
        return 'MEC ' . Carbon::create($schedule->year, $schedule->month, 1)->format('F Y');
    }

    /** Where the Ref No. opens: the page of that order, transfer, receipt, wastage record or count. */
    private function url(string $metric, object $row, int $branchId): ?string
    {
        if ($row->ref_id === null || $row->ref_id === '') {
            return null;
        }

        return match ($metric) {
            'ordered', 'committed' => $this->orderUrl($row),
            'received' => route('orders-receiving.show', $row->ref_id),
            'interco_in' => route('interco-receiving.show', $row->ref_id),
            'interco_out' => route('interco.show', $row->ref_id),
            // Coming in opens where this store receives it; going out, the transfer it sent.
            'interco_pending' => $row->kind === 'in' ? route('interco-receiving.show', $row->ref_id) : route('interco.show', $row->ref_id),
            'sales' => route('store-transactions.show', $row->ref_id),
            'wastage' => route('wastage.show.by-number', $row->ref_id),
            'beg_bal' => route('month-end-count-approvals.show', [$row->ref_id, $branchId]),
            default => null,
        };
    }

    /**
     * An order opens on the page it was placed from: a DTS mass order by its batch, a mass
     * order by its number, anything else on Store Orders.
     */
    private function orderUrl(object $row): string
    {
        $kind = strtolower((string) ($row->kind ?? ''));

        if (str_contains($kind, 'dts') && filled($row->batch ?? null)) {
            return route('dts-mass-orders.show', $row->batch);
        }

        return str_contains($kind, 'mass')
            ? route('mass-orders.show', $row->ref_id)
            : route('store-orders.show', $row->ref_id);
    }

    private function describe(string $metric, object $row): string
    {
        $status = fn ($value) => ucwords(str_replace('_', ' ', strtolower((string) $value)));
        $day = fn ($value) => $value ? Carbon::parse($value)->format('M j, Y') : null;

        return match ($metric) {
            'ordered', 'committed' => trim(($row->d1 ?: 'Supplier not set') . ' · ' . $status($row->d2), ' ·'),
            'received' => trim(($row->d1 ?: 'Supplier not set') . ($day($row->d2) ? ' · received ' . $day($row->d2) : ''), ' ·'),
            'interco_in' => 'From ' . ($row->d1 ?: 'another store') . ($day($row->d2) ? ' · received ' . $day($row->d2) : ''),
            'interco_out' => 'To ' . ($row->d1 ?: 'another store') . ($day($row->d2) ? ' · received ' . $day($row->d2) : ''),
            'interco_pending' => $row->kind === 'in'
                ? 'From ' . ($row->d1 ?: 'another store') . ' · ' . match ($row->d2) {
                    'for_confirmation' => 'received, waiting for the sending store to confirm',
                    'recorded' => 'received, not in stock yet',
                    default => 'not received yet',
                }
                : 'To ' . ($row->d1 ?: 'another store') . ' · ' . ($row->d2 === 'for_confirmation' ? 'waiting for your confirmation' : 'not received yet'),
            // The product by its name, its POS Code after it: a bare "5 Spanish Latte" reads as a quantity.
            'sales' => trim((string) $row->d2) !== '' ? trim($row->d2) . ' (' . $row->d1 . ')' : (string) $row->d1,
            'wastage' => $row->d2 ? 'Sub-Prep ' . trim($row->d2) . ' · ' . $row->d1 : (string) $row->d1,
            'beg_bal' => 'Month end count · ' . $status($row->d1),
            default => '',
        };
    }
}
