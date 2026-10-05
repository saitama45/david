<script setup>
import { formatReportNumber } from '@/lib/reportNumbers';
import { ref, reactive, watch, computed } from "vue";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
import axios from "axios";
import Dialog from "primevue/dialog";
import { Calendar, Search, RotateCcw, Filter, ChevronDown, Package, CalendarDays, Building2, TrendingUp, TrendingDown, ClipboardCheck, Info, FileText, FileSpreadsheet, Truck, ArrowUpDown, ArrowUp, ArrowDown } from "lucide-vue-next";
import SearchableSelect from "@/components/ui/select/SearchableSelect.vue";
import Pagination from "@/components/table/Pagination.vue";

const props = defineProps({
    movementData: {
        type: Array,
        required: true,
    },
    sapItems: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    branches: {
        type: Array,
        required: true,
    },
    suppliers: {
        type: Array,
        required: true,
    }
});

const isFiltersCollapsed = ref(false);
const isLoading = ref(false);
const searchFocus = ref(false);

const perPageOptions = [
    { label: '25 rows', value: 25 },
    { label: '50 rows', value: 50 },
    { label: '100 rows', value: 100 },
    { label: '200 rows', value: 200 }
];

const branchOptions = computed(() => {
    return props.branches.map(branch => ({
        label: `${branch.name} (${branch.branch_code})`,
        value: branch.id,
        searchTerms: [branch.name, branch.branch_code].join(' ').toLowerCase()
    }));
});

const supplierOptions = computed(() => {
    return props.suppliers.map(supplier => ({
        label: supplier.label,
        value: supplier.value,
        searchTerms: supplier.label.toLowerCase(),
    }));
});

const dateFrom = ref(props.filters.date_from || '');
const dateTo = ref(props.filters.date_to || '');
const branchId = ref(props.filters.branch_id || '');
const supplierCode = ref(props.filters.supplier_code || '');
const search = ref(props.filters.search || '');
const perPage = ref(props.filters.per_page || 50);
const sortField = ref(props.filters.sort_field || '');
const sortDirection = ref(props.filters.sort_direction || 'asc');

const handleSort = (field) => {
    if (sortField.value === field) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = field;
        sortDirection.value = 'asc';
    }
    updateFilters();
};

const updateFilters = () => {
    isLoading.value = true;
    router.get(
        route('reports.inventory-movement.index'),
        {
            date_from: dateFrom.value,
            date_to: dateTo.value,
            branch_id: branchId.value || null,
            supplier_code: supplierCode.value || null,
            search: search.value,
            per_page: perPage.value,
            sort_field: sortField.value,
            sort_direction: sortDirection.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => isLoading.value = true,
            onFinish: () => isLoading.value = false,
        }
    );
};

const handleSearch = () => {
    updateFilters();
};

const resetFilters = () => {
    dateFrom.value = '';
    dateTo.value = '';
    branchId.value = props.branches[0]?.id || '';
    supplierCode.value = '';
    search.value = '';
    perPage.value = 50;
    sortField.value = '';
    sortDirection.value = 'asc';
    updateFilters();
};

// ---- The transactions behind a figure: a click on it opens them in a popup.
// label is the column as the table heads it; date says which date the report files a line under.
const detailColumns = {
    ordered: { label: 'Ordered', date: 'Order Date' },
    committed: { label: 'Committed', date: 'Order Date' },
    received: { label: 'Received', date: 'Order Date' },
    beg_bal: { label: 'Beg Bal Qty', date: 'MEC Date' },
    sales: { label: 'Sales Qty', date: 'Sales Date' },
    wastage: { label: 'Wastage Qty', date: 'Date Filed' },
    supplies: { label: 'Supplies Used', date: 'MEC Date' },
    interco_in: { label: 'Inbound Interco', date: 'Order Date' },
    interco_out: { label: 'Outbound Interco', date: 'Order Date' },
};

const detail = reactive({
    visible: false,
    loading: false,
    error: '',
    item: null,
    metric: null,
    // The period and store the figure was worked out for, kept with it in case the filters are edited meanwhile
    filters: null,
    data: null,
});

const loadDetails = async (page = 1) => {
    detail.loading = true;
    detail.error = '';

    try {
        const response = await axios.get(route('reports.inventory-movement.details'), {
            params: { ...detail.filters, sap_code: detail.item.sap_code, metric: detail.metric, page },
        });
        detail.data = response.data;
    } catch (error) {
        detail.error = error.response?.data?.message || 'The transactions could not be loaded. Please try again.';
    } finally {
        detail.loading = false;
    }
};

