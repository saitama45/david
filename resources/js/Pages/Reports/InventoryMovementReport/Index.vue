<script setup>
import { ref, watch, computed } from "vue";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
import { Calendar, Search, RotateCcw, Filter, ChevronDown, Package, CalendarDays, Building2, TrendingUp, TrendingDown, ClipboardCheck, Info, FileText, Truck, ArrowUpDown, ArrowUp, ArrowDown } from "lucide-vue-next";
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

const exportPdf = () => {
    const params = new URLSearchParams({
        date_from: dateFrom.value,
        date_to: dateTo.value,
        branch_id: branchId.value,
        supplier_code: supplierCode.value,
        search: search.value,
    });
    
    window.open(route('reports.inventory-movement.export-pdf') + '?' + params.toString(), '_blank');
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
};

const formatNumber = (num) => {
    if (num === null || num === undefined) return '0';
    return parseFloat(num).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 4
    });
};
</script>

<template>
    <Layout heading="Inventory Movement Report">
        <template #header-actions>
            <Button @click="exportPdf" variant="outline" class="flex items-center gap-2 border-blue-200 text-blue-700 hover:bg-blue-50">
                <FileText class="w-4 h-4" />
                Export PDF
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

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-visible">
            <div v-if="isLoading" class="absolute inset-0 bg-white/80 flex items-center justify-center z-10">
                <div class="flex items-center gap-3 text-gray-600">
                    <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600"></div>
                    <span>Loading Data...</span>
                </div>
            </div>

            <div class="overflow-visible">
                <table class="min-w-full border-separate border-spacing-0">
                    <thead class="sticky top-0 z-20 bg-gray-50 shadow-sm">
                        <tr>
                            <th colspan="4" class="px-3 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider border-r border-gray-200 bg-gray-100">Item Info</th>
                            <th colspan="3" class="px-3 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider border-r border-gray-200 bg-blue-50">Procurement (Date Range)</th>
                            <th class="px-3 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider border-r border-gray-200 bg-emerald-50">Beginning</th>
                            <th colspan="4" class="px-3 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider border-r border-gray-200 bg-orange-50">Deductions / Transfers</th>
                            <th colspan="2" class="px-3 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider bg-purple-50">Final Balance</th>
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
                            <th @click="handleSort('actual_mec')" class="px-3 py-3 text-center cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-2">
                                    Actual MEC
                                    <ArrowUp v-if="sortField === 'actual_mec' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'actual_mec' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-400 group-hover:text-blue-400" />
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <tr v-if="movementData.length === 0" class="hover:bg-gray-50">
                            <td colspan="14" class="text-center py-12 text-gray-500">
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
                            <td class="px-3 py-4 text-center text-gray-500 border-r border-gray-100 italic">{{ item.uom }}</td>
                            <td class="px-3 py-4 text-center text-gray-600 border-r border-gray-100">{{ formatNumber(item.ordered_qty) }}</td>
                            <td class="px-3 py-4 text-center text-gray-600 border-r border-gray-100">{{ formatNumber(item.committed_qty) }}</td>
                            <td class="px-3 py-4 text-center font-medium text-blue-600 border-r border-gray-100 bg-blue-50/30">{{ formatNumber(item.received_qty) }}</td>
                            <td class="px-3 py-4 text-center font-medium text-emerald-600 border-r border-gray-100 bg-emerald-50/30">{{ formatNumber(item.beg_bal_qty) }}</td>
                            <td class="px-3 py-4 text-center font-medium text-red-600 border-r border-gray-100 bg-red-50/30">{{ formatNumber(item.sales_qty) }}</td>
                            <td class="px-3 py-4 text-center font-medium text-orange-600 border-r border-gray-100 bg-orange-50/30">{{ formatNumber(item.wastage_qty) }}</td>
                            <td class="px-3 py-4 text-center text-gray-600 border-r border-gray-100">{{ formatNumber(item.interco_in_qty) }}</td>
                            <td class="px-3 py-4 text-center text-gray-600 border-r border-gray-100">{{ formatNumber(item.interco_out_qty) }}</td>
                            <td class="px-3 py-4 text-center font-bold text-gray-900 border-r border-gray-100 bg-purple-50/30">{{ formatNumber(item.theoretical_qty) }}</td>
                            <td class="px-3 py-4 text-center font-bold" :class="item.actual_mec !== null ? 'text-indigo-600 bg-indigo-50/30' : 'text-gray-400 font-normal italic'">
                                {{ item.actual_mec !== null ? formatNumber(item.actual_mec) : 'Not Available' }}
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
                    <p class="mt-2 text-xs opacity-80">* Sales Qty is calculated based on BOM (Bill of Materials) linked to POS transactions.</p>
                </div>
            </div>
        </div>
    </Layout>
</template>
