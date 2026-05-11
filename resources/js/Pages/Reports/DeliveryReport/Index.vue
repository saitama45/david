<script setup>
import { ref, watch, computed } from "vue";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { useSelectOptions } from "@/composables/useSelectOptions";
import { Calendar, Search, RotateCcw, Download, Filter, ChevronDown, ChevronUp, Package, CalendarDays, Building2, Badge as BadgeIcon, ChartColumnBig, Truck, ArrowUpDown, ArrowUp, ArrowDown } from "lucide-vue-next";
import { useAuth } from "@/composables/useAuth";
import MultiSelect from "primevue/multiselect";

const props = defineProps({
    deliveryData: {
        type: Array,
        required: true,
    },
    paginatedData: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    stores: {
        type: Array,
        required: true,
    },
    assignedStoreIds: {
        type: Array,
        required: true,
    },
    totals: {
        type: Object,
        required: true,
        default: () => ({
            quantity_ordered: 0,
            quantity_committed: 0,
            quantity_received: 0
        })
    }
});

// Reactive states
const isFiltersCollapsed = ref(false);
const searchFocus = ref(false);
const isLoading = ref(false);

// Per page options with proper labels
const perPageOptions = [
    { label: '25 rows', value: 25 },
    { label: '50 rows', value: 50 },
    { label: '100 rows', value: 100 },
    { label: '200 rows', value: 200 }
];

// Store options with proper formatting
const storeOptions = computed(() => {
    return props.stores.map(store => ({
        label: `${store.name} (${store.brand_code})`,
        value: store.id,
        searchTerms: [store.name, store.brand_code].join(' ').toLowerCase()
    }));
});

// Initialize filters with default values
const dateFrom = ref(props.filters.date_from || '');
const dateTo = ref(props.filters.date_to || '');
const storeIds = ref(props.filters.store_ids || []);
const search = ref(props.filters.search || '');
const perPage = ref(props.filters.per_page || 50);

// Column filters
const filterExpectedDate = ref(props.filters.filter_expected_date || '');
const filterReceivedDate = ref(props.filters.filter_received_date || '');
const filterStore = ref(props.filters.filter_store || '');
const filterSupplier = ref(props.filters.filter_supplier || '');
const filterStatus = ref(props.filters.filter_status || '');
const filterItemCode = ref(props.filters.filter_item_code || '');
const filterItemDescription = ref(props.filters.filter_item_description || '');
const filterUom = ref(props.filters.filter_uom || '');
const filterSo = ref(props.filters.filter_so || '');
const filterDr = ref(props.filters.filter_dr || '');

// Sorting
const sortField = ref(props.filters.sort_field || '');
const sortDirection = ref(props.filters.sort_direction || 'asc');

const { hasAccess } = useAuth();