const openDetails = (item, metric) => {
    detail.item = item;
    detail.metric = metric;
    detail.data = null;
    detail.filters = { branch_id: props.filters.branch_id, date_from: props.filters.date_from, date_to: props.filters.date_to };
    detail.visible = true;
    loadDetails();
};

const detailPages = computed(() => (detail.data ? Math.max(1, Math.ceil(detail.data.total_rows / detail.data.per_page)) : 1));
const detailRange = computed(() => {
    if (!detail.data || detail.data.total_rows === 0) return '';
    const first = (detail.data.page - 1) * detail.data.per_page + 1;

    return `${first} to ${first + detail.data.rows.length - 1} of ${detail.data.total_rows}`;
});
const detailStore = computed(() => {
    const branch = props.branches.find((each) => String(each.id) === String(detail.filters?.branch_id));

    return branch ? `${branch.name} (${branch.branch_code})` : '';
});

const exportParams = () => new URLSearchParams({
    date_from: dateFrom.value,
    date_to: dateTo.value,
    branch_id: branchId.value,
    supplier_code: supplierCode.value,
    search: search.value,
});

const exportPdf = () => {
    window.open(route('reports.inventory-movement.export-pdf') + '?' + exportParams().toString(), '_blank');
};

const exportExcel = () => {
    window.location.href = route('reports.inventory-movement.export-excel') + '?' + exportParams().toString();
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
};

// Every quantity, amount and percentage prints with four decimals, as on all the reports.
const formatNumber = formatReportNumber;
</script>

