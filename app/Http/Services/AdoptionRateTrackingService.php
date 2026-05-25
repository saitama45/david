<?php

namespace App\Http\Services;

use App\Models\AdoptionRateTrackingRemark;
use App\Models\DTSDeliverySchedule;
use App\Models\OrdersCutoff;
use App\Models\StoreBranch;
use App\Models\StoreOrder;
use App\Models\StoreTransaction;
use App\Models\Supplier;
use App\Models\SupplierItems;
use App\Models\User;
use App\Models\Wastage;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

class AdoptionRateTrackingService
{
    public const TAB_ORDERING_TIMELINESS = 'ordering_timeliness';
    public const TAB_COMMIT_ORDER_TIMELINESS = 'commit_order_timeliness';
    public const TAB_DELIVERY_LOGGING_TIMELINESS = 'delivery_logging_timeliness';
    public const TAB_SALES_UPLOAD_TIMELINESS = 'sales_upload_timeliness';
    public const TAB_WASTAGE_UPLOAD_TIMELINESS = 'wastage_upload_timeliness';
    public const TAB_OVERALL_ADOPTION_RATE = 'overall_adoption_rate';

    private const SPECIAL_WEEK_OFFSET_TEMPLATES = ['PUL-O', 'CPO', 'FRUITS AND VEGETABLES'];
    private const SALES_UPLOAD_REMARK_TEMPLATE = 'SALES_UPLOAD';
    private const WASTAGE_UPLOAD_REMARK_PREFIX = 'WASTAGE_UPLOAD:';
    private const ENABLED_TABS = [
        self::TAB_ORDERING_TIMELINESS,
        self::TAB_COMMIT_ORDER_TIMELINESS,
        self::TAB_DELIVERY_LOGGING_TIMELINESS,
        self::TAB_SALES_UPLOAD_TIMELINESS,
        self::TAB_WASTAGE_UPLOAD_TIMELINESS,
        self::TAB_OVERALL_ADOPTION_RATE,
    ];

    public function getReportData(array $filters, User $user, bool $paginate = true): array
    {
        $tab = $this->resolveTab($filters);

        return match ($tab) {
            self::TAB_COMMIT_ORDER_TIMELINESS => $this->getCommitOrderTimelinessData($filters, $user, $paginate),
            self::TAB_DELIVERY_LOGGING_TIMELINESS => $this->getDeliveryLoggingTimelinessData($filters, $user, $paginate),
            self::TAB_SALES_UPLOAD_TIMELINESS => $this->getSalesUploadTimelinessData($filters, $user, $paginate),
            self::TAB_WASTAGE_UPLOAD_TIMELINESS => $this->getWastageUploadTimelinessData($filters, $user, $paginate),
            self::TAB_OVERALL_ADOPTION_RATE => $this->getOverallAdoptionRateData($filters, $user, $paginate),
            default => $this->getOrderingTimelinessData($filters, $user, $paginate),
        };
    }

    public function getOrderingTimelinessData(array $filters, User $user, bool $paginate = true): array
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($filters);
        $storeIds = $this->resolveStoreIds($filters, $user);
        $templates = $this->resolveTemplates($filters);

        $schedules = $this->getSchedules($dateFrom, $dateTo, $storeIds, $templates);
        $orders = $this->getOrders($dateFrom, $dateTo, $storeIds, $templates);
        $remarks = $this->getRemarks(self::TAB_ORDERING_TIMELINESS, $dateFrom, $dateTo, $storeIds, $templates);

        $rows = $this->buildRows($schedules, $orders, $remarks, $dateFrom, $dateTo);
        $rows = $this->filterRows($rows, $filters['search'] ?? null);

        $totals = [
            'scheduled' => $rows->count(),
            'yes' => $rows->where('plotted', 'Yes')->count(),
            'no' => $rows->where('plotted', 'No')->count(),
            'no_order' => $rows->where('plotted', 'No order')->count(),
        ];
        $orderDenominator = $totals['yes'] + $totals['no'];
        $totals['adoption_rate'] = $orderDenominator > 0
            ? round(($totals['yes'] / $orderDenominator) * 100, 2)
            : null;