// Enhanced filter management with loading states
const updateFilters = () => {
    isLoading.value = true;
    router.get(
        route('reports.delivery-report.index'),
        {
            date_from: dateFrom.value,
            date_to: dateTo.value,
            store_ids: storeIds.value,
            search: search.value,
            per_page: perPage.value,
            sort_field: sortField.value,
            sort_direction: sortDirection.value,
            filter_expected_date: filterExpectedDate.value,
            filter_received_date: filterReceivedDate.value,
            filter_store: filterStore.value,
            filter_supplier: filterSupplier.value,
            filter_status: filterStatus.value,
            filter_item_code: filterItemCode.value,
            filter_item_description: filterItemDescription.value,
            filter_uom: filterUom.value,
            filter_so: filterSo.value,
            filter_dr: filterDr.value,
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

const handleSort = (field) => {
    if (sortField.value === field) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = field;
        sortDirection.value = 'asc';
    }
    updateFilters();
};

// Watch for filter changes and update URL
watch([dateFrom, dateTo, storeIds, perPage], throttle(updateFilters, 300));
watch([search, filterExpectedDate, filterReceivedDate, filterStore, filterSupplier, filterStatus, filterItemCode, filterItemDescription, filterUom, filterSo, filterDr], throttle(updateFilters, 500));

// Toggle filters for mobile
const toggleFilters = () => {
    isFiltersCollapsed.value = !isFiltersCollapsed.value;
};

// Active filters count for mobile indicator
const activeFiltersCount = computed(() => {
    let count = 0;
    if (dateFrom.value) count++;
    if (dateTo.value) count++;
    if (storeIds.value && storeIds.value.length > 0) count++;
    if (search.value) count++;
    if (filterExpectedDate.value) count++;
    if (filterReceivedDate.value) count++;
    if (filterStore.value) count++;
    if (filterSupplier.value) count++;
    if (filterStatus.value) count++;
    if (filterItemCode.value) count++;
    if (filterItemDescription.value) count++;
    if (filterUom.value) count++;
    if (filterSo.value) count++;
    if (filterDr.value) count++;
    return count;
});

// Check if any filters are active
const hasActiveFilters = computed(() => activeFiltersCount.value > 0);

// Mobile-responsive filter visibility
const shouldShowFilters = computed(() => {
    return !isFiltersCollapsed.value || window.innerWidth >= 1024;
});

// Reset filters to defaults
const resetFilters = () => {
    dateFrom.value = '';
    dateTo.value = '';
    storeIds.value = props.assignedStoreIds;
    search.value = '';
    perPage.value = 50;
    filterExpectedDate.value = '';
    filterReceivedDate.value = '';
    filterStore.value = '';
    filterSupplier.value = '';
    filterStatus.value = '';
    filterItemCode.value = '';
    filterItemDescription.value = '';
    filterUom.value = '';
    filterSo.value = '';
    filterDr.value = '';
    sortField.value = '';
    sortDirection.value = 'asc';
};

// Export route
const exportRoute = computed(() =>
    route('reports.delivery-report.export', {
        date_from: dateFrom.value,
        date_to: dateTo.value,
        store_ids: storeIds.value,
        search: search.value,
    })
);

// Format currency (for future use if needed)
const formatCurrency = (amount) => {
    if (!amount) return '₱0.00';
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    }).format(amount);
};

// Format number with commas
const formatNumber = (num) => {
    if (!num) return '0';
    return new Intl.NumberFormat('en-PH').format(num);
};

// Format date
const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    } catch (e) {
        return 'Invalid Date';
    }
};

// Format date only
const formatDateOnly = (dateString) => {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    } catch (e) {
        return 'Invalid Date';
    }
};
</script>