<template>
    <Layout heading="Inventory Movement Report">
        <template #header-actions>
            <Button @click="exportPdf" variant="outline" class="flex items-center gap-2 border-blue-200 text-blue-700 hover:bg-blue-50">
                <FileText class="w-4 h-4" />
                Export PDF
            </Button>
            <Button @click="exportExcel" variant="outline" class="flex items-center gap-2 border-green-200 text-green-700 hover:bg-green-50">
                <FileSpreadsheet class="w-4 h-4" />
                Export Excel
            </Button>
        </template>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6">
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4 items-end">
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <Building2 class="w-4 h-4" />
                            Branch
                        </label>
                        <SearchableSelect
                            v-model="branchId"
                            placeholder="Select Branch"
                            :options="branchOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                        />
                    </div>

                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <Truck class="w-4 h-4" />
                            Supplier
                        </label>
                        <SearchableSelect
                            v-model="supplierCode"
                            placeholder="All Suppliers"
                            :options="supplierOptions"
                            optionLabel="label"
                            optionValue="value"
                            clearable
                            class="w-full"
                        />
                    </div>

                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <CalendarDays class="w-4 h-4" />
                            From Date
                        </label>
                        <Input
                            type="date"
                            v-model="dateFrom"
                            class="w-full border-gray-300 focus:border-blue-500 rounded-lg"
                        />
                    </div>

                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <CalendarDays class="w-4 h-4" />
                            To Date
                        </label>
                        <Input
                            type="date"
                            v-model="dateTo"
                            class="w-full border-gray-300 focus:border-blue-500 rounded-lg"
                        />
                    </div>

                    <div class="space-y-2 lg:col-span-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <Search class="w-4 h-4" />
                            Search Item
                        </label>
                        <div class="flex gap-2">
                            <Input
                                v-model="search"
                                placeholder="Search by SAP Code or Description..."
                                class="flex-1 border-gray-300 focus:border-blue-500 rounded-lg"
                                @keyup.enter="handleSearch"
                            />
                            <Button @click="handleSearch" class="flex items-center gap-2">
                                <Search class="w-4 h-4" />
                                Search
                            </Button>
                            <Button @click="resetFilters" variant="outline" class="flex items-center gap-2">
                                <RotateCcw class="w-4 h-4" />
                                Reset
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p class="mb-3 text-sm text-gray-600">
            All totals use the SAP base unit shown in the UOM column. Procurement quantities in their original units appear below the totals (for example, 36 Gm sold of a 1,000 Gm Bag = 0.0360 Bag).
            Supplies Used applies to Operating / Cleaning Supplies items: the usage the month end count shows once sales, wastage and transfers are accounted for.
            A Wastage Qty marked <span class="rounded bg-amber-100 px-1 py-0.5 text-[11px] font-semibold text-amber-800">Sub-Prep</span> includes a wasted Sub-Prep, charged to this raw material through its BOM.
            Click any underlined figure to see the transactions behind it.
        </p>
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-visible">
            <div v-if="isLoading" class="absolute inset-0 bg-white/80 flex items-center justify-center z-10">
                <div class="flex items-center gap-3 text-gray-600">
                    <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600"></div>
                    <span>Loading Data...</span>
                </div>
            </div>

            <div class="overflow-visible">
                <table class="min-w-full border-separate border-spacing-0">
                    <thead class="sticky -top-4 lg:-top-6 z-20 bg-gray-50 shadow-sm">
                        <tr>
                            <th colspan="4" class="px-3 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider border-r border-gray-200 bg-gray-100">Item Info</th>
                            <th colspan="3" class="px-3 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider border-r border-gray-200 bg-blue-50">Procurement (Date Range)</th>
                            <th class="px-3 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider border-r border-gray-200 bg-emerald-50">Beginning</th>
                            <th colspan="5" class="px-3 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider border-r border-gray-200 bg-orange-50">Deductions / Transfers</th>
                            <th colspan="3" class="px-3 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider bg-purple-50">Final Balance</th>
                        </tr>
                        <tr class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold">
                            <th @click="handleSort('supplier')" class="px-3 py-3 text-left border-r border-gray-200 min-w-[160px] cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-between gap-2">
                                    Supplier
                                    <ArrowUp v-if="sortField === 'supplier' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'supplier' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('sap_code')" class="px-3 py-3 text-left border-r border-gray-200 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-between gap-2">
                                    SAP Code
                                    <ArrowUp v-if="sortField === 'sap_code' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'sap_code' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('item_description')" class="px-3 py-3 text-left border-r border-gray-200 min-w-[200px] cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-between gap-2">
                                    Item Description
                                    <ArrowUp v-if="sortField === 'item_description' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'item_description' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('uom')" class="px-3 py-3 text-center border-r border-gray-200 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-2">
                                    UOM
                                    <ArrowUp v-if="sortField === 'uom' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'uom' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('ordered_qty')" class="px-3 py-3 text-center border-r border-gray-200 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-2">
                                    Ordered
                                    <ArrowUp v-if="sortField === 'ordered_qty' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'ordered_qty' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('committed_qty')" class="px-3 py-3 text-center border-r border-gray-200 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-2">
                                    Committed
                                    <ArrowUp v-if="sortField === 'committed_qty' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'committed_qty' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('received_qty')" class="px-3 py-3 text-center border-r border-gray-200 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-2">
                                    Received
                                    <ArrowUp v-if="sortField === 'received_qty' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'received_qty' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('beg_bal_qty')" class="px-3 py-3 text-center border-r border-gray-200 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-2">
                                    Beg Bal Qty
                                    <ArrowUp v-if="sortField === 'beg_bal_qty' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'beg_bal_qty' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('sales_qty')" class="px-3 py-3 text-center border-r border-gray-200 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-2">
                                    Sales Qty
                                    <ArrowUp v-if="sortField === 'sales_qty' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'sales_qty' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('wastage_qty')" class="px-3 py-3 text-center border-r border-gray-200 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-2">
                                    Wastage Qty
                                    <ArrowUp v-if="sortField === 'wastage_qty' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'wastage_qty' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('supplies_qty')" class="px-3 py-3 text-center border-r border-gray-200 cursor-pointer hover:bg-gray-100 group transition-colors" title="Operating / Cleaning Supplies used, from the month end count">
                                <div class="flex items-center justify-center gap-2">
                                    Supplies Used
                                    <ArrowUp v-if="sortField === 'supplies_qty' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'supplies_qty' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('interco_in_qty')" class="px-3 py-3 text-center border-r border-gray-200 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-2">
                                    Inbound Interco
                                    <ArrowUp v-if="sortField === 'interco_in_qty' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'interco_in_qty' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('interco_out_qty')" class="px-3 py-3 text-center border-r border-gray-200 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-2">
                                    Outbound Interco
                                    <ArrowUp v-if="sortField === 'interco_out_qty' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'interco_out_qty' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('theoretical_qty')" class="px-3 py-3 text-center border-r border-gray-200 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-2">
                                    Theoretical SOH
                                    <ArrowUp v-if="sortField === 'theoretical_qty' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'theoretical_qty' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('actual_mec')" class="px-3 py-3 text-center border-r border-gray-200 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-2">
                                    Actual MEC
                                    <ArrowUp v-if="sortField === 'actual_mec' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'actual_mec' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('variance_qty')" class="px-3 py-3 text-center cursor-pointer hover:bg-gray-100 group transition-colors" title="Actual MEC - Theoretical SOH">
                                <div class="flex items-center justify-center gap-2">
                                    Variance
                                    <ArrowUp v-if="sortField === 'variance_qty' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'variance_qty' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <tr v-if="movementData.length === 0" class="hover:bg-gray-50">
                            <td colspan="16" class="text-center py-12 text-gray-500">
                                <div class="flex flex-col items-center">
                                    <Package class="w-12 h-12 text-gray-300 mb-3" />
                                    <span class="text-lg font-medium">No movement data found</span>
                                    <span class="text-sm text-gray-400 mt-1">Select a branch and date range to view movement</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="item in movementData" :key="item.sap_code + item.uom" class="hover:bg-gray-50 transition-colors text-xs">
                            <td class="px-3 py-4 text-gray-900 border-r border-gray-100">{{ item.supplier || '-' }}</td>
                            <td class="px-3 py-4 font-mono text-gray-900 border-r border-gray-100">{{ item.sap_code }}</td>
                            <td class="px-3 py-4 text-gray-900 border-r border-gray-100">{{ item.item_description }}</td>
                            <td class="px-3 py-4 text-center text-gray-500 border-r border-gray-100 italic">
                                {{ item.uom }}
                                <div
                                    v-if="item.unconverted_units?.length"
                                    class="mt-1 not-italic text-[10px] text-amber-600"
                                    :title="`No SAP conversion from ${item.unconverted_units.join(', ')} to ${item.uom}; those quantities are excluded.`"
                                >
                                    Excl. {{ item.unconverted_units.join(', ') }}
                                </div>
                            </td>
                            <td
                                v-for="metric in ['ordered', 'committed', 'received']"
                                :key="metric"
                                class="px-3 py-4 text-center border-r border-gray-100"
                                :class="metric === 'received' ? 'font-medium text-blue-600 bg-blue-50/30' : 'text-gray-600'"
                            >
                                <button type="button" class="underline decoration-dotted underline-offset-2 hover:decoration-solid focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 rounded-sm" title="Show the transactions" @click="openDetails(item, metric)">{{ formatNumber(item[`${metric}_qty`]) }}</button>
                                <div
                                    v-for="source in (item.procurement_sources?.[metric] || []).filter((s) => s.uom.toUpperCase() !== item.uom.toUpperCase())"
                                    :key="source.uom"
                                    class="mt-1 text-[10px] font-normal text-gray-500 whitespace-nowrap"
                                    :title="`1 ${source.uom} = ${formatNumber(source.conversion_factor)} ${item.uom}`"
                                >
                                    {{ formatNumber(source.quantity) }} {{ source.uom }}
                                </div>
                            </td>
                            <td class="px-3 py-4 text-center font-medium text-emerald-600 border-r border-gray-100 bg-emerald-50/30"><button type="button" class="underline decoration-dotted underline-offset-2 hover:decoration-solid focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 rounded-sm" title="Show the transactions" @click="openDetails(item, 'beg_bal')">{{ formatNumber(item.beg_bal_qty) }}</button></td>
                            <td class="px-3 py-4 text-center font-medium text-red-600 border-r border-gray-100 bg-red-50/30"><button type="button" class="underline decoration-dotted underline-offset-2 hover:decoration-solid focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 rounded-sm" title="Show the transactions" @click="openDetails(item, 'sales')">{{ formatNumber(item.sales_qty) }}</button></td>
                            <td class="px-3 py-4 text-center font-medium text-orange-600 border-r border-gray-100 bg-orange-50/30">
                                <button type="button" class="underline decoration-dotted underline-offset-2 hover:decoration-solid focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 rounded-sm" title="Show the transactions" @click="openDetails(item, 'wastage')">{{ formatNumber(item.wastage_qty) }}</button>
                                <!-- The part that is a wasted Sub-Prep, charged to this raw material through its BOM -->
                                <div
                                    v-for="subPrep in item.wastage_sub_preps || []"
                                    :key="subPrep.code"
                                    class="mt-1 text-[10px] font-normal text-amber-800 whitespace-nowrap"
                                    :title="`${formatNumber(subPrep.wasted_qty)} ${subPrep.uom} of ${subPrep.code} ${subPrep.description || ''} was wasted. Its BOM charges ${formatNumber(subPrep.quantity)} ${item.uom} of that to this item.`"
                                >
                                    <span class="rounded bg-amber-100 px-1 py-0.5 font-semibold">Sub-Prep</span>
                                    {{ formatNumber(subPrep.quantity) }} from {{ subPrep.description || subPrep.code }}
                                </div>
                            </td>
                            <td class="px-3 py-4 text-center border-r border-gray-100" :class="item.supplies_type ? 'font-medium text-amber-700 bg-amber-50/30' : 'text-gray-400'">
                                <button v-if="item.supplies_counted" type="button" class="underline decoration-dotted underline-offset-2 hover:decoration-solid focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 rounded-sm" title="Show how it was worked out" @click="openDetails(item, 'supplies')">{{ formatNumber(item.supplies_qty) }}</button>
                                <button v-else-if="item.supplies_type" type="button" class="text-[11px] italic text-gray-400 underline decoration-dotted underline-offset-2 hover:decoration-solid focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 rounded-sm" title="Show how it will be worked out" @click="openDetails(item, 'supplies')">Awaiting MEC</button>
                                <template v-else>-</template>
                                <div v-if="item.supplies_type" class="mt-1 text-[10px] font-normal text-gray-500 whitespace-nowrap">{{ item.supplies_type }}</div>
                            </td>
                            <td class="px-3 py-4 text-center text-gray-600 border-r border-gray-100"><button type="button" class="underline decoration-dotted underline-offset-2 hover:decoration-solid focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 rounded-sm" title="Show the transactions" @click="openDetails(item, 'interco_in')">{{ formatNumber(item.interco_in_qty) }}</button></td>
                            <td class="px-3 py-4 text-center text-gray-600 border-r border-gray-100"><button type="button" class="underline decoration-dotted underline-offset-2 hover:decoration-solid focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 rounded-sm" title="Show the transactions" @click="openDetails(item, 'interco_out')">{{ formatNumber(item.interco_out_qty) }}</button></td>
                            <td class="px-3 py-4 text-center font-bold text-gray-900 border-r border-gray-100 bg-purple-50/30">{{ formatNumber(item.theoretical_qty) }}</td>
                            <td class="px-3 py-4 text-center font-bold border-r border-gray-100" :class="item.actual_mec !== null ? 'text-indigo-600 bg-indigo-50/30' : 'text-gray-400 font-normal italic'">
                                {{ item.actual_mec !== null ? formatNumber(item.actual_mec) : 'Not Available' }}
                            </td>
                            <td class="px-3 py-4 text-center font-bold" :class="item.variance_qty < 0 ? 'text-red-600' : item.variance_qty > 0 ? 'text-emerald-600' : 'text-gray-500'">
                                {{ formatNumber(item.variance_qty) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white px-6 py-4 border-t border-gray-200 mt-6 rounded-b-xl flex items-center justify-between">
            <div class="text-sm text-gray-600">
                Total {{ sapItems.total }} items tracked
            </div>
            <Pagination :data="sapItems" />
        </div>

        <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex gap-3">
                <Info class="w-5 h-5 text-blue-600 shrink-0 mt-0.5" />
                <div class="text-sm text-blue-800">
                    <p class="font-bold mb-1">Theoretical SOH Formula:</p>
                    <p>Beginning Balance + Received Qty + Inbound Interco - Sales Qty - Wastage Qty - Outbound Interco</p>
                    <p class="font-bold mt-2 mb-1">Variance Formula:</p>
                    <p>Actual MEC - Theoretical SOH</p>
                    <p class="mt-2 text-xs opacity-80">* Sales Qty is calculated based on BOM (Bill of Materials) linked to POS transactions.</p>
                    <p class="mt-1 text-xs opacity-80">* Wastage Qty includes the raw materials of a wasted Sub-Prep: the BOM Qty of the item for every unit of the Sub-Prep wasted. The line marked Sub-Prep under the figure shows that part and where it came from.</p>
                </div>
            </div>
        </div>

        <!-- The transactions behind the figure that was clicked -->
        <Dialog
            v-model:visible="detail.visible"
            modal
            :draggable="false"
            :header="detail.item ? `${detailColumns[detail.metric].label}: ${detail.item.sap_code} ${detail.item.item_description}` : ''"
            :style="{ width: '900px' }"
            :breakpoints="{ '1023px': '95vw' }"
        >
            <div v-if="detail.item" class="space-y-4 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-gray-50 px-3 py-2 text-gray-600">
                    <span>{{ detailStore }} · {{ formatDate(detail.filters.date_from) }} to {{ formatDate(detail.filters.date_to) }}</span>
                    <span v-if="detail.data" class="font-semibold text-gray-900">
                        Total: {{ formatNumber(detail.data.total) }} {{ detail.data.uom }}
                    </span>
                </div>

                <div v-if="detail.error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-red-700">{{ detail.error }}</div>
                <div v-else-if="!detail.data" class="py-8 text-center text-gray-500">Loading the transactions...</div>

                <template v-else>
                    <p v-if="detail.data.note" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">{{ detail.data.note }}</p>

                    <!-- Supplies Used has no transaction: it is worked out -->
                    <table v-if="detail.data.calculation.length" class="w-full max-w-md text-sm">
                        <tbody>
                            <tr
                                v-for="line in detail.data.calculation"
                                :key="line.label"
                                :class="line.sign === '=' ? 'border-t border-gray-300 font-semibold text-gray-900' : 'text-gray-700'"
                            >
                                <td class="w-6 py-1 text-center font-mono">{{ line.sign }}</td>
                                <td class="py-1">{{ line.label }}</td>
                                <td class="py-1 text-right font-mono">{{ formatNumber(line.value) }} {{ detail.data.uom }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="detail.data.rows.length" class="overflow-x-auto rounded-md border border-gray-200" :class="{ 'opacity-60': detail.loading }">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2 text-left">{{ detailColumns[detail.metric].date }}</th>
                                    <th class="px-3 py-2 text-left">Ref No.</th>
                                    <th class="px-3 py-2 text-left">Details</th>
                                    <th class="px-3 py-2 text-right">Qty</th>
                                    <th class="px-3 py-2 text-left">UOM</th>
                                    <th class="px-3 py-2 text-right">In {{ detail.data.uom }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(row, index) in detail.data.rows" :key="`${row.ref_no}-${index}`" class="hover:bg-gray-50">
                                    <td class="px-3 py-2 whitespace-nowrap text-gray-700">{{ row.date || '-' }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap font-mono">
                                        <a
                                            v-if="row.ref_url"
                                            :href="row.ref_url"
                                            target="_blank"
                                            rel="noopener"
                                            class="text-blue-600 underline hover:text-blue-800"
                                            title="Open this transaction in a new tab"
                                        >{{ row.ref_no || 'Open' }}</a>
                                        <span v-else>{{ row.ref_no || '-' }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-gray-700">{{ row.details }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ formatNumber(row.quantity) }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ row.uom || '-' }}</td>
                                    <td class="px-3 py-2 text-right font-mono font-medium text-gray-900">
                                        <template v-if="row.converted !== null">{{ formatNumber(row.converted) }}</template>
                                        <span v-else class="text-xs font-normal text-amber-700" :title="`No SAP conversion from ${row.uom} to ${detail.data.uom}; this line is not in the figure.`">Excluded</span>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50 font-semibold text-gray-900">
                                <tr>
                                    <td colspan="5" class="px-3 py-2 text-right">Total of all {{ detail.data.total_rows }} line{{ detail.data.total_rows === 1 ? '' : 's' }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ formatNumber(detail.data.total) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p v-else-if="!detail.data.calculation.length && !detail.data.note" class="py-6 text-center text-gray-500">
                        No transactions make up this figure in the period.
                    </p>

                    <p v-if="detail.data.unconverted_units.length" class="text-xs text-amber-700">
                        Lines in {{ detail.data.unconverted_units.join(', ') }} have no SAP conversion to {{ detail.data.uom }} and are not in the figure.
                    </p>

                    <div v-if="detailPages > 1" class="flex items-center justify-between text-xs text-gray-600">
                        <span>Showing {{ detailRange }}</span>
                        <div class="flex items-center gap-2">
                            <Button variant="outline" size="sm" :disabled="detail.loading || detail.data.page <= 1" @click="loadDetails(detail.data.page - 1)">Previous</Button>
                            <span>Page {{ detail.data.page }} of {{ detailPages }}</span>
                            <Button variant="outline" size="sm" :disabled="detail.loading || detail.data.page >= detailPages" @click="loadDetails(detail.data.page + 1)">Next</Button>
                        </div>
                    </div>
                </template>
            </div>
        </Dialog>
    </Layout>
</template>
