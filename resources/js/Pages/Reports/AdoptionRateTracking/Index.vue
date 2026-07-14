<script setup>
import { computed, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";
import { throttle } from "lodash";
import axios from "axios";
import MultiSelect from "primevue/multiselect";
import Select from "primevue/select";
import {
    CalendarDays,
    ClipboardCheck,
    Download,
    FileClock,
    RotateCcw,
    Save,
    Search,
} from "lucide-vue-next";

const props = defineProps({
    rows: { type: Object, required: true },
    totals: { type: Object, required: true },
    filters: { type: Object, required: true },
    stores: { type: Array, required: true },
    assignedStoreIds: { type: Array, required: true },
    orderingTemplates: { type: Array, required: true },
    tabs: { type: Array, required: true },
    canEditRemarks: { type: Boolean, default: false },
});

const dateFrom = ref(props.filters.date_from || "");
const dateTo = ref(props.filters.date_to || "");
const storeIds = ref(props.filters.store_ids || props.assignedStoreIds);
const selectedTemplates = ref(props.filters.ordering_templates || []);
const search = ref(props.filters.search || "");
const perPage = ref(props.filters.per_page || 50);
const activeTab = ref(props.filters.tab || "ordering_timeliness");
const isLoading = ref(false);
const isSyncingFilters = ref(false);
const savingRow = ref(null);
const localRemarks = ref({});
const tabsWithoutTemplateFilter = ["sales_upload_timeliness", "wastage_upload_timeliness", "overall_adoption_rate"];
const renderedTab = computed(() => props.filters.tab || "ordering_timeliness");
const renderedSearch = computed(() => String(props.filters.search || "").trim());
const visibleRows = computed(() => props.rows?.data || []);
const overallSections = computed(() =>
    renderedTab.value === "overall_adoption_rate"
        ? visibleRows.value.filter((section) => Array.isArray(section.weeks) && Array.isArray(section.indicators))
        : []
);
const templateFilterAppliesFor = (tab) => !tabsWithoutTemplateFilter.includes(tab);
const templateFilterApplies = computed(() => templateFilterAppliesFor(activeTab.value));
const renderedTemplateFilterApplies = computed(() => templateFilterAppliesFor(renderedTab.value));

const perPageOptions = [
    { label: "25 rows", value: 25 },
    { label: "50 rows", value: 50 },
    { label: "100 rows", value: 100 },
    { label: "200 rows", value: 200 },
];

const storeOptions = computed(() =>
    props.stores.map((store) => ({
        label: `${store.name} (${store.branch_code || store.brand_code || store.id})`,
        value: store.id,
    }))
);

watch(
    () => props.rows.data,
    (rows) => {
        localRemarks.value = Object.fromEntries((rows || []).map((row) => [row.row_key, row.remarks || ""]));
    },
    { immediate: true }
);

const syncFiltersFromProps = (filters) => {
    isSyncingFilters.value = true;
    activeTab.value = filters.tab || "ordering_timeliness";
    dateFrom.value = filters.date_from || "";
    dateTo.value = filters.date_to || "";
    storeIds.value = filters.store_ids || props.assignedStoreIds;
    selectedTemplates.value = filters.ordering_templates || [];
    search.value = filters.search || "";
    perPage.value = filters.per_page || 50;
    Promise.resolve().then(() => {
        isSyncingFilters.value = false;
    });
};

watch(() => props.filters, syncFiltersFromProps, { deep: true });

const updateFilters = (tab = activeTab.value) => {
    router.get(
        route("reports.adoption-rate-tracking.index"),
        {
            tab,
            date_from: dateFrom.value,
            date_to: dateTo.value,
            store_ids: storeIds.value,
            ordering_templates: templateFilterAppliesFor(tab) ? selectedTemplates.value : [],
            search: search.value,
            per_page: perPage.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => {
                isLoading.value = true;
            },
            onFinish: () => {
                isLoading.value = false;
            },
        }
    );
};

const updateFiltersThrottled = throttle(() => updateFilters(), 300);
const updateSearchThrottled = throttle(() => updateFilters(), 500);

watch([dateFrom, dateTo, storeIds, selectedTemplates, perPage], () => {
    if (!isSyncingFilters.value) {
        updateFiltersThrottled();
    }
});

watch(search, () => {
    if (!isSyncingFilters.value) {
        updateSearchThrottled();
    }
});

const selectTab = (tab) => {
    if (!tab.enabled || activeTab.value === tab.key) return;

    activeTab.value = tab.key;
    updateFilters(tab.key);
};

const resetFilters = () => {
    dateFrom.value = props.filters.date_from;
    dateTo.value = props.filters.date_to;
    storeIds.value = props.assignedStoreIds;
    selectedTemplates.value = [];
    search.value = "";
    perPage.value = 50;
};

const exportRoute = computed(() =>
    route("reports.adoption-rate-tracking.export", {
        tab: renderedTab.value,
        date_from: dateFrom.value,
        date_to: dateTo.value,
        store_ids: storeIds.value,
        ordering_templates: renderedTemplateFilterApplies.value ? selectedTemplates.value : [],
        search: search.value,
    })
);

const saveRemark = async (row) => {
    if (!props.canEditRemarks) return;

    savingRow.value = row.row_key;
    try {
        const response = await axios.patch(route("reports.adoption-rate-tracking.remarks"), {
            tab_key: renderedTab.value,
            ordering_template: row.ordering_template,
            store_branch_id: row.store_branch_id,
            delivery_date: row.delivery_date || row.sap_dr_date || row.david_delivery_date || row.date_of_sales || row.date_of_wastage,
            remarks: localRemarks.value[row.row_key],
        });
        localRemarks.value[row.row_key] = response.data.remarks || "";
    } finally {
        savingRow.value = null;
    }
};

const formatNumber = (number) => new Intl.NumberFormat("en-PH").format(number || 0);
const formatRate = (rate) => rate === null || rate === undefined ? "N/A" : `${Number(rate).toFixed(2)}%`;

const statusClass = (status) => {
    if (status === "Yes") return "bg-emerald-50 text-emerald-700 border-emerald-200";
    if (status === "No") return "bg-red-50 text-red-700 border-red-200";
    return "bg-gray-50 text-gray-700 border-gray-200";
};
</script>

<template>
    <Layout heading="Adoption Rate Tracking" :hasExcelDownload="true" :exportRoute="exportRoute">
        <div class="mb-5 overflow-x-auto">
            <div class="inline-flex min-w-full gap-2 border-b border-gray-200">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    :disabled="!tab.enabled"
                    @click="selectTab(tab)"
                    class="whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition"
                    :class="[
                        activeTab === tab.key
                            ? 'border-blue-600 text-blue-700'
                            : 'border-transparent text-gray-500 hover:text-gray-800',
                        !tab.enabled ? 'cursor-not-allowed opacity-45 hover:text-gray-500' : ''
                    ]"
                >
                    {{ tab.label }}
                </button>
            </div>
        </div>

        <div class="mb-6 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-6">
                <div class="space-y-1">
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                        <CalendarDays class="h-4 w-4" />
                        From
                    </label>
                    <Input type="date" v-model="dateFrom" />
                </div>
                <div class="space-y-1">
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                        <CalendarDays class="h-4 w-4" />
                        To
                    </label>
                    <Input type="date" v-model="dateTo" />
                </div>
                <div class="space-y-1 lg:col-span-2">
                    <label class="text-sm font-medium text-gray-700">Stores</label>
                    <MultiSelect
                        v-model="storeIds"
                        :options="storeOptions"
                        optionLabel="label"
                        optionValue="value"
                        filter
                        placeholder="Select stores"
                        class="w-full"
                    />
                </div>
                <div class="space-y-1 lg:col-span-2">
                    <label class="text-sm font-medium text-gray-700">Ordering Templates</label>
                    <MultiSelect
                        v-model="selectedTemplates"
                        :options="props.orderingTemplates"
                        optionLabel="label"
                        optionValue="value"
                        filter
                        placeholder="All templates"
                        :disabled="!templateFilterApplies"
                        class="w-full"
                    />
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-6">
                <div class="relative lg:col-span-4">
                    <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                    <Input v-model="search" placeholder="Search supplier, store, status, or remarks..." class="pl-10" />
                </div>
                <Select
                    v-model="perPage"
                    :options="perPageOptions"
                    optionLabel="label"
                    optionValue="value"
                    class="w-full"
                />
                <div class="flex gap-2">
                    <Button variant="outline" class="w-full gap-2" @click="resetFilters">
                        <RotateCcw class="h-4 w-4" />
                        Reset
                    </Button>
                    <a :href="exportRoute" class="inline-flex h-10 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground">
                        <Download class="h-4 w-4" />
                    </a>
                </div>
            </div>
        </div>

        <div v-if="isLoading || activeTab !== renderedTab" class="mb-6 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-700">
            Loading report data...
        </div>

        <div v-if="renderedTab === 'ordering_timeliness'" class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Scheduled Rows</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatNumber(totals.scheduled) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Yes</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-700">{{ formatNumber(totals.yes) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">No</p>
                <p class="mt-1 text-2xl font-semibold text-red-700">{{ formatNumber(totals.no) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">No Order</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatNumber(totals.no_order) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Adoption Rate</p>
                <p class="mt-1 text-2xl font-semibold text-blue-700">{{ formatRate(totals.adoption_rate) }}</p>
            </div>
        </div>

        <div v-else-if="renderedTab === 'commit_order_timeliness'" class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Order Rows</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatNumber(totals.orders) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Combined Rate</p>
                <p class="mt-1 text-2xl font-semibold text-blue-700">{{ formatRate(totals.combined_adoption_rate) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">FG Yes / No / NA</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatNumber(totals.fg_yes) }} / {{ formatNumber(totals.fg_no) }} / {{ formatNumber(totals.fg_na) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">FG Adoption Rate</p>
                <p class="mt-1 text-2xl font-semibold text-blue-700">{{ formatRate(totals.fg_adoption_rate) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Traded Yes / No / NA</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatNumber(totals.traded_yes) }} / {{ formatNumber(totals.traded_no) }} / {{ formatNumber(totals.traded_na) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Traded Adoption Rate</p>
                <p class="mt-1 text-2xl font-semibold text-blue-700">{{ formatRate(totals.traded_adoption_rate) }}</p>
            </div>
        </div>

        <div v-else-if="renderedTab === 'delivery_logging_timeliness'" class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Deliveries</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatNumber(totals.deliveries) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Yes</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-700">{{ formatNumber(totals.yes) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">No</p>
                <p class="mt-1 text-2xl font-semibold text-red-700">{{ formatNumber(totals.no) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">NA</p>
                <p class="mt-1 text-2xl font-semibold text-gray-700">{{ formatNumber(totals.na) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Adoption Rate</p>
                <p class="mt-1 text-2xl font-semibold text-blue-700">{{ formatRate(totals.adoption_rate) }}</p>
            </div>
        </div>

        <div v-else-if="renderedTab === 'sales_upload_timeliness'" class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Total Days</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatNumber(totals.days) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Yes</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-700">{{ formatNumber(totals.yes) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">No</p>
                <p class="mt-1 text-2xl font-semibold text-red-700">{{ formatNumber(totals.no) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Adoption Rate</p>
                <p class="mt-1 text-2xl font-semibold text-blue-700">{{ formatRate(totals.adoption_rate) }}</p>
            </div>
        </div>

        <div v-else-if="renderedTab === 'wastage_upload_timeliness'" class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Wastage Rows</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatNumber(totals.rows) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Uploaded Yes / No</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatNumber(totals.upload_yes) }} / {{ formatNumber(totals.upload_no) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Upload Rate</p>
                <p class="mt-1 text-2xl font-semibold text-blue-700">{{ formatRate(totals.upload_adoption_rate) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Approved Yes / No</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatNumber(totals.approval_yes) }} / {{ formatNumber(totals.approval_no) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Approval Rate</p>
                <p class="mt-1 text-2xl font-semibold text-blue-700">{{ formatRate(totals.approval_adoption_rate) }}</p>
            </div>
        </div>

        <div v-if="renderedTab === 'overall_adoption_rate' && renderedSearch" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Search is active. The Overall Adoption Rate is recalculated from the matching stores and may differ from the dashboard's unsearched selected-range rate.
        </div>

        <div v-if="renderedTab === 'overall_adoption_rate'" class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Stores</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatNumber(totals.stores) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Weeks</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatNumber(totals.weeks) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm text-gray-600">Overall Adoption Rate</p>
                <p class="mt-1 text-2xl font-semibold text-blue-700">{{ formatRate(totals.overall_rate) }}</p>
            </div>
        </div>

        <div v-if="renderedTab === 'overall_adoption_rate'" class="mb-6 overflow-hidden rounded-lg border border-blue-200 bg-white shadow-sm">
            <div class="border-b border-blue-100 bg-blue-50 px-4 py-3">
                <h3 class="text-sm font-semibold text-blue-900">All Selected Stores - Report-Tallied Indicator Rates</h3>
                <p class="mt-1 text-xs text-blue-700">Complete selected date range; these values match the corresponding detail report tabs.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="border-b px-4 py-3 text-left font-medium">No.</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Indicator</th>
                            <th class="border-b px-4 py-3 text-right font-medium">Selected Range Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="indicator in totals.indicator_rates || []" :key="indicator.no" class="hover:bg-gray-50">
                            <td class="border-b px-4 py-3">{{ indicator.no }}</td>
                            <td class="border-b px-4 py-3 font-medium text-gray-900">{{ indicator.indicator }}</td>
                            <td class="border-b px-4 py-3 text-right font-semibold text-blue-700 tabular-nums">{{ formatRate(indicator.rate) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="!['ordering_timeliness', 'commit_order_timeliness', 'delivery_logging_timeliness', 'sales_upload_timeliness', 'wastage_upload_timeliness', 'overall_adoption_rate'].includes(renderedTab)" class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center">
            <FileClock class="mx-auto h-9 w-9 text-gray-400" />
            <p class="mt-3 text-sm font-medium text-gray-700">This tab is not available yet.</p>
        </div>

        <div v-else class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table v-if="renderedTab === 'ordering_timeliness'" class="min-w-full border-separate border-spacing-0 text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="border-b px-4 py-3 text-left font-medium">Week No.</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Date Range</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Supplier Code</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Store</th>
                            <th class="border-b px-4 py-3 text-left font-medium">DAVID Delivery Date</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Plotted?</th>
                            <th class="border-b px-4 py-3 text-left font-medium min-w-[260px]">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in visibleRows" :key="row.row_key" class="border-b hover:bg-gray-50">
                            <td class="border-b px-4 py-3">{{ row.week_no }}</td>
                            <td class="border-b px-4 py-3">{{ row.date_range }}</td>
                            <td class="border-b px-4 py-3 font-medium text-gray-900">{{ row.supplier_code }}</td>
                            <td class="border-b px-4 py-3">{{ row.store }}</td>
                            <td class="border-b px-4 py-3">{{ row.david_delivery_date_display }}</td>
                            <td class="border-b px-4 py-3">
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium" :class="statusClass(row.plotted)">
                                    {{ row.plotted }}
                                </span>
                            </td>
                            <td class="border-b px-4 py-3">
                                <div v-if="canEditRemarks" class="flex items-start gap-2">
                                    <Textarea v-model="localRemarks[row.row_key]" rows="1" class="min-h-9 text-sm" />
                                    <Button size="icon" variant="outline" :disabled="savingRow === row.row_key" @click="saveRemark(row)">
                                        <Save class="h-4 w-4" />
                                    </Button>
                                </div>
                                <span v-else class="text-gray-700">{{ row.remarks || "" }}</span>
                            </td>
                        </tr>
                        <tr v-if="!visibleRows.length">
                            <td colspan="7" class="px-4 py-12 text-center text-gray-500">
                                <ClipboardCheck class="mx-auto mb-3 h-8 w-8 text-gray-400" />
                                No rows found for the selected filters.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table v-else-if="renderedTab === 'commit_order_timeliness'" class="min-w-full border-separate border-spacing-0 text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="border-b px-4 py-3 text-left font-medium">Week No.</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Date Range</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Store</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Supplier Code</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Delivery Date</th>
                            <th class="border-b px-4 py-3 text-left font-medium">DAVID Commit Date (FG)</th>
                            <th class="border-b px-4 py-3 text-left font-medium">On Time? For FG</th>
                            <th class="border-b px-4 py-3 text-left font-medium">DAVID Commit Date (Traded)</th>
                            <th class="border-b px-4 py-3 text-left font-medium">On Time? For Traded</th>
                            <th class="border-b px-4 py-3 text-left font-medium min-w-[260px]">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in visibleRows" :key="row.row_key" class="border-b hover:bg-gray-50">
                            <td class="border-b px-4 py-3">{{ row.week_no }}</td>
                            <td class="border-b px-4 py-3">{{ row.date_range }}</td>
                            <td class="border-b px-4 py-3">{{ row.store }}</td>
                            <td class="border-b px-4 py-3 font-medium text-gray-900">{{ row.supplier_code }}</td>
                            <td class="border-b px-4 py-3">{{ row.delivery_date_display }}</td>
                            <td class="border-b px-4 py-3">{{ row.fg_commit_date_display }}</td>
                            <td class="border-b px-4 py-3">
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium" :class="statusClass(row.fg_on_time)">
                                    {{ row.fg_on_time }}
                                </span>
                            </td>
                            <td class="border-b px-4 py-3">{{ row.traded_commit_date_display }}</td>
                            <td class="border-b px-4 py-3">
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium" :class="statusClass(row.traded_on_time)">
                                    {{ row.traded_on_time }}
                                </span>
                            </td>
                            <td class="border-b px-4 py-3">
                                <div v-if="canEditRemarks" class="flex items-start gap-2">
                                    <Textarea v-model="localRemarks[row.row_key]" rows="1" class="min-h-9 text-sm" />
                                    <Button size="icon" variant="outline" :disabled="savingRow === row.row_key" @click="saveRemark(row)">
                                        <Save class="h-4 w-4" />
                                    </Button>
                                </div>
                                <span v-else class="text-gray-700">{{ row.remarks || "" }}</span>
                            </td>
                        </tr>
                        <tr v-if="!visibleRows.length">
                            <td colspan="10" class="px-4 py-12 text-center text-gray-500">
                                <ClipboardCheck class="mx-auto mb-3 h-8 w-8 text-gray-400" />
                                No rows found for the selected filters.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table v-else-if="renderedTab === 'delivery_logging_timeliness'" class="min-w-full border-separate border-spacing-0 text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="border-b px-4 py-3 text-left font-medium">Week No.</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Date Range</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Supplier Code</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Store</th>
                            <th class="border-b px-4 py-3 text-left font-medium">DR Number</th>
                            <th class="border-b px-4 py-3 text-left font-medium">SO/PO Number</th>
                            <th class="border-b px-4 py-3 text-left font-medium">SAP DR Date</th>
                            <th class="border-b px-4 py-3 text-left font-medium">DAVID Logging Date</th>
                            <th class="border-b px-4 py-3 text-left font-medium">On Time?</th>
                            <th class="border-b px-4 py-3 text-left font-medium min-w-[260px]">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in visibleRows" :key="row.row_key" class="border-b hover:bg-gray-50">
                            <td class="border-b px-4 py-3">{{ row.week_no }}</td>
                            <td class="border-b px-4 py-3">{{ row.date_range }}</td>
                            <td class="border-b px-4 py-3 font-medium text-gray-900">{{ row.supplier_code }}</td>
                            <td class="border-b px-4 py-3">{{ row.store }}</td>
                            <td class="border-b px-4 py-3">{{ row.dr_number }}</td>
                            <td class="border-b px-4 py-3">{{ row.so_po_number }}</td>
                            <td class="border-b px-4 py-3">{{ row.sap_dr_date_display }}</td>
                            <td class="border-b px-4 py-3">{{ row.david_logging_date_display }}</td>
                            <td class="border-b px-4 py-3">
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium" :class="statusClass(row.on_time)">
                                    {{ row.on_time }}
                                </span>
                            </td>
                            <td class="border-b px-4 py-3">
                                <div v-if="canEditRemarks" class="flex items-start gap-2">
                                    <Textarea v-model="localRemarks[row.row_key]" rows="1" class="min-h-9 text-sm" />
                                    <Button size="icon" variant="outline" :disabled="savingRow === row.row_key" @click="saveRemark(row)">
                                        <Save class="h-4 w-4" />
                                    </Button>
                                </div>
                                <span v-else class="text-gray-700">{{ row.remarks || "" }}</span>
                            </td>
                        </tr>
                        <tr v-if="!visibleRows.length">
                            <td colspan="10" class="px-4 py-12 text-center text-gray-500">
                                <ClipboardCheck class="mx-auto mb-3 h-8 w-8 text-gray-400" />
                                No rows found for the selected filters.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table v-else-if="renderedTab === 'sales_upload_timeliness'" class="min-w-full border-separate border-spacing-0 text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="border-b px-4 py-3 text-left font-medium">Week No.</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Date Range</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Date of Sales</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Date of Actual Sales Upload</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Store</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Sales Report Uploaded?</th>
                            <th class="border-b px-4 py-3 text-left font-medium min-w-[260px]">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in visibleRows" :key="row.row_key" class="border-b hover:bg-gray-50">
                            <td class="border-b px-4 py-3">{{ row.week_no }}</td>
                            <td class="border-b px-4 py-3">{{ row.date_range }}</td>
                            <td class="border-b px-4 py-3">{{ row.date_of_sales_display }}</td>
                            <td class="border-b px-4 py-3">{{ row.actual_sales_upload_date_display }}</td>
                            <td class="border-b px-4 py-3">{{ row.store }}</td>
                            <td class="border-b px-4 py-3">
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium" :class="statusClass(row.sales_report_uploaded)">
                                    {{ row.sales_report_uploaded }}
                                </span>
                            </td>
                            <td class="border-b px-4 py-3">
                                <div v-if="canEditRemarks" class="flex items-start gap-2">
                                    <Textarea v-model="localRemarks[row.row_key]" rows="1" class="min-h-9 text-sm" />
                                    <Button size="icon" variant="outline" :disabled="savingRow === row.row_key" @click="saveRemark(row)">
                                        <Save class="h-4 w-4" />
                                    </Button>
                                </div>
                                <span v-else class="text-gray-700">{{ row.remarks || "" }}</span>
                            </td>
                        </tr>
                        <tr v-if="!visibleRows.length">
                            <td colspan="7" class="px-4 py-12 text-center text-gray-500">
                                <ClipboardCheck class="mx-auto mb-3 h-8 w-8 text-gray-400" />
                                No rows found for the selected filters.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table v-else-if="renderedTab === 'wastage_upload_timeliness'" class="min-w-full border-separate border-spacing-0 text-sm">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="border-b px-4 py-3 text-left font-medium">Week No.</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Date Range</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Wastage #</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Status</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Date of Wastage</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Date of Wastage Upload</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Level 1 Approval</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Level 2 Approval</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Store</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Wastage Report Uploaded?</th>
                            <th class="border-b px-4 py-3 text-left font-medium">Wastage Report Approved?</th>
                            <th class="border-b px-4 py-3 text-left font-medium min-w-[260px]">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in visibleRows" :key="row.row_key" class="border-b hover:bg-gray-50">
                            <td class="border-b px-4 py-3">{{ row.week_no }}</td>
                            <td class="border-b px-4 py-3">{{ row.date_range }}</td>
                            <td class="border-b px-4 py-3 font-medium text-gray-900">{{ row.wastage_no }}</td>
                            <td class="border-b px-4 py-3">{{ row.status }}</td>
                            <td class="border-b px-4 py-3">{{ row.date_of_wastage_display }}</td>
                            <td class="border-b px-4 py-3">{{ row.date_of_wastage_upload_display }}</td>
                            <td class="border-b px-4 py-3">{{ row.level_1_approval }}</td>
                            <td class="border-b px-4 py-3">{{ row.level_2_approval }}</td>
                            <td class="border-b px-4 py-3">{{ row.store }}</td>
                            <td class="border-b px-4 py-3">
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium" :class="statusClass(row.wastage_report_uploaded)">
                                    {{ row.wastage_report_uploaded }}
                                </span>
                            </td>
                            <td class="border-b px-4 py-3">
                                <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium" :class="statusClass(row.wastage_report_approved)">
                                    {{ row.wastage_report_approved }}
                                </span>
                            </td>
                            <td class="border-b px-4 py-3">
                                <div v-if="canEditRemarks" class="flex items-start gap-2">
                                    <Textarea v-model="localRemarks[row.row_key]" rows="1" class="min-h-9 text-sm" />
                                    <Button size="icon" variant="outline" :disabled="savingRow === row.row_key" @click="saveRemark(row)">
                                        <Save class="h-4 w-4" />
                                    </Button>
                                </div>
                                <span v-else class="text-gray-700">{{ row.remarks || "" }}</span>
                            </td>
                        </tr>
                        <tr v-if="!visibleRows.length">
                            <td colspan="12" class="px-4 py-12 text-center text-gray-500">
                                <ClipboardCheck class="mx-auto mb-3 h-8 w-8 text-gray-400" />
                                No rows found for the selected filters.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-else-if="renderedTab === 'overall_adoption_rate'" class="space-y-6 p-4">
                    <div v-for="section in overallSections" :key="section.row_key" class="overflow-hidden rounded-lg border border-gray-200">
                        <div class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                            <h3 class="text-sm font-semibold text-gray-900">{{ section.store }}</h3>
                            <p v-if="section.store_code && section.store_code !== section.store" class="mt-1 text-xs text-gray-500">{{ section.store_code }}</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full border-separate border-spacing-0 text-sm">
                                <thead class="bg-white text-xs uppercase text-gray-500">
                                    <tr>
                                        <th class="border-b px-4 py-3 text-left font-medium">No.</th>
                                        <th class="border-b px-4 py-3 text-left font-medium">Indicator</th>
                                        <th class="border-b px-4 py-3 text-left font-medium">Responsible</th>
                                        <th v-for="week in section.weeks" :key="week.key" class="border-b px-4 py-3 text-right font-medium">
                                            {{ week.label }}
                                        </th>
                                        <th class="border-b px-4 py-3 text-right font-medium">Overall</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="indicator in section.indicators" :key="indicator.no" class="hover:bg-gray-50">
                                        <td class="border-b px-4 py-3 tabular-nums">{{ indicator.no }}</td>
                                        <td class="border-b px-4 py-3 font-medium text-gray-900">{{ indicator.indicator }}</td>
                                        <td class="whitespace-pre-line border-b px-4 py-3 text-gray-700">{{ indicator.responsible }}</td>
                                        <td v-for="week in section.weeks" :key="week.key" class="border-b px-4 py-3 text-right tabular-nums">
                                            {{ formatRate(indicator.rates[week.key]) }}
                                        </td>
                                        <td class="border-b px-4 py-3 text-right tabular-nums">{{ formatRate(indicator.overall_rate) }}</td>
                                    </tr>
                                    <tr class="bg-blue-50 font-semibold text-blue-900">
                                        <td class="border-b px-4 py-3"></td>
                                        <td class="border-b px-4 py-3">Average</td>
                                        <td class="border-b px-4 py-3"></td>
                                        <td v-for="week in section.weeks" :key="week.key" class="border-b px-4 py-3 text-right tabular-nums">
                                            {{ formatRate(section.weekly_averages?.[week.key]) }}
                                        </td>
                                        <td class="border-b px-4 py-3 text-right tabular-nums">{{ formatRate(section.overall_rate) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="!overallSections.length" class="px-4 py-12 text-center text-gray-500">
                        <ClipboardCheck class="mx-auto mb-3 h-8 w-8 text-gray-400" />
                        No rows found for the selected filters.
                    </div>
                </div>
            </div>
            <div class="border-t border-gray-200 p-4">
                <Pagination :data="rows" />
            </div>
        </div>
    </Layout>
</template>