<template>
    <Layout heading="Delivery Report" :hasExcelDownload="true" :exportRoute="exportRoute">
        <!-- Mobile Filter Toggle -->
        <div class="lg:hidden mb-4">
            <button
                @click="toggleFilters"
                class="flex items-center justify-between w-full p-4 bg-white rounded-lg border border-gray-200 shadow-sm hover:shadow-md transition-shadow"
            >
                <div class="flex items-center gap-3">
                    <Filter class="w-5 h-5 text-gray-600" />
                    <span class="font-medium text-gray-900">Filters</span>
                    <span
                        v-if="hasActiveFilters"
                        class="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-700 rounded-full"
                    >
                        {{ activeFiltersCount }}
                    </span>
                </div>
                <ChevronDown
                    :class="['w-5 h-5 text-gray-400 transition-transform', isFiltersCollapsed ? 'rotate-180' : '']"
                />
            </button>
        </div>

        <!-- Enhanced Filter Section -->
        <div
            :class="[
                'bg-white rounded-xl border border-gray-200 shadow-sm mb-6 transition-all duration-300',
                isFiltersCollapsed && window.innerWidth < 1024 ? 'hidden' : 'block'
            ]"
        >
            <div class="p-6">
                <!-- Search Bar -->
                <div class="mb-6">
                    <div class="relative">
                        <Search :class="['w-5 h-5 text-gray-400 absolute left-4 top-1/2 transform -translate-y-1/2 transition-colors', searchFocus ? 'text-blue-500' : '']" />
                        <Input
                            v-model="search"
                            @focus="searchFocus = true"
                            @blur="searchFocus = false"
                            placeholder="Search by item code, description, SO number, or DR number..."
                            class="pl-12 pr-4 py-3 w-full text-base border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 rounded-lg"
                        />
                        <div v-if="search" class="absolute right-4 top-1/2 transform -translate-y-1/2">
                            <button
                                @click="search = ''"
                                class="text-gray-400 hover:text-gray-600 transition-colors"
                            >
                                <RotateCcw class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Filter Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Date Range -->
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <CalendarDays class="w-4 h-4" />
                            From Date
                        </label>
                        <Input
                            type="date"
                            v-model="dateFrom"
                            class="w-full border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 rounded-lg"
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
                            class="w-full border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 rounded-lg"
                        />
                    </div>

                    <!-- Store Filter -->
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <Building2 class="w-4 h-4" />
                            Stores
                        </label>
                        <MultiSelect
                            filter
                            placeholder="Select Stores"
                            v-model="storeIds"
                            :options="storeOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                        />
                    </div>

                    <!-- Per Page -->
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <Package class="w-4 h-4" />
                            Per Page
                        </label>
                        <Select
                            v-model="perPage"
                            :options="perPageOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :clearable="false"
                        />
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center justify-between mt-6 pt-6 border-t border-gray-200">
                    <div class="text-sm text-gray-600">
                        Showing {{ paginatedData.data?.length || 0 }} of {{ paginatedData.total || 0 }} results
                    </div>
                    <div class="flex items-center gap-3">
                        <Button
                            @click="resetFilters"
                            variant="outline"
                            class="flex items-center gap-2 px-4 py-2 border-gray-300 hover:bg-gray-50 transition-colors"
                        >
                            <RotateCcw class="w-4 h-4" />
                            Reset Filters
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Total Records</p>
                        <p class="text-2xl font-bold text-gray-900">{{ formatNumber(paginatedData.total || 0) }}</p>
                    </div>
                    <ChartColumnBig class="w-8 h-8 text-blue-500" />
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Total Ordered</p>
                        <p class="text-2xl font-bold text-gray-900">{{ formatNumber(totals.quantity_ordered) }}</p>
                    </div>
                    <Package class="w-8 h-8 text-green-500" />
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Total Committed</p>
                        <p class="text-2xl font-bold text-gray-900">{{ formatNumber(totals.quantity_committed) }}</p>
                    </div>
                    <BadgeIcon class="w-8 h-8 text-amber-500" />
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Total Received</p>
                        <p class="text-2xl font-bold text-gray-900">{{ formatNumber(totals.quantity_received) }}</p>
                    </div>
                    <Truck class="w-8 h-8 text-purple-500" />
                </div>
            </div>
        </div>

        <!-- Enhanced Data Table -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-visible">
            <!-- Desktop Table -->
            <div class="hidden lg:block overflow-visible">
                <table class="min-w-full border-separate border-spacing-0">
                    <thead class="sticky -top-4 lg:-top-6 z-20 bg-gray-50 shadow-sm border-b border-gray-200">
                        <tr class="text-xs text-gray-500 uppercase tracking-wider">
                            <th @click="handleSort('expected_delivery_date')" class="px-6 py-4 text-left font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-1">
                                        <span>Expected Delivery Date</span>
                                        <ArrowUp v-if="sortField === 'expected_delivery_date' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'expected_delivery_date' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterExpectedDate" @click.stop placeholder="Filter..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
                                </div>
                            </th>
                            <th @click="handleSort('date_received')" class="px-6 py-4 text-left font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-1">
                                        <span>Received Logged</span>
                                        <ArrowUp v-if="sortField === 'date_received' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'date_received' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterReceivedDate" @click.stop placeholder="Filter..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
                                </div>
                            </th>
                            <th @click="handleSort('store_name')" class="px-6 py-4 text-left font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-1">
                                        <span>Store</span>
                                        <ArrowUp v-if="sortField === 'store_name' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'store_name' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterStore" @click.stop placeholder="Filter..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
                                </div>
                            </th>
                            <th @click="handleSort('supplier_code')" class="px-6 py-4 text-left font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-1">
                                        <span>Supplier Code</span>
                                        <ArrowUp v-if="sortField === 'supplier_code' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'supplier_code' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterSupplier" @click.stop placeholder="Filter..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
                                </div>
                            </th>
                            <th @click="handleSort('status')" class="px-6 py-4 text-center font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-center gap-1">
                                        <span>Status</span>
                                        <ArrowUp v-if="sortField === 'status' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'status' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterStatus" @click.stop placeholder="Filter..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
                                </div>
                            </th>
                            <th @click="handleSort('item_code')" class="px-6 py-4 text-left font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-1">
                                        <span>Item Code</span>
                                        <ArrowUp v-if="sortField === 'item_code' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'item_code' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterItemCode" @click.stop placeholder="Filter..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
                                </div>
                            </th>
                            <th @click="handleSort('item_description')" class="px-6 py-4 text-left font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors min-w-[200px]">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-1">
                                        <span>Item Name</span>
                                        <ArrowUp v-if="sortField === 'item_description' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'item_description' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterItemDescription" @click.stop placeholder="Filter..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
                                </div>
                            </th>
                            <th @click="handleSort('uom')" class="px-6 py-4 text-center font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-center gap-1">
                                        <span>UOM</span>
                                        <ArrowUp v-if="sortField === 'uom' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'uom' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterUom" @click.stop placeholder="Filter..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
                                </div>
                            </th>
                            <th rowspan="2" @click="handleSort('quantity_ordered')" class="px-6 py-4 text-center font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-1">
                                    <span>Order Qty</span>
                                    <ArrowUp v-if="sortField === 'quantity_ordered' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'quantity_ordered' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th rowspan="2" @click="handleSort('quantity_committed')" class="px-6 py-4 text-center font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-1">
                                    <span>Committed Qty</span>
                                    <ArrowUp v-if="sortField === 'quantity_committed' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'quantity_committed' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th rowspan="2" @click="handleSort('quantity_received')" class="px-6 py-4 text-center font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-1">
                                    <span>Received Qty</span>
                                    <ArrowUp v-if="sortField === 'quantity_received' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'quantity_received' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('so_number')" class="px-6 py-4 text-left font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-1">
                                        <span>SO Number</span>
                                        <ArrowUp v-if="sortField === 'so_number' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'so_number' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterSo" @click.stop placeholder="Filter..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
                                </div>
                            </th>
                            <th @click="handleSort('dr_number')" class="px-6 py-4 text-left font-medium cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-1">
                                        <span>DR Number</span>
                                        <ArrowUp v-if="sortField === 'dr_number' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'dr_number' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterDr" @click.stop placeholder="Filter..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <tr v-if="!deliveryData || deliveryData.length === 0" class="hover:bg-gray-50">
                            <td colspan="13" class="text-center py-12 text-gray-500">
                                <div class="flex flex-col items-center">
                                    <Truck class="w-12 h-12 text-gray-300 mb-3" />
                                    <span class="text-lg font-medium">No delivery data available</span>
                                    <span class="text-sm text-gray-400 mt-1">Try adjusting your filters or search criteria</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="(item, index) in deliveryData" :key="item.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 text-sm text-gray-900">{{ formatDateOnly(item.expected_delivery_date) }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">{{ formatDate(item.date_received) }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">{{ item.store_name }} ({{ item.store_code }})</td>
                            <td class="px-6 py-4 text-sm text-gray-900 font-medium">{{ item.supplier_code || 'N/A' }}</td>
                            <td class="px-6 py-4 text-sm text-center">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                    {{ item.status ? item.status.toUpperCase() : 'N/A' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm font-mono text-gray-900">{{ item.item_code }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900 max-w-xs truncate" :title="item.item_description">{{ item.item_description }}</td>
                            <td class="px-6 py-4 text-sm text-center text-gray-900">{{ item.uom || '-' }}</td>
                            <td class="px-6 py-4 text-sm text-center font-medium text-gray-900">{{ formatNumber(item.quantity_ordered) }}</td>
                            <td class="px-6 py-4 text-sm text-center font-medium text-gray-900">{{ formatNumber(item.quantity_committed) }}</td>
                            <td class="px-6 py-4 text-sm text-center font-medium text-gray-900">{{ formatNumber(item.quantity_received) }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">{{ item.so_number || 'N/A' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">{{ item.dr_number || 'N/A' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card Layout -->
            <div class="lg:hidden">
                <div v-if="!deliveryData || deliveryData.length === 0" class="p-8 text-center">
                    <Truck class="w-16 h-16 text-gray-300 mx-auto mb-4" />
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No delivery data available</h3>
                    <p class="text-sm text-gray-500">Try adjusting your filters or search criteria</p>
                </div>

                <div class="divide-y divide-gray-100">
                    <div v-for="(item, index) in deliveryData" :key="item.id" class="p-4 hover:bg-gray-50 transition-colors">
                        <!-- Header Row -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex flex-col gap-1">
                                <div class="flex items-center gap-2">
                                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                        {{ item.status ? item.status.toUpperCase() : 'N/A' }}
                                    </span>
                                    <span class="text-xs text-gray-500 font-medium">{{ item.supplier_code }}</span>
                                </div>
                                <span class="text-sm font-mono text-gray-900">Rx: {{ formatDate(item.date_received) }}</span>
                                <span class="text-xs text-gray-500">Exp: {{ formatDateOnly(item.expected_delivery_date) }}</span>
                                <span class="text-xs text-gray-500 mt-1">{{ item.store_name }} ({{ item.store_code }})</span>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-semibold text-gray-900">{{ formatNumber(item.quantity_received) }}</div>
                                <div class="text-xs text-gray-500">Received</div>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="mb-3">
                            <p class="text-sm text-gray-900 line-clamp-2">{{ item.item_description }}</p>
                            <div class="flex justify-between items-center mt-1">
                                <p class="text-xs text-gray-600">{{ item.item_code }}</p>
                                <p class="text-xs text-gray-600">UOM: {{ item.uom }}</p>
                            </div>
                        </div>

                        <!-- Quantity Breakdown -->
                        <div class="grid grid-cols-3 gap-4 text-sm">
                            <div class="text-center">
                                <p class="text-gray-600">Order Qty</p>
                                <p class="font-medium">{{ formatNumber(item.quantity_ordered) }}</p>
                            </div>
                            <div class="text-center">
                                <p class="text-gray-600">Committed Qty</p>
                                <p class="font-medium">{{ formatNumber(item.quantity_committed) }}</p>
                            </div>
                            <div class="text-center">
                                <p class="text-gray-600">Received Qty</p>
                                <p class="font-medium text-blue-600">{{ formatNumber(item.quantity_received) }}</p>
                            </div>
                        </div>

                        <!-- Reference Numbers -->
                        <div class="grid grid-cols-2 gap-4 text-sm pt-3 border-t border-gray-100">
                            <div>
                                <p class="text-gray-600">SO Number</p>
                                <p class="font-medium">{{ item.so_number || 'N/A' }}</p>
                            </div>
                            <div>
                                <p class="text-gray-600">DR Number</p>
                                <p class="font-medium">{{ item.dr_number || 'N/A' }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Pagination -->
        <div class="bg-white px-6 py-4 border-t border-gray-200 mt-6 rounded-b-xl">
            <Pagination :data="paginatedData" />
        </div>
    </Layout>
</template>
