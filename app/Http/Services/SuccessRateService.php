<?php

namespace App\Http\Services;

use App\Models\Entity;
use App\Models\SuccessRateWeeklyTicket;
use App\Models\User;
use App\Support\EntityContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Success Rate dashboard - a port of the
 * "David - Adoption Rate and Success Rate" workbook.
 *
 * The ONLY hand-keyed inputs are the helpdesk ticket counts: Incoming and Closed
 * per module per ISO week (the workbook's "c/o SO" columns). Transaction volume
 * is the workbook's "c/o BS" columns - supplied by the system, not typed in - so
 * it is read live from the Adoption Rate datasets here. Everything else is
 * derived:
 *
 *   Module Concerns    W = order+commit+receiving+wastage+mec+sales_upload incoming
 *   Technical Concerns X = admin incoming
 *   Total Tickets      Y = W + X
 *   Total Closed       Z = sum of all seven "closed" columns
 *   Total Open        AA = Y - Z
 *   Total Transactions AB = live transaction volume across the five modules below
 *   Success Rate      AC = 1 - (Y / AB)
 *   Close Rate        AD = Z / Y
 *   Adoption Rate     AE = per-week override, else the system adoption rate
 *
 * Running averages (row 21) are simple averages of the weeks that have a value.
 */
class SuccessRateService
{
    /**
     * Ticket module -> the Adoption Rate dataset that measures the same activity.
     *
     * Keys match AdoptionRateTrackingService::getIndicatorDatasets(). Each entry
     * names the row's date field (used to bucket into weeks) and the status
     * fields whose Yes/No values form that indicator's adoption denominator. A
     * week's transaction count is the number of rows in that denominator, so
     * Success Rate and Adoption Rate are measured over exactly the same
     * population.
     *
     * MEC is deliberately absent: it has no Adoption Rate indicator, matching the
     * workbook, whose MEC transactions column is empty for all 18 weeks.
     */
    private const TRANSACTION_SOURCES = [
        'order' => [
            'date' => 'david_delivery_date',
            'status' => ['plotted'],
        ],
        'commit' => [
            'date' => 'delivery_date',
            'status' => ['fg_on_time', 'traded_on_time'],
        ],
        'receiving' => [
            'date' => 'sap_dr_date',
            'status' => ['on_time'],
        ],
        'sales_upload' => [
            'date' => 'date_of_sales',
            'status' => ['sales_report_uploaded_on_time'],
        ],
        'wastage' => [
            'date' => 'date_of_wastage',
            'status' => ['wastage_report_uploaded'],
        ],
    ];

    public const PERIODS = ['week', 'month'];

    public function __construct(
        private AdoptionRateTrackingService $adoptionRateService,
        private HelpdeskTicketTallyClient $helpdesk,
    ) {}

    /**
     * Build the full Success Rate payload for a date range.
     *
     * Tickets are always counted and derived per week. The monthly view only
     * regroups those weekly rows (see buildMonthRows), and the running averages
     * in `totals` stay weekly so the headline figures never shift with the view.
     *
     * Incoming/Closed come from ghelpdesk when it is configured and reachable;
     * otherwise the last saved (or hand-encoded) counts are used.
     *
     * @param  array{date_from?:string|null,date_to?:string|null,store_ids?:array,period?:string|null,refresh?:bool}  $filters
     */
    public function getWeeklyTrend(array $filters, User $user): array
    {
        $period = in_array($filters['period'] ?? null, self::PERIODS, true) ? $filters['period'] : 'week';

        [$dateFrom, $dateTo] = $this->resolveDateRange($filters);

        $weeks = $this->buildWeekBuckets($dateFrom, $dateTo);
        $records = $this->recordsForWeeks($weeks);
        $derived = $this->derivedByWeekStart($filters, $user);

        [$live, $ticketSource] = $this->liveTicketCounts($weeks, (bool) ($filters['refresh'] ?? false));

        if ($live !== null) {
            $records = $this->persistLiveCounts($weeks, $live, $records);
        }

        $rows = collect($weeks)->map(function (array $week) use ($records, $derived, $live) {
            $start = $week['start_date'];

            return $this->buildRow(
                $week,
                $records->get($start),
                $derived['adoption'][$start] ?? null,
                $derived['transactions'][$start] ?? [],
                $live === null ? null : ($live[$start] ?? [])
            );
        })->values();

        return [
            'rows' => ($period === 'month' ? $this->buildMonthRows($rows) : $rows)->all(),
            'totals' => $this->buildTotals($rows),
            'modules' => $this->moduleDefinitions(),
            'ticket_source' => $ticketSource,
            'filters' => [
                'period' => $period,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'store_ids' => array_values(array_filter(
                    (array) ($filters['store_ids'] ?? []),
                    fn ($id) => is_numeric($id)
                )),
            ],
        ];
    }

    /**
     * Create or update one week's keyed-in ticket counts.
     * The week is normalised to its Monday so a row can never be duplicated by
     * a caller passing a mid-week date.
     */
    public function saveWeek(array $data, User $user): SuccessRateWeeklyTicket
    {
        $weekStart = Carbon::parse($data['week_start'])->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $attributes = [
            'week_end' => $weekEnd->toDateString(),
            'iso_year' => (int) $weekStart->isoWeekYear,
            'week_no' => (int) $weekStart->isoWeek,
            'adoption_rate_override' => $this->normalizeRateInput($data['adoption_rate_override'] ?? null),
            'remarks' => $data['remarks'] ?? null,
            'updated_by' => $user->id,
        ];

        foreach (SuccessRateWeeklyTicket::countColumns() as $column) {
            $attributes[$column] = max(0, (int) ($data[$column] ?? 0));
        }

        return SuccessRateWeeklyTicket::updateOrCreate(
            ['week_start' => $weekStart->toDateString()],
            $attributes
        );
    }

    /**
     * Accepts either a percentage (0-100) or a fraction (0-1) and stores a
     * percentage, so "0.61" and "61" both mean 61%.
     */
    private function normalizeRateInput($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (float) $value;

        if ($value <= 1 && $value > 0) {
            $value *= 100;
        }

        return round(max(0, min(100, $value)), 2);
    }

    /**
     * Fetch this entity's weekly counts from ghelpdesk.
     *
     * @return array{0: array<string, array<string, int>>|null, 1: array}
     *         [counts keyed by week_start or null when unavailable, source metadata for the UI]
     */
    private function liveTicketCounts(array $weeks, bool $refresh): array
    {
        $entity = ($entityId = app(EntityContext::class)->id()) ? Entity::find($entityId) : null;

        $source = [
            'mode' => 'manual',
            'configured' => $this->helpdesk->isConfigured(),
            'entity_code' => $entity?->code,
            'error' => null,
        ];

        if (! $source['configured'] || $weeks === []) {
            return [null, $source];
        }

        if (! $entity?->code) {
            $source['error'] = 'No active entity to match against Helpdesk.';

            return [null, $source];
        }

        $counts = $this->helpdesk->weeklyCounts(
            $entity->code,
            Carbon::parse($weeks[0]['start_date']),
            Carbon::parse(end($weeks)['end_date']),
            $refresh
        );

        if ($counts === null) {
            $source['error'] = $this->helpdesk->lastError() ?? 'Helpdesk is unavailable.';

            return [null, $source];
        }

        $source['mode'] = 'helpdesk';

        return [$counts, $source];
    }

    /**
     * Save live counts onto the weekly rows, so the tab still shows the last
     * known numbers if ghelpdesk is later unreachable. Only writes when a count
     * actually changed, and never creates a row for an all-zero week. Override
     * and remarks are left untouched.
     *
     * @param  Collection<string, SuccessRateWeeklyTicket>  $records
     * @return Collection<string, SuccessRateWeeklyTicket>
     */
    private function persistLiveCounts(array $weeks, array $live, Collection $records): Collection
    {
        foreach ($weeks as $week) {
            $start = $week['start_date'];
            $counts = [];

            foreach (SuccessRateWeeklyTicket::countColumns() as $column) {
                $counts[$column] = (int) ($live[$start][$column] ?? 0);
            }

            $record = $records->get($start);

            if (! $record && array_sum($counts) === 0) {
                continue;
            }

            try {
                if ($record) {
                    $record->fill($counts);

                    if ($record->isDirty()) {
                        $record->save();
                    }

                    continue;
                }

                $records->put($start, SuccessRateWeeklyTicket::create(array_merge($counts, [
                    'week_start' => $start,
                    'week_end' => $week['end_date'],
                    'iso_year' => $week['iso_year'],
                    'week_no' => $week['week_no'],
                ])));
            } catch (\Throwable $e) {
                // A concurrent load may have created the same week first; the
                // live counts are still shown, so a lost snapshot write is harmless.
                report($e);
            }
        }

        return $records;
    }

    /** @return Collection<string, SuccessRateWeeklyTicket> keyed by week_start */
    private function recordsForWeeks(array $weeks): Collection
    {
        if ($weeks === []) {
            return collect();
        }

        $starts = collect($weeks)->pluck('start_date')->all();

        return SuccessRateWeeklyTicket::whereIn('week_start', $starts)
            ->get()
            ->keyBy(fn (SuccessRateWeeklyTicket $row) => $row->week_start->toDateString());
    }

    /**
     * Everything this tab reads from live system activity: the per-week, per-module
     * transaction volume and the per-week adoption rate.
     *
     * Both come out of the same five Adoption Rate datasets, so they are computed
     * and cached together — fetching them twice would double the cost of the most
     * expensive query on the page.
     *
     * Cached separately from the ticket counts on purpose. The trend fans out
     * across five datasets and a per-store weekly loop and can take tens of
     * seconds; when it shared a cache entry with the ticket figures, every encoded
     * week invalidated it and the save appeared to hang while it all recomputed.
     * Keyed by user + active entity + accessible stores so store-assignment
     * changes and entity switches never serve another context's numbers.
     *
     * @return array{adoption: array<string, float>, transactions: array<string, array<string, int>>}
     */
    private function derivedByWeekStart(array $filters, User $user): array
    {
        $scoped = [
            'store_ids' => $filters['store_ids'] ?? [],
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
        ];

        $cacheKey = 'success_rate_derived_v3_'
            .$user->id.'_'
            .(app(EntityContext::class)->id() ?? 'none').'_'
            .md5(json_encode([$scoped, $this->adoptionRateService->accessibleStoreIds($user)]));

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($scoped, $user) {
            return [
                'adoption' => $this->computeAdoptionByWeekStart($scoped, $user),
                'transactions' => $this->computeTransactionsByWeekStart($scoped, $user),
            ];
        });
    }

    /**
     * Per-week, per-module transaction volume, keyed by week start.
     *
     * A module's transactions for a week are the rows in that week which count
     * toward its Adoption Rate denominator — rows whose status is a real Yes/No.
     * Rows the report marks N/A (CPO / automated orders, for instance) are not
     * transactions anyone could have raised a ticket about, so counting them
     * would understate the success rate.
     *
     * @return array<string, array<string, int>>
     */
    private function computeTransactionsByWeekStart(array $filters, User $user): array
    {
        try {
            // Memoised in AdoptionRateTrackingService, so this shares one pass
            // with the adoption rate instead of building all five datasets twice.
            $datasets = $this->adoptionRateService->getIndicatorDatasets($filters, $user);
        } catch (\Throwable $e) {
            // An unavailable dataset must not blank out the whole tab; the weeks
            // simply carry no transactions for the range.
            report($e);

            return [];
        }

        $result = [];

        foreach (self::TRANSACTION_SOURCES as $module => $source) {
            foreach ($datasets[$module]['rows'] ?? [] as $row) {
                if (! $this->countsAsTransaction($row, $source['status'])) {
                    continue;
                }

                $weekStart = $this->weekStartFor($row[$source['date']] ?? null);

                if ($weekStart === null) {
                    continue;
                }

                $result[$weekStart][$module] = ($result[$weekStart][$module] ?? 0) + 1;
            }
        }

        return $result;
    }

    /**
     * A row is one transaction when any of its status fields carries a real
     * Yes/No verdict. Commit rows have two (finished goods and traded), and a row
     * with either one still represents a single committing transaction — hence
     * "any", not a count per field.
     */
    private function countsAsTransaction(array $row, array $statusFields): bool
    {
        foreach ($statusFields as $field) {
            // Excused rows left the adoption denominator but were still real activity.
            if (in_array($row[$field] ?? null, ['Yes', 'No', AdoptionRateTrackingService::EXCUSED], true)) {
                return true;
            }
        }

        return false;
    }

    private function weekStartFor($date): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            return Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Weekly system adoption rate (percent) keyed by week start, used whenever a
     * week has no manual override. Reuses the Adoption Rate tab's own trend so
     * both dashboard tabs always agree.
     *
     * @return array<string, float>
     */
    private function computeAdoptionByWeekStart(array $filters, User $user): array
    {
        try {
            $trend = $this->adoptionRateService->getWeeklyAdoptionTrend(array_merge($filters, [
                'tab' => AdoptionRateTrackingService::TAB_OVERALL_ADOPTION_RATE,
            ]), $user);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $overall = collect($trend['combined_weekly'] ?? [])->firstWhere('label', 'Overall');

        if (! $overall) {
            return [];
        }

        $result = [];

        foreach (($trend['weeks'] ?? []) as $index => $week) {
            // Week keys are "start|end"; align on the start date so the two tabs
            // bucket weeks identically even when the range clips a partial week.
            $parts = explode('|', (string) ($week['key'] ?? ''));
            $value = $overall['data'][$index] ?? null;

            if (! empty($parts[0]) && $value !== null) {
                // The Adoption Rate report clips the first/last bucket to the
                // filter edges; normalise back to the Monday this tab keys on.
                $start = $this->weekStartFor($parts[0]);

                if ($start !== null) {
                    $result[$start] = round((float) $value, 2);
                }
            }
        }

        return $result;
    }

    /**
     * Derive one table row from a week bucket, its (possibly missing) ticket
     * record and the live transaction counts for that week.
     *
     * $liveCounts (from ghelpdesk) wins over the record's counts when given; a
     * week with live counts always has a real tally, even if every count is zero.
     */
    private function buildRow(
        array $week,
        ?SuccessRateWeeklyTicket $record,
        ?float $systemAdoption,
        array $transactions,
        ?array $liveCounts = null
    ): array {
        $counts = [];

        foreach (SuccessRateWeeklyTicket::countColumns() as $column) {
            $counts[$column] = $liveCounts !== null
                ? (int) ($liveCounts[$column] ?? 0)
                : (int) ($record->{$column} ?? 0);
        }

        $hasTally = $record !== null || $liveCounts !== null;

        $moduleKeys = array_keys(SuccessRateWeeklyTicket::MODULES);

        $moduleConcerns = array_sum(array_map(fn ($m) => $counts[$m.'_incoming'], $moduleKeys));
        $technicalConcerns = $counts['admin_incoming'];
        $totalTickets = $moduleConcerns + $technicalConcerns;

        $totalClosed = array_sum(array_map(fn ($m) => $counts[$m.'_closed'], $moduleKeys))
            + $counts['admin_closed'];

        // Per-module transaction volume, exposed so the tab can show where the
        // denominator came from instead of presenting one unexplained number.
        // Only modules with an Adoption Rate indicator can contribute: a stray
        // key for one without (MEC) must never reach the denominator, matching
        // the workbook's empty MEC transactions column.
        $moduleTransactions = [];

        foreach ($moduleKeys as $module) {
            $moduleTransactions[$module.'_transactions'] = array_key_exists($module, self::TRANSACTION_SOURCES)
                ? (int) ($transactions[$module] ?? 0)
                : 0;
        }

        $totalTransactions = array_sum($moduleTransactions);

        $override = $record?->adoption_rate_override;
        $adoptionRate = $override !== null ? round((float) $override, 2) : $systemAdoption;

        return array_merge($counts, $moduleTransactions, [
            'week_start' => $week['start_date'],
            'week_end' => $week['end_date'],
            'week_no' => $week['week_no'],
            'week_label' => 'Week '.$week['week_no'],
            'week_range' => $week['label'],
            'period_start' => $week['start_date'],
            'period_label' => 'Week '.$week['week_no'],
            'period_range' => $week['label'],
            'has_record' => $hasTally,
            'module_concerns' => $moduleConcerns,
            'technical_concerns' => $technicalConcerns,
            'total_tickets' => $totalTickets,
            'total_closed' => $totalClosed,
            'total_open' => $totalTickets - $totalClosed,
            'total_transactions' => $totalTransactions,
            // Only weeks whose tickets have actually been tallied get a rate.
            // Transactions are live, so an un-tallied week has a real denominator
            // and zero tickets - reporting that as a flawless 100% would invent a
            // score for a week nobody has counted and inflate the running average.
            // A week tallied as all zeros (encoded, or live from Helpdesk) does score 100%.
            'success_rate' => $hasTally && $totalTransactions > 0
                ? round((1 - ($totalTickets / $totalTransactions)) * 100, 2)
                : null,
            'close_rate' => $totalTickets > 0
                ? round(($totalClosed / $totalTickets) * 100, 2)
                : null,
            'adoption_rate' => $adoptionRate,
            'adoption_rate_override' => $override !== null ? round((float) $override, 2) : null,
            'remarks' => $record?->remarks,
        ]);
    }

    /**
     * Regroup weekly rows into calendar months.
     *
     * A whole ISO week belongs to the month holding its Thursday - the same rule
     * that numbers ISO weeks - so a week straddling two months is never split
     * or counted twice. Counts and transactions are summed; the rates are
     * re-derived from those sums rather than averaged:
     *   Success Rate  over encoded weeks only, as a weekly row requires
     *   Close Rate    summed closed / summed tickets
     *   Adoption Rate simple average of the weeks' rates (it has no counts to sum)
     */
    private function buildMonthRows(Collection $weekRows): Collection
    {
        $moduleKeys = array_keys(SuccessRateWeeklyTicket::MODULES);
        $sumColumns = array_merge(
            SuccessRateWeeklyTicket::countColumns(),
            array_map(fn ($m) => $m.'_transactions', $moduleKeys),
            ['module_concerns', 'technical_concerns', 'total_tickets', 'total_closed', 'total_open', 'total_transactions']
        );

        return $weekRows
            ->groupBy(fn (array $row) => Carbon::parse($row['week_start'])->addDays(3)->format('Y-m'))
            ->map(function (Collection $weeks, string $month) use ($sumColumns) {
                $monthStart = Carbon::createFromFormat('Y-m-d', $month.'-01');
                $encoded = $weeks->where('has_record', true);

                $row = [];
                foreach ($sumColumns as $column) {
                    $row[$column] = (int) $weeks->sum($column);
                }

                $encodedTickets = (int) $encoded->sum('total_tickets');
                $encodedTransactions = (int) $encoded->sum('total_transactions');

                return array_merge($row, [
                    'period_start' => $monthStart->toDateString(),
                    'period_label' => $monthStart->format('M Y'),
                    'period_range' => Carbon::parse($weeks->first()['week_start'])->format('M j')
                        .'-'.Carbon::parse($weeks->last()['week_end'])->format('M j'),
                    'weeks' => $weeks->count(),
                    'weeks_with_data' => $encoded->count(),
                    'has_record' => $encoded->isNotEmpty(),
                    'success_rate' => $encoded->isNotEmpty() && $encodedTransactions > 0
                        ? round((1 - ($encodedTickets / $encodedTransactions)) * 100, 2)
                        : null,
                    'close_rate' => $row['total_tickets'] > 0
                        ? round(($row['total_closed'] / $row['total_tickets']) * 100, 2)
                        : null,
                    'adoption_rate' => $this->average($weeks->pluck('adoption_rate')),
                    'adoption_rate_override' => null,
                ]);
            })
            ->values();
    }

    /** Running averages and the module/technical ticket-type split. */
    private function buildTotals(Collection $rows): array
    {
        $moduleConcerns = (int) $rows->sum('module_concerns');
        $technicalConcerns = (int) $rows->sum('technical_concerns');
        $totalTickets = $moduleConcerns + $technicalConcerns;

        return [
            'weeks' => $rows->count(),
            'weeks_with_data' => $rows->where('has_record', true)->count(),
            'running_success_rate' => $this->average($rows->pluck('success_rate')),
            'running_close_rate' => $this->average($rows->pluck('close_rate')),
            'running_adoption_rate' => $this->average($rows->pluck('adoption_rate')),
            'module_concerns' => $moduleConcerns,
            'technical_concerns' => $technicalConcerns,
            'total_tickets' => $totalTickets,
            'total_closed' => (int) $rows->sum('total_closed'),
            'total_open' => (int) $rows->sum('total_open'),
            'total_transactions' => (int) $rows->sum('total_transactions'),
            // Ticket-type split (workbook W19 / X19).
            'module_share' => $totalTickets > 0
                ? round(($moduleConcerns / $totalTickets) * 100, 2)
                : null,
            'technical_share' => $totalTickets > 0
                ? round(($technicalConcerns / $totalTickets) * 100, 2)
                : null,
        ];
    }

    private function average(Collection $values): ?float
    {
        $present = $values->filter(fn ($value) => $value !== null);

        return $present->isEmpty() ? null : round((float) $present->avg(), 2);
    }

    /**
     * The seven ticket types. `has_transactions` tells the UI which modules
     * contribute to the success-rate denominator; MEC and Admin do not.
     */
    private function moduleDefinitions(): array
    {
        $modules = [];

        foreach (SuccessRateWeeklyTicket::MODULES as $key => $label) {
            $modules[] = [
                'key' => $key,
                'label' => $label,
                'has_transactions' => array_key_exists($key, self::TRANSACTION_SOURCES),
            ];
        }

        $modules[] = [
            'key' => 'admin',
            'label' => 'Admin / Technical Concerns',
            'has_transactions' => false,
        ];

        return $modules;
    }

    /** Defaults to the trailing 12 weeks so the chart opens with a real trend. */
    private function resolveDateRange(array $filters): array
    {
        $dateTo = ! empty($filters['date_to'])
            ? Carbon::parse($filters['date_to'])
            : Carbon::today();

        $dateFrom = ! empty($filters['date_from'])
            ? Carbon::parse($filters['date_from'])
            : $dateTo->copy()->subWeeks(11)->startOfWeek(Carbon::MONDAY);

        if ($dateFrom->gt($dateTo)) {
            $dateFrom = $dateTo->copy()->startOfWeek(Carbon::MONDAY);
        }

        return [$dateFrom->startOfDay(), $dateTo->endOfDay()];
    }

    /**
     * Whole ISO weeks (Mon-Sun) covering the range. Unlike the Adoption Rate
     * report these are never clipped to the range edges: a ticket tally belongs
     * to a full week, so a partial bucket would silently understate its counts.
     */
    private function buildWeekBuckets(Carbon $dateFrom, Carbon $dateTo): array
    {
        $weeks = [];

        for (
            $weekStart = $dateFrom->copy()->startOfWeek(Carbon::MONDAY);
            $weekStart->lte($dateTo);
            $weekStart->addWeek()
        ) {
            $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

            $weeks[] = [
                'start_date' => $weekStart->toDateString(),
                'end_date' => $weekEnd->toDateString(),
                'week_no' => (int) $weekStart->isoWeek,
                'iso_year' => (int) $weekStart->isoWeekYear,
                'label' => $weekStart->format('M j').'-'.$weekEnd->format('M j'),
            ];
        }

        return $weeks;
    }
}