        return [
            'rows' => $paginate ? $this->paginateRows($rows, (int) ($filters['per_page'] ?? 50)) : $rows->values(),
            'totals' => $totals,
            'filters' => $this->responseFilters($dateFrom, $dateTo, $storeIds, $templates, $filters, self::TAB_ORDERING_TIMELINESS),
        ];
    }

    public function getCommitOrderTimelinessData(array $filters, User $user, bool $paginate = true): array
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($filters);
        $storeIds = $this->resolveStoreIds($filters, $user);
        $templates = $this->resolveTemplates($filters);

        $orders = $this->getCommitOrders($dateFrom, $dateTo, $storeIds, $templates);
        $remarks = $this->getRemarks(self::TAB_COMMIT_ORDER_TIMELINESS, $dateFrom, $dateTo, $storeIds, $templates);

        $rows = $this->buildCommitRows($orders, $remarks);
        $rows = $this->filterCommitRows($rows, $filters['search'] ?? null);

        $fgDenominator = $rows->whereIn('fg_on_time', ['Yes', 'No'])->count();
        $fgYes = $rows->where('fg_on_time', 'Yes')->count();
        $tradedDenominator = $rows->whereIn('traded_on_time', ['Yes', 'No'])->count();
        $tradedYes = $rows->where('traded_on_time', 'Yes')->count();
        $combinedCommitDenominator = $fgDenominator + $tradedDenominator;
        $combinedCommitYes = $fgYes + $tradedYes;

        $totals = [
            'orders' => $rows->count(),
            'fg_yes' => $fgYes,
            'fg_no' => $rows->where('fg_on_time', 'No')->count(),
            'fg_na' => $rows->where('fg_on_time', 'NA')->count(),
            'fg_adoption_rate' => $fgDenominator > 0 ? round(($fgYes / $fgDenominator) * 100, 2) : null,
            'traded_yes' => $tradedYes,
            'traded_no' => $rows->where('traded_on_time', 'No')->count(),
            'traded_adoption_rate' => $tradedDenominator > 0 ? round(($tradedYes / $tradedDenominator) * 100, 2) : null,
            'combined_yes' => $combinedCommitYes,
            'combined_no' => $combinedCommitDenominator - $combinedCommitYes,
            'combined_adoption_rate' => $combinedCommitDenominator > 0 ? round(($combinedCommitYes / $combinedCommitDenominator) * 100, 2) : null,
        ];

        return [
            'rows' => $paginate ? $this->paginateRows($rows, (int) ($filters['per_page'] ?? 50)) : $rows->values(),
            'totals' => $totals,
            'filters' => $this->responseFilters($dateFrom, $dateTo, $storeIds, $templates, $filters, self::TAB_COMMIT_ORDER_TIMELINESS),
        ];
    }

    public function getDeliveryLoggingTimelinessData(array $filters, User $user, bool $paginate = true): array
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($filters);
        $storeIds = $this->resolveStoreIds($filters, $user);
        $templates = $this->resolveTemplates($filters);

        $orders = $this->getDeliveryLoggingOrders($dateFrom, $dateTo, $storeIds, $templates);
        $remarks = $this->getRemarks(self::TAB_DELIVERY_LOGGING_TIMELINESS, $dateFrom, $dateTo, $storeIds, $templates);

        $rows = $this->buildDeliveryLoggingRows($orders, $remarks);
        $rows = $this->filterDeliveryLoggingRows($rows, $filters['search'] ?? null);

        $totals = [
            'deliveries' => $rows->count(),
            'yes' => $rows->where('on_time', 'Yes')->count(),
            'no' => $rows->where('on_time', 'No')->count(),
        ];
        $totals['adoption_rate'] = $totals['deliveries'] > 0
            ? round(($totals['yes'] / $totals['deliveries']) * 100, 2)
            : null;

        return [
            'rows' => $paginate ? $this->paginateRows($rows, (int) ($filters['per_page'] ?? 50)) : $rows->values(),
            'totals' => $totals,
            'filters' => $this->responseFilters($dateFrom, $dateTo, $storeIds, $templates, $filters, self::TAB_DELIVERY_LOGGING_TIMELINESS),
        ];
    }

    public function getSalesUploadTimelinessData(array $filters, User $user, bool $paginate = true): array
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($filters);
        $storeIds = $this->resolveStoreIds($filters, $user);

        $stores = StoreBranch::whereIn('id', $storeIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $uploads = $this->getSalesUploads($dateFrom, $dateTo, $storeIds);
        $remarks = $this->getRemarks(
            self::TAB_SALES_UPLOAD_TIMELINESS,
            $dateFrom,
            $dateTo,
            $storeIds,
            [self::SALES_UPLOAD_REMARK_TEMPLATE]
        );

        $rows = $this->buildSalesUploadRows($stores, $uploads, $remarks, $dateFrom, $dateTo);
        $rows = $this->filterSalesUploadRows($rows, $filters['search'] ?? null);

        $totals = [
            'days' => $rows->count(),
            'yes' => $rows->where('sales_report_uploaded', 'Yes')->count(),
            'no' => $rows->where('sales_report_uploaded', 'No')->count(),
        ];
        $totals['adoption_rate'] = $totals['days'] > 0
            ? round(($totals['yes'] / $totals['days']) * 100, 2)
            : null;

        return [
            'rows' => $paginate ? $this->paginateRows($rows, (int) ($filters['per_page'] ?? 50)) : $rows->values(),
            'totals' => $totals,
            'filters' => $this->responseFilters($dateFrom, $dateTo, $storeIds, [], $filters, self::TAB_SALES_UPLOAD_TIMELINESS),
        ];
    }

    public function getWastageUploadTimelinessData(array $filters, User $user, bool $paginate = true): array
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($filters);
        $storeIds = $this->resolveStoreIds($filters, $user);

        $wastages = $this->getWastageUploads($dateFrom, $dateTo, $storeIds);
        $remarks = $this->getWastageUploadRemarks($dateFrom, $dateTo, $storeIds);

        $rows = $this->buildWastageUploadRows($wastages, $remarks);
        $rows = $this->filterWastageUploadRows($rows, $filters['search'] ?? null);

        $totals = [
            'rows' => $rows->count(),
            'upload_yes' => $rows->where('wastage_report_uploaded', 'Yes')->count(),
            'upload_no' => $rows->where('wastage_report_uploaded', 'No')->count(),
            'approval_yes' => $rows->where('wastage_report_approved', 'Yes')->count(),
            'approval_no' => $rows->where('wastage_report_approved', 'No')->count(),
        ];
        $totals['upload_adoption_rate'] = $totals['rows'] > 0
            ? round(($totals['upload_yes'] / $totals['rows']) * 100, 2)
            : null;
        $totals['approval_adoption_rate'] = $totals['rows'] > 0
            ? round(($totals['approval_yes'] / $totals['rows']) * 100, 2)
            : null;

        return [
            'rows' => $paginate ? $this->paginateRows($rows, (int) ($filters['per_page'] ?? 50)) : $rows->values(),
            'totals' => $totals,
            'filters' => $this->responseFilters($dateFrom, $dateTo, $storeIds, [], $filters, self::TAB_WASTAGE_UPLOAD_TIMELINESS),
        ];
    }

    public function getOverallAdoptionRateData(array $filters, User $user, bool $paginate = true): array
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange($filters);
        $storeIds = $this->resolveStoreIds($filters, $user);
        $baseFilters = array_merge($filters, [
            'store_ids' => $storeIds,
            'ordering_templates' => [],
            'search' => null,
        ]);

        $weeks = $this->buildWeekBuckets($dateFrom, $dateTo);
        $stores = StoreBranch::whereIn('id', $storeIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $orderingRows = $this->getOrderingTimelinessData($baseFilters, $user, false)['rows'];
        $commitRows = $this->getCommitOrderTimelinessData($baseFilters, $user, false)['rows'];
        $deliveryRows = $this->getDeliveryLoggingTimelinessData($baseFilters, $user, false)['rows'];
        $salesRows = $this->getSalesUploadTimelinessData($baseFilters, $user, false)['rows'];
        $wastageRows = $this->getWastageUploadTimelinessData($baseFilters, $user, false)['rows'];

        $sections = $stores->map(function (StoreBranch $store) use ($weeks, $orderingRows, $commitRows, $deliveryRows, $salesRows, $wastageRows) {
            $storeId = (int) $store->id;
            $indicators = [
                [
                    'no' => 1,
                    'indicator' => 'Order Placement',
                    'responsible' => 'DSP / Store',
                    'rates' => $this->weeklyRates($weeks, $orderingRows, $storeId, 'david_delivery_date', fn (Collection $rows) => $this->statusRate($rows, 'plotted', ['Yes'], ['Yes', 'No'])),
                    'overall_rate' => $this->statusRate($this->storeRows($orderingRows, $storeId), 'plotted', ['Yes'], ['Yes', 'No']),
                ],
                [
                    'no' => 2,
                    'indicator' => 'Timeliness of Committing of Order',
                    'responsible' => "Customer Service\n(GSI & Pulilan)",
                    'rates' => $this->weeklyRates($weeks, $commitRows, $storeId, 'delivery_date', fn (Collection $rows) => $this->commitOverallRate($rows)),
                    'overall_rate' => $this->commitOverallRate($this->storeRows($commitRows, $storeId)),
                ],
                [
                    'no' => 3,
                    'indicator' => 'Timeliness of Receiving of Orders',
                    'responsible' => 'Operations',
                    'rates' => $this->weeklyRates($weeks, $deliveryRows, $storeId, 'sap_dr_date', fn (Collection $rows) => $this->statusRate($rows, 'on_time', ['Yes'], ['Yes', 'No'])),
                    'overall_rate' => $this->statusRate($this->storeRows($deliveryRows, $storeId), 'on_time', ['Yes'], ['Yes', 'No']),
                ],
                [
                    'no' => 4,
                    'indicator' => 'Timeliness of Sales Uploading',
                    'responsible' => 'Sales Audit',
                    'rates' => $this->weeklyRates($weeks, $salesRows, $storeId, 'date_of_sales', fn (Collection $rows) => $this->statusRate($rows, 'sales_report_uploaded', ['Yes'], ['Yes', 'No'])),
                    'overall_rate' => $this->statusRate($this->storeRows($salesRows, $storeId), 'sales_report_uploaded', ['Yes'], ['Yes', 'No']),
                ],
                [
                    'no' => 5,
                    'indicator' => 'Timeliness of Wastage Uploading',
                    'responsible' => 'Operations',
                    'rates' => $this->weeklyRates($weeks, $wastageRows, $storeId, 'date_of_wastage', fn (Collection $rows) => $this->statusRate($rows, 'wastage_report_uploaded', ['Yes'], ['Yes', 'No'])),
                    'overall_rate' => $this->statusRate($this->storeRows($wastageRows, $storeId), 'wastage_report_uploaded', ['Yes'], ['Yes', 'No']),
                ],
            ];

            $weeklyAverages = collect($weeks)->mapWithKeys(function (array $week) use ($indicators) {
                return [$week['key'] => $this->simpleAverage(collect($indicators)->pluck("rates.{$week['key']}")->all())];
            })->all();

            return [
                'row_key' => 'overall|' . $store->id,
                'store' => $store->name ?: $this->displayStore($store),
                'store_code' => $this->displayStore($store),
                'store_branch_id' => (int) $store->id,
                'weeks' => $weeks,
                'indicators' => $indicators,
                'weekly_averages' => $weeklyAverages,
                'overall_rate' => $this->simpleAverage(collect($indicators)->pluck('overall_rate')->all()),
            ];
        })->values();

        $sections = $this->filterOverallSections($sections, $filters['search'] ?? null);
        $visibleOverallRates = $sections
            ->pluck('overall_rate')
            ->filter(fn ($rate) => $rate !== null)
            ->values()
            ->all();

        return [
            'rows' => $paginate ? $this->paginateRows($sections, (int) ($filters['per_page'] ?? 50)) : $sections,
            'totals' => [
                'stores' => $sections->count(),
                'weeks' => count($weeks),
                'overall_rate' => $this->simpleAverage($visibleOverallRates),
            ],
            'filters' => $this->responseFilters($dateFrom, $dateTo, $storeIds, [], $filters, self::TAB_OVERALL_ADOPTION_RATE),
        ];
    }

    public function getFilterOptions(User $user): array
    {
        $storeIds = $this->getAccessibleStoreIds($user);

        $stores = StoreBranch::whereIn('id', $storeIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'branch_code', 'brand_code']);

        $templates = DTSDeliverySchedule::query()
            ->select('variant')
            ->whereIn('store_branch_id', $storeIds)
            ->distinct()
            ->orderBy('variant')
            ->pluck('variant')
            ->map(fn ($variant) => ['label' => $this->displayTemplate($variant), 'value' => $variant])
            ->values();

        return [
            'stores' => $stores,
            'assignedStoreIds' => $storeIds,
            'orderingTemplates' => $templates,
        ];
    }

    public function saveRemark(array $data, User $user): AdoptionRateTrackingRemark
    {
        $tabKey = $this->resolveTab(['tab' => $data['tab_key'] ?? self::TAB_ORDERING_TIMELINESS]);

        return AdoptionRateTrackingRemark::updateOrCreate(
            [
                'tab_key' => $tabKey,
                'ordering_template' => $data['ordering_template'],
                'store_branch_id' => $data['store_branch_id'],
                'delivery_date' => Carbon::parse($data['delivery_date'])->toDateString(),
            ],
            [
                'remarks' => $data['remarks'] ?? null,
                'updated_by' => $user->id,
            ]
        );
    }

    public function resolveTab(array $filters): string
    {
        $tab = (string) ($filters['tab'] ?? self::TAB_ORDERING_TIMELINESS);

        return in_array($tab, self::ENABLED_TABS, true) ? $tab : self::TAB_ORDERING_TIMELINESS;
    }

    private function responseFilters(Carbon $dateFrom, Carbon $dateTo, array $storeIds, array $templates, array $filters, string $tab): array
    {
        return [
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'store_ids' => $storeIds,
            'ordering_templates' => $templates,
            'search' => $filters['search'] ?? null,
            'per_page' => (int) ($filters['per_page'] ?? 50),
            'tab' => $tab,
        ];
    }

    private function resolveDateRange(array $filters): array
    {
        $dateFrom = !empty($filters['date_from'])
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : Carbon::today()->startOfMonth();
        $dateTo = !empty($filters['date_to'])
            ? Carbon::parse($filters['date_to'])->startOfDay()
            : Carbon::today()->endOfMonth()->startOfDay();

        if ($dateTo->lt($dateFrom)) {
            $dateTo = $dateFrom->copy();
        }

        return [$dateFrom, $dateTo];
    }

    private function resolveStoreIds(array $filters, User $user): array
    {
        $accessible = $this->getAccessibleStoreIds($user);
        $requested = array_values(array_filter((array) ($filters['store_ids'] ?? []), fn ($id) => $id !== 'all'));

        if (empty($requested)) {
            return $accessible;
        }

        return array_values(array_intersect(array_map('intval', $requested), $accessible));
    }

    private function getAccessibleStoreIds(User $user): array
    {
        $user->loadMissing(['roles', 'store_branches']);

        if ($user->roles->contains('name', 'admin') && $user->store_branches->isEmpty()) {
            return StoreBranch::where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $user->store_branches->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function resolveTemplates(array $filters): array
    {
        return array_values(array_filter((array) ($filters['ordering_templates'] ?? [])));
    }

    private function getSchedules(Carbon $dateFrom, Carbon $dateTo, array $storeIds, array $templates): EloquentCollection
    {
        return DTSDeliverySchedule::with(['store_branch', 'deliverySchedule'])
            ->whereIn('store_branch_id', $storeIds)
            ->when(!empty($templates), fn ($query) => $query->whereIn('variant', $templates))
            ->get();
    }

    private function getOrders(Carbon $dateFrom, Carbon $dateTo, array $storeIds, array $templates): Collection
    {
        $supplierCodes = $this->supplierCodesForTemplates($templates);

        return StoreOrder::with(['supplier', 'store_order_items'])
            ->whereIn('store_branch_id', $storeIds)
            ->whereBetween('order_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->whereHas('store_order_items')
            ->where(function ($query) use ($templates, $supplierCodes) {
                if (!empty($supplierCodes)) {
                    $supplierIds = Supplier::whereIn('supplier_code', $supplierCodes)->pluck('id');
                    $query->whereIn('supplier_id', $supplierIds);
                }

                if (!empty($templates)) {
                    $query->orWhereIn('variant', $templates)
                        ->orWhere(function ($q) use ($templates) {
                            $q->where('variant', 'mass dts');
                            foreach ($templates as $template) {
                                $q->orWhere('remarks', 'like', "Mass DTS Order - {$template}");
                            }
                        });
                }
            })
            ->get()
            ->groupBy(fn (StoreOrder $order) => $this->orderRowKey($order));
    }

    private function getCommitOrders(Carbon $dateFrom, Carbon $dateTo, array $storeIds, array $templates): Collection
    {
        return $this->getOrders($dateFrom, $dateTo, $storeIds, $templates);
    }

    private function getDeliveryLoggingOrders(Carbon $dateFrom, Carbon $dateTo, array $storeIds, array $templates): Collection
    {
        $supplierCodes = $this->supplierCodesForTemplates($templates);

        return StoreOrder::with([
                'supplier',
                'store_branch',
                'delivery_receipts',
                'store_order_items.ordered_item_receive_dates' => fn ($query) => $query->where('status', 'approved'),
            ])
            ->whereIn('store_branch_id', $storeIds)
            ->whereBetween('order_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->whereHas('store_order_items')
            ->where(function ($query) use ($templates, $supplierCodes) {
                if (!empty($supplierCodes)) {
                    $supplierIds = Supplier::whereIn('supplier_code', $supplierCodes)->pluck('id');
                    $query->whereIn('supplier_id', $supplierIds);
                }

                if (!empty($templates)) {
                    $query->orWhereIn('variant', $templates)
                        ->orWhere(function ($q) use ($templates) {
                            $q->where('variant', 'mass dts');
                            foreach ($templates as $template) {
                                $q->orWhere('remarks', 'like', "Mass DTS Order - {$template}");
                            }
                        });
                }
            })
            ->get()
            ->groupBy(fn (StoreOrder $order) => $this->orderRowKey($order));
    }

    private function getSalesUploads(Carbon $dateFrom, Carbon $dateTo, array $storeIds): Collection
    {
        return StoreTransaction::query()
            ->whereIn('store_branch_id', $storeIds)
            ->whereBetween('order_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->selectRaw('store_branch_id, order_date, MIN(created_at) as uploaded_at')
            ->groupBy('store_branch_id', 'order_date')
            ->get()
            ->keyBy(fn (StoreTransaction $transaction) => $this->scheduledRowKey(
                self::SALES_UPLOAD_REMARK_TEMPLATE,
                (int) $transaction->store_branch_id,
                Carbon::parse($transaction->order_date)->toDateString()
            ));
    }

    private function getWastageUploads(Carbon $dateFrom, Carbon $dateTo, array $storeIds): Collection
    {
        return Wastage::with(['storeBranch'])
            ->whereIn('store_branch_id', $storeIds)
            ->whereBetween('created_at', [$dateFrom->copy()->startOfDay(), $dateTo->copy()->endOfDay()])
            ->orderBy('created_at')
            ->orderBy('wastage_no')
            ->orderBy('id')
            ->get();
    }

    private function getWastageUploadRemarks(Carbon $dateFrom, Carbon $dateTo, array $storeIds): Collection
    {
        return AdoptionRateTrackingRemark::where('tab_key', self::TAB_WASTAGE_UPLOAD_TIMELINESS)
            ->whereIn('store_branch_id', $storeIds)
            ->whereBetween('delivery_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('ordering_template', 'like', self::WASTAGE_UPLOAD_REMARK_PREFIX . '%')
            ->get()
            ->keyBy(fn ($remark) => $this->scheduledRowKey(
                $remark->ordering_template,
                (int) $remark->store_branch_id,
                Carbon::parse($remark->delivery_date)->toDateString()
            ));
    }

    private function getRemarks(string $tabKey, Carbon $dateFrom, Carbon $dateTo, array $storeIds, array $templates): Collection
    {
        return AdoptionRateTrackingRemark::where('tab_key', $tabKey)
            ->whereIn('store_branch_id', $storeIds)
            ->whereBetween('delivery_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when(!empty($templates), fn ($query) => $query->whereIn('ordering_template', $templates))
            ->get()
            ->keyBy(fn ($remark) => $this->scheduledRowKey(
                $remark->ordering_template,
                (int) $remark->store_branch_id,
                Carbon::parse($remark->delivery_date)->toDateString()
            ));
    }

    private function buildRows(EloquentCollection $schedules, Collection $orders, Collection $remarks, Carbon $dateFrom, Carbon $dateTo): Collection
    {
        $rows = collect();
        $dates = collect();

        for ($date = $dateFrom->copy(); $date->lte($dateTo); $date->addDay()) {
            $dates->push($date->copy());
        }

        foreach ($schedules as $schedule) {
            if (!$schedule->store_branch || !$schedule->deliverySchedule) {
                continue;
            }

            foreach ($dates as $date) {
                if ((int) $date->dayOfWeekIso !== (int) $schedule->delivery_schedule_id) {
                    continue;
                }

                $template = $schedule->variant;
                $key = $this->scheduledRowKey($template, (int) $schedule->store_branch_id, $date->toDateString());
                $matchingOrders = $orders->get($key, collect());
                $hasOrder = $matchingOrders->isNotEmpty();
                $isOnTime = $hasOrder && $matchingOrders->contains(fn (StoreOrder $order) => $this->isOrderOnTime($order, $template, $date));
                $plotted = $isOnTime ? 'Yes' : ($hasOrder ? 'No' : 'No order');
                $remark = $remarks->get($key);

                $rows->push([
                    'row_key' => $key,
                    'week_no' => (int) $date->isoWeek(),
                    'date_range' => $this->formatDateRange($date),
                    'supplier_code' => $this->displayTemplate($template),
                    'ordering_template' => $template,
                    'store' => $this->displayStore($schedule->store_branch),
                    'store_branch_id' => (int) $schedule->store_branch_id,
                    'david_delivery_date' => $date->toDateString(),
                    'david_delivery_date_display' => $date->format('M j, Y'),
                    'plotted' => $plotted,
                    'plotted_num' => $plotted === 'Yes' ? 1 : 0,
                    'remarks' => $remark?->remarks,
                ]);
            }
        }

        return $rows
            ->sortBy([
                ['david_delivery_date', 'asc'],
                ['supplier_code', 'asc'],
                ['store', 'asc'],
            ])
            ->values();
    }

    private function buildCommitRows(Collection $orders, Collection $remarks): Collection
    {
        $categoryMap = $this->categoryMapForOrders($orders->flatten(1));
        $rows = collect();

        foreach ($orders as $key => $matchingOrders) {
            $firstOrder = $matchingOrders->sortBy('id')->first();

            if (!$firstOrder || !$firstOrder->store_branch) {
                continue;
            }

            $deliveryDate = Carbon::parse($firstOrder->order_date)->startOfDay();
            $template = $this->templateForOrder($firstOrder);
            $fgCommitDate = $this->latestCategoryCommitDate($matchingOrders, $categoryMap, 'fg');
            $tradedCommitDate = $this->latestCategoryCommitDate($matchingOrders, $categoryMap, 'traded');
            $isPulO = $this->displayTemplate($template) === 'PUL-O';
            $fgStatus = $isPulO ? 'NA' : $this->commitStatus($fgCommitDate, $deliveryDate);
            $tradedStatus = $this->commitStatus($tradedCommitDate, $deliveryDate);
            $remark = $remarks->get($key);

            $rows->push([
                'row_key' => $key,
                'week_no' => (int) $deliveryDate->isoWeek(),
                'date_range' => $this->formatDateRange($deliveryDate),
                'store' => $this->displayStore($firstOrder->store_branch),
                'store_branch_id' => (int) $firstOrder->store_branch_id,
                'supplier_code' => $this->displayTemplate($template),
                'ordering_template' => $template,
                'delivery_date' => $deliveryDate->toDateString(),
                'delivery_date_display' => $deliveryDate->format('M j, Y'),
                'fg_commit_date_display' => $isPulO ? 'NA' : ($fgCommitDate ? $fgCommitDate->format('M j, Y') : 'No Commit'),
                'fg_on_time' => $fgStatus,
                'fg_on_time_num' => $fgStatus === 'Yes' ? 1 : 0,
                'traded_commit_date_display' => $tradedCommitDate ? $tradedCommitDate->format('M j, Y') : 'No Commit',
                'traded_on_time' => $tradedStatus,
                'traded_on_time_num' => $tradedStatus === 'Yes' ? 1 : 0,
                'remarks' => $remark?->remarks,
            ]);
        }

        return $rows
            ->sortBy([
                ['delivery_date', 'asc'],
                ['supplier_code', 'asc'],
                ['store', 'asc'],
            ])
            ->values();
    }

    private function buildDeliveryLoggingRows(Collection $orders, Collection $remarks): Collection
    {
        $rows = collect();

        foreach ($orders as $key => $matchingOrders) {
            $firstOrder = $matchingOrders->sortBy('id')->first();

            if (!$firstOrder || !$firstOrder->store_branch) {
                continue;
            }

            $deliveryDate = Carbon::parse($firstOrder->order_date)->startOfDay();
            $template = $this->templateForOrder($firstOrder);
            $loggingDate = $this->latestApprovedLoggingDate($matchingOrders);
            $onTime = $loggingDate && $loggingDate->isSameDay($deliveryDate) ? 'Yes' : 'No';
            $remark = $remarks->get($key);

            $rows->push([
                'row_key' => $key,
                'week_no' => (int) $deliveryDate->isoWeek(),
                'date_range' => $this->formatDateRange($deliveryDate),
                'supplier_code' => $this->displayTemplate($template),
                'ordering_template' => $template,
                'store' => $this->displayStore($firstOrder->store_branch),
                'store_branch_id' => (int) $firstOrder->store_branch_id,
                'dr_number' => $this->joinReceiptValues($matchingOrders, 'delivery_receipt_number'),
                'so_po_number' => $this->joinReceiptValues($matchingOrders, 'sap_so_number'),
                'sap_dr_date' => $deliveryDate->toDateString(),
                'sap_dr_date_display' => $deliveryDate->format('M j, Y'),
                'david_logging_date' => $loggingDate?->toDateString(),
                'david_logging_date_display' => $loggingDate ? $loggingDate->format('M j, Y') : '',
                'on_time' => $onTime,
                'on_time_num' => $onTime === 'Yes' ? 1 : 0,
                'remarks' => $remark?->remarks,
            ]);
        }

        return $rows
            ->sortBy([
                ['sap_dr_date', 'asc'],
                ['supplier_code', 'asc'],
                ['store', 'asc'],
            ])
            ->values();
    }

    private function buildSalesUploadRows(Collection $stores, Collection $uploads, Collection $remarks, Carbon $dateFrom, Carbon $dateTo): Collection
    {
        $rows = collect();

        foreach ($stores as $store) {
            for ($date = $dateFrom->copy(); $date->lte($dateTo); $date->addDay()) {
                $salesDate = $date->copy()->startOfDay();
                $key = $this->scheduledRowKey(self::SALES_UPLOAD_REMARK_TEMPLATE, (int) $store->id, $salesDate->toDateString());
                $upload = $uploads->get($key);
                $uploadDate = $upload?->uploaded_at ? Carbon::parse($upload->uploaded_at)->timezone('Asia/Manila')->startOfDay() : null;
                $acceptanceCriteria = $salesDate->isWeekend() ? 0 : 1;
                $networkDays = $uploadDate ? $this->salesUploadNetworkDays($salesDate, $uploadDate) : null;
                $uploaded = $networkDays !== null && $networkDays <= $acceptanceCriteria ? 'Yes' : 'No';
                $remark = $remarks->get($key);

                $rows->push([
                    'row_key' => $key,
                    'week_no' => (int) $salesDate->isoWeek(),
                    'date_range' => $this->formatDateRange($salesDate),
                    'ordering_template' => self::SALES_UPLOAD_REMARK_TEMPLATE,
                    'store' => $this->displayStore($store),
                    'store_branch_id' => (int) $store->id,
                    'date_of_sales' => $salesDate->toDateString(),
                    'date_of_sales_display' => $salesDate->format('M j, Y'),
                    'actual_sales_upload_date' => $uploadDate?->toDateString(),
                    'actual_sales_upload_date_display' => $uploadDate ? $uploadDate->format('M j, Y') : '',
                    'networkdays' => $networkDays,
                    'day_of_sales' => $salesDate->format('D'),
                    'acceptance_criteria' => $acceptanceCriteria,
                    'sales_report_uploaded' => $uploaded,
                    'remarks' => $remark?->remarks,
                ]);
            }
        }

        return $rows
            ->sortBy([
                ['date_of_sales', 'asc'],
                ['store', 'asc'],
            ])
            ->values();
    }

    private function buildWastageUploadRows(Collection $wastages, Collection $remarks): Collection
    {
        return $wastages->map(function (Wastage $wastage) use ($remarks) {
            $wastageDate = Carbon::parse($wastage->created_at)->timezone('Asia/Manila');
            $uploadDate = Carbon::parse($wastage->created_at)->timezone('Asia/Manila');
            $wastageDay = $wastageDate->copy()->startOfDay();
            $uploadDay = $uploadDate->copy()->startOfDay();
            $acceptanceCriteria = $wastageDay->isWeekend() ? 0 : 1;
            $networkDays = $this->salesUploadNetworkDays($wastageDay, $uploadDay);
            $uploaded = $networkDays <= $acceptanceCriteria ? 'Yes' : 'No';
            $approved = $this->isWastageFinalApproved($wastage) ? 'Yes' : 'No';
            $template = self::WASTAGE_UPLOAD_REMARK_PREFIX . $wastage->id;
            $key = $this->scheduledRowKey($template, (int) $wastage->store_branch_id, $wastageDay->toDateString());
            $remark = $remarks->get($key);

            return [
                'row_key' => $key,
                'week_no' => (int) $wastageDay->isoWeek(),
                'date_range' => $this->formatDateRange($wastageDay),
                'ordering_template' => $template,
                'wastage_id' => (int) $wastage->id,
                'wastage_no' => $wastage->wastage_no,
                'status' => $wastage->status_label,
                'date_of_wastage' => $wastageDay->toDateString(),
                'date_of_wastage_display' => $wastageDate->format('M j, Y'),
                'date_of_wastage_upload' => $uploadDay->toDateString(),
                'date_of_wastage_upload_display' => $uploadDate->format('M j, Y'),
                'level_1_approval' => $wastage->approved_level1_date ? 'Y' : 'N',
                'level_2_approval' => $wastage->approved_level2_date ? 'Y' : 'N',
                'store' => $wastage->storeBranch ? $this->displayStore($wastage->storeBranch) : 'N/A',
                'store_branch_id' => (int) $wastage->store_branch_id,
                'networkdays' => $networkDays,
                'day_of_sales' => $wastageDay->format('D'),
                'acceptance_criteria' => $acceptanceCriteria,
                'wastage_report_uploaded' => $uploaded,
                'wastage_report_approved' => $approved,
                'remarks' => $remark?->remarks,
            ];
        })->values();
    }

    private function isWastageFinalApproved(Wastage $wastage): bool
    {
        return !empty($wastage->approved_level2_date)
            || (string) ($wastage->wastage_status?->value ?? $wastage->wastage_status) === 'approved_lvl2';
    }

    private function salesUploadNetworkDays(Carbon $salesDate, Carbon $uploadDate): int
    {
        $baseDate = $salesDate->copy();

        while ($baseDate->isWeekend()) {
            $baseDate->addDay();
        }

        if ($uploadDate->lte($baseDate)) {
            return 0;
        }

        $days = 0;
        for ($date = $baseDate->copy()->addDay(); $date->lte($uploadDate); $date->addDay()) {
            if ($date->isWeekday()) {
                $days++;
            }
        }

        return $days;
    }

    private function buildWeekBuckets(Carbon $dateFrom, Carbon $dateTo): array
    {
        $weeks = [];

        for ($weekStart = $dateFrom->copy()->startOfWeek(Carbon::MONDAY); $weekStart->lte($dateTo); $weekStart->addWeek()) {
            $start = $weekStart->copy()->max($dateFrom);
            $end = $weekStart->copy()->endOfWeek(Carbon::SUNDAY)->min($dateTo);

            if ($end->lt($start)) {
                continue;
            }

            $weeks[] = [
                'key' => $start->toDateString() . '|' . $end->toDateString(),
                'label' => $start->format('M') . $start->format('j') . '-' . $end->format('M') . $end->format('j'),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ];
        }

        return $weeks;
    }

    private function weeklyRates(array $weeks, Collection $rows, int $storeBranchId, string $dateKey, callable $rateResolver): array
    {
        return collect($weeks)->mapWithKeys(function (array $week) use ($rows, $storeBranchId, $dateKey, $rateResolver) {
            $weekStart = Carbon::parse($week['start_date'])->startOfDay();
            $weekEnd = Carbon::parse($week['end_date'])->startOfDay();
            $weekRows = $rows->filter(function (array $row) use ($storeBranchId, $dateKey, $weekStart, $weekEnd) {
                if ((int) ($row['store_branch_id'] ?? 0) !== $storeBranchId || empty($row[$dateKey])) {
                    return false;
                }

                $rowDate = Carbon::parse($row[$dateKey])->startOfDay();

                return $rowDate->betweenIncluded($weekStart, $weekEnd);
            });

            return [$week['key'] => $rateResolver($weekRows)];
        })->all();
    }

    private function storeRows(Collection $rows, int $storeBranchId): Collection
    {
        return $rows
            ->filter(fn (array $row) => (int) ($row['store_branch_id'] ?? 0) === $storeBranchId)
            ->values();
    }

    private function statusRate(Collection $rows, string $field, array $yesValues, array $denominatorValues): ?float
    {
        $denominator = $rows->filter(fn (array $row) => in_array($row[$field] ?? null, $denominatorValues, true))->count();

        if ($denominator === 0) {
            return null;
        }

        $yes = $rows->filter(fn (array $row) => in_array($row[$field] ?? null, $yesValues, true))->count();

        return round(($yes / $denominator) * 100, 2);
    }

    private function commitOverallRate(Collection $rows): ?float
    {
        $statuses = $rows->flatMap(fn (array $row) => [
            $row['fg_on_time'] ?? null,
            $row['traded_on_time'] ?? null,
        ])->filter(fn ($status) => in_array($status, ['Yes', 'No'], true));

        $denominator = $statuses->count();

        if ($denominator === 0) {
            return null;
        }

        return round(($statuses->filter(fn ($status) => $status === 'Yes')->count() / $denominator) * 100, 2);
    }

    private function simpleAverage(array $rates): ?float
    {
        $numericRates = collect($rates)
            ->filter(fn ($rate) => $rate !== null && is_numeric($rate))
            ->values();

        if ($numericRates->isEmpty()) {
            return null;
        }

        return round($numericRates->avg(), 2);
    }

    private function latestApprovedLoggingDate(Collection $orders): ?Carbon
    {
        return $orders
            ->flatMap(fn (StoreOrder $order) => $order->store_order_items)
            ->flatMap(fn ($item) => $item->ordered_item_receive_dates)
            ->filter(fn ($receiveDate) => !empty($receiveDate->received_date))
            ->map(fn ($receiveDate) => Carbon::parse($receiveDate->received_date)->startOfDay())
            ->sort()
            ->last();
    }

    private function joinReceiptValues(Collection $orders, string $field): string
    {
        $values = $orders
            ->flatMap(fn (StoreOrder $order) => $order->delivery_receipts)
            ->pluck($field)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        return $values->isEmpty() ? 'N/A' : $values->implode(', ');
    }

    private function categoryMapForOrders(Collection $orders): Collection
    {
        $keys = $orders
            ->flatMap(fn (StoreOrder $order) => $order->store_order_items->map(fn ($item) => [
                'item_code' => $item->item_code,
                'uom' => $item->uom,
                'supplier_code' => $order->supplier?->supplier_code,
            ]))
            ->filter(fn ($item) => $item['item_code'] && $item['supplier_code'])
            ->unique(fn ($item) => $this->categoryKey($item['item_code'], $item['uom'], $item['supplier_code']))
            ->values();

        if ($keys->isEmpty()) {
            return collect();
        }

        $uoms = $keys->pluck('uom')->filter()->unique()->values()->all();

        return SupplierItems::query()
            ->whereIn('ItemCode', $keys->pluck('item_code')->unique()->values()->all())
            ->whereIn('SupplierCode', $keys->pluck('supplier_code')->unique()->values()->all())
            ->when(!empty($uoms), fn ($query) => $query->whereIn('uom', $uoms))
            ->get(['ItemCode', 'uom', 'SupplierCode', 'category'])
            ->keyBy(fn (SupplierItems $item) => $this->categoryKey($item->ItemCode, $item->uom, $item->SupplierCode));
    }

    private function latestCategoryCommitDate(Collection $orders, Collection $categoryMap, string $categoryType): ?Carbon
    {
        $items = $orders->flatMap(function (StoreOrder $order) use ($categoryMap, $categoryType) {
            return $order->store_order_items->filter(function ($item) use ($order, $categoryMap, $categoryType) {
                $category = $categoryMap->get($this->categoryKey($item->item_code, $item->uom, $order->supplier?->supplier_code))?->category;

                return $this->categoryType($category) === $categoryType;
            });
        });

        if ($items->isEmpty() || $items->contains(fn ($item) => empty($item->committed_date))) {
            return null;
        }

        return $items
            ->map(fn ($item) => Carbon::parse($item->committed_date)->startOfDay())
            ->sort()
            ->last();
    }

    private function categoryType(?string $category): ?string
    {
        $normalized = strtoupper(trim((string) $category));

        if (in_array($normalized, ['FINISHED GOODS', 'FINISHED GOOD', 'FG'], true)) {
            return 'fg';
        }

        return $normalized === 'TRADED' ? 'traded' : null;
    }

    private function commitStatus(?Carbon $commitDate, Carbon $deliveryDate): string
    {
        if (!$commitDate) {
            return 'No';
        }

        return $commitDate->lt($deliveryDate->copy()->startOfDay()) ? 'Yes' : 'No';
    }

    private function paginateRows(Collection $rows, int $perPage): LengthAwarePaginator
    {
        $perPage = in_array($perPage, [25, 50, 100, 200], true) ? $perPage : 50;
        $page = Paginator::resolveCurrentPage();
        $items = $rows->forPage($page, $perPage)->values();

        return new Paginator($items, $rows->count(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);
    }

    private function filterRows(Collection $rows, ?string $search): Collection
    {
        if (!$search) {
            return $rows;
        }

        $needle = mb_strtolower($search);

        return $rows->filter(function (array $row) use ($needle) {
            return str_contains(mb_strtolower($row['supplier_code']), $needle)
                || str_contains(mb_strtolower($row['ordering_template']), $needle)
                || str_contains(mb_strtolower($row['store']), $needle)
                || str_contains(mb_strtolower($row['plotted']), $needle)
                || str_contains(mb_strtolower((string) $row['remarks']), $needle);
        })->values();
    }

    private function filterCommitRows(Collection $rows, ?string $search): Collection
    {
        if (!$search) {
            return $rows;
        }

        $needle = mb_strtolower($search);

        return $rows->filter(function (array $row) use ($needle) {
            return str_contains(mb_strtolower($row['supplier_code']), $needle)
                || str_contains(mb_strtolower($row['ordering_template']), $needle)
                || str_contains(mb_strtolower($row['store']), $needle)
                || str_contains(mb_strtolower($row['fg_on_time']), $needle)
                || str_contains(mb_strtolower($row['traded_on_time']), $needle)
                || str_contains(mb_strtolower((string) $row['remarks']), $needle);
        })->values();
    }

    private function filterDeliveryLoggingRows(Collection $rows, ?string $search): Collection
    {
        if (!$search) {
            return $rows;
        }

        $needle = mb_strtolower($search);

        return $rows->filter(function (array $row) use ($needle) {
            return str_contains(mb_strtolower($row['supplier_code']), $needle)
                || str_contains(mb_strtolower($row['ordering_template']), $needle)
                || str_contains(mb_strtolower($row['store']), $needle)
                || str_contains(mb_strtolower($row['dr_number']), $needle)
                || str_contains(mb_strtolower($row['so_po_number']), $needle)
                || str_contains(mb_strtolower($row['on_time']), $needle)
                || str_contains(mb_strtolower((string) $row['remarks']), $needle);
        })->values();
    }

    private function filterSalesUploadRows(Collection $rows, ?string $search): Collection
    {
        if (!$search) {
            return $rows;
        }

        $needle = mb_strtolower($search);

        return $rows->filter(function (array $row) use ($needle) {
            return str_contains(mb_strtolower($row['store']), $needle)
                || str_contains(mb_strtolower($row['day_of_sales']), $needle)
                || str_contains(mb_strtolower($row['sales_report_uploaded']), $needle)
                || str_contains(mb_strtolower((string) $row['remarks']), $needle);
        })->values();
    }

    private function filterWastageUploadRows(Collection $rows, ?string $search): Collection
    {
        if (!$search) {
            return $rows;
        }

        $needle = mb_strtolower($search);

        return $rows->filter(function (array $row) use ($needle) {
            return str_contains(mb_strtolower($row['wastage_no']), $needle)
                || str_contains(mb_strtolower($row['status']), $needle)
                || str_contains(mb_strtolower($row['store']), $needle)
                || str_contains(mb_strtolower($row['wastage_report_uploaded']), $needle)
                || str_contains(mb_strtolower($row['wastage_report_approved']), $needle)
                || str_contains(mb_strtolower((string) $row['remarks']), $needle);
        })->values();
    }

    private function filterOverallSections(Collection $sections, ?string $search): Collection
    {
        if (!$search) {
            return $sections;
        }

        $needle = mb_strtolower($search);

        return $sections->filter(function (array $section) use ($needle) {
            if (str_contains(mb_strtolower($section['store']), $needle)
                || str_contains(mb_strtolower($section['store_code']), $needle)) {
                return true;
            }

            return collect($section['indicators'])->contains(function (array $indicator) use ($needle) {
                return str_contains(mb_strtolower($indicator['indicator']), $needle)
                    || str_contains(mb_strtolower($indicator['responsible']), $needle);
            });
        })->values();
    }

    private function isOrderOnTime(StoreOrder $order, string $template, Carbon $deliveryDate): bool
    {
        $cutoff = OrdersCutoff::where('ordering_template', $template)->first();

        if (!$cutoff) {
            return true;
        }

        $plottedAt = Carbon::parse($order->created_at)->timezone('Asia/Manila');
        $availableDates = $this->availableDatesAt($cutoff, $template, $plottedAt);

        return in_array($deliveryDate->toDateString(), $availableDates, true);
    }

    private function availableDatesAt(OrdersCutoff $cutoff, string $template, Carbon $now): array
    {
        $cutoff1 = $this->cutoffDate($now, $cutoff->cutoff_1_day, $cutoff->cutoff_1_time);
        $cutoff2 = $this->cutoffDate($now, $cutoff->cutoff_2_day, $cutoff->cutoff_2_time);
        $isSpecial = $this->usesSpecialWeekOffset($template);

        if ($cutoff1 && $now->lt($cutoff1)) {
            $days = $cutoff->days_covered_1;
            $weekOffset = $isSpecial ? 1 : 0;
        } elseif ($cutoff2 && $now->lt($cutoff2)) {
            $days = $cutoff->days_covered_2;
            $weekOffset = $isSpecial ? 1 : 0;
        } else {
            $days = $cutoff->days_covered_1;
            $weekOffset = $isSpecial ? 2 : 1;
        }

        $startOfWeek = $now->copy()->startOfWeek(Carbon::SUNDAY)->addWeeks($weekOffset);
        $dayMap = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

        return collect(explode(',', (string) $days))
            ->map(fn ($day) => trim($day))
            ->filter(fn ($day) => isset($dayMap[$day]))
            ->map(fn ($day) => $startOfWeek->copy()->addDays($dayMap[$day])->toDateString())
            ->values()
            ->all();
    }

    private function cutoffDate(Carbon $now, ?int $day, ?string $time): ?Carbon
    {
        if (!$day || !$time) {
            return null;
        }

        $dayIndex = $day === 7 ? 0 : $day;

        return $now->copy()->startOfWeek(Carbon::SUNDAY)->addDays($dayIndex)->setTimeFromTimeString($time);
    }

    private function usesSpecialWeekOffset(string $template): bool
    {
        return str_starts_with($template, 'GSI') || in_array($template, self::SPECIAL_WEEK_OFFSET_TEMPLATES, true);
    }

    private function orderRowKey(StoreOrder $order): string
    {
        return $this->scheduledRowKey(
            $this->templateForOrder($order),
            (int) $order->store_branch_id,
            Carbon::parse($order->order_date)->toDateString()
        );
    }

    private function scheduledRowKey(string $template, int $storeBranchId, string $date): string
    {
        return implode('|', [$template, $storeBranchId, $date]);
    }

    private function categoryKey(?string $itemCode, ?string $uom, ?string $supplierCode): string
    {
        return strtoupper(trim((string) $itemCode)) . '|'
            . strtoupper(trim((string) $uom)) . '|'
            . strtoupper(trim((string) $supplierCode));
    }

    private function templateForOrder(StoreOrder $order): string
    {
        if ($order->variant === 'mass dts' && str_starts_with((string) $order->remarks, 'Mass DTS Order - ')) {
            return str_replace('Mass DTS Order - ', '', (string) $order->remarks);
        }

        if ($order->supplier?->supplier_code === 'DROPS') {
            if ($order->variant === 'mass regular') {
                return 'FRUITS AND VEGETABLES';
            }

            return $order->variant ?: 'FRUITS AND VEGETABLES';
        }

        return $order->supplier?->supplier_code ?: $order->variant;
    }

    private function supplierCodesForTemplates(array $templates): array
    {
        if (empty($templates)) {
            return Supplier::where('is_active', true)->pluck('supplier_code')->all();
        }

        return collect($templates)
            ->map(fn ($template) => in_array($template, ['FRUITS AND VEGETABLES', 'ICE CREAM', 'SALMON'], true) ? 'DROPS' : $template)
            ->unique()
            ->values()
            ->all();
    }

    private function formatDateRange(Carbon $date): string
    {
        $start = $date->copy()->startOfWeek(Carbon::MONDAY);
        $end = $date->copy()->endOfWeek(Carbon::SUNDAY);

        return $start->format('M') . $start->format('d') . '-' . $end->format('M') . $end->format('d');
    }

    private function displayTemplate(string $template): string
    {
        return $template === 'FRUITS AND VEGETABLES' ? 'F&V' : $template;
    }

    private function displayStore(StoreBranch $branch): string
    {
        $code = $branch->brand_code ? $branch->brand_code . '-' : '';

        return $code . ($branch->branch_code ?: $branch->name);
    }
}
