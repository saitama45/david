<script setup>
import { ref, watch, computed } from "vue";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { Calendar, Search, RotateCcw, Download, Filter, ChevronDown, ChevronUp, Package, CalendarDays, Building2, Badge as BadgeIcon, Trash2 } from "lucide-vue-next";
import { useAuth } from "@/Composables/useAuth";
import SearchableSelect from "@/Components/ui/select/SearchableSelect.vue";

const props = defineProps({
    wastages: {
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
    statusOptions: {
        type: Array,
        required: true,
    },
    assignedStoreIds: {
        type: Array,
        required: true,
    },
    summaryTotals: {
        type: Object,
        required: true,
    }
});

// Reactive states
const isFiltersCollapsed = ref(false);
const isLoading = ref(false);
const searchFocus = ref(false);

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
        label: `${store.label}`,
        value: store.value,
        searchTerms: [store.label].join(' ').toLowerCase()
    }));
});

// Status options with enhanced labels
const statusOptions = computed(() => {
    return props.statusOptions.map(status => ({
        label: status.label,
        value: status.value
    }));
});

// Initialize filters with default values
const dateFrom = ref(props.filters.date_from || '');
const dateTo = ref(props.filters.date_to || '');
const storeBranchId = ref(props.filters.store_branch_id || '');
const status = ref(props.filters.status || '');
const search = ref(props.filters.search || '');
const perPage = ref(props.filters.per_page || 50);

const { hasAccess } = useAuth();

// Enhanced filter management with loading states
const updateFilters = () => {
    isLoading.value = true;
    router.get(
        route('reports.wastage-report.index'),
        {
            date_from: dateFrom.value,
            date_to: dateTo.value,
            store_branch_id: storeBranchId.value,
            status: status.value,
            search: search.value,
            per_page: perPage.value,
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

// Watch for filter changes and update URL
watch([dateFrom, dateTo, storeBranchId, status, perPage],
    throttle(updateFilters, 300)
);

// Watch for search changes with longer throttling
watch(search,
    throttle(updateFilters, 500)
);

// Toggle filters for mobile
const toggleFilters = () => {
    isFiltersCollapsed.value = !isFiltersCollapsed.value;
};

// Active filters count for mobile indicator
const activeFiltersCount = computed(() => {
    let count = 0;
    if (dateFrom.value) count++;
    if (dateTo.value) count++;
    if (storeBranchId.value) count++;
    if (status.value) count++;
    if (search.value) count++;
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
    // Set default dates (start of month to today)
    const today = new Date();
    const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);

    dateFrom.value = firstDayOfMonth.toISOString().split('T')[0];
    dateTo.value = today.toISOString().split('T')[0];
    storeBranchId.value = null;
    status.value = null;
    search.value = '';
    perPage.value = 50;
};

// Export route
const exportRoute = computed(() =>
    route('reports.wastage-report.export', {
        date_from: dateFrom.value,
        date_to: dateTo.value,
        store_branch_id: storeBranchId.value,
        status: status.value,
        search: search.value,
    })
);

// Format currency
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

// Enhanced status badge styling with modern design
const getStatusClass = (status) => {
    const statusStyles = {
        'pending': {
            bg: 'bg-gray-50 border-gray-200',
            text: 'text-gray-700',
            dot: 'bg-gray-400',
            label: 'Pending'
        },
        'approved_lvl1': {
            bg: 'bg-blue-50 border-blue-200',
            text: 'text-blue-700',
            dot: 'bg-blue-400',
            label: 'Approved Level 1'
        },
        'approved_lvl2': {
            bg: 'bg-green-50 border-green-200',
            text: 'text-green-700',
            dot: 'bg-green-400',
            label: 'Approved Level 2'
        },
        'cancelled': {
            bg: 'bg-red-50 border-red-200',
            text: 'text-red-700',
            dot: 'bg-red-400',
            label: 'Cancelled'
        }
    };
    return statusStyles[status] || statusStyles['pending'];
};
</script>

<template>
    <Layout heading="Wastage Report" :hasExcelDownload="true" :exportRoute="exportRoute">
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
                            placeholder="Search by wastage number or store name..."
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
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
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
                            Store
                        </label>
                        <SearchableSelect
                            v-model="storeBranchId"
                            placeholder="All Stores"
                            :options="storeOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            clearable
                        />
                    </div>

                    <!-- Status Filter -->
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <BadgeIcon class="w-4 h-4" />
                            Status
                        </label>
                        <Select
                            v-model="status"
                            placeholder="All Status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            clearable
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
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Total Records</p>
                        <p class="text-2xl font-bold text-gray-900">{{ formatNumber(summaryTotals.total_records || 0) }}</p>
                    </div>
                    <Trash2 class="w-8 h-8 text-blue-500" />
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Total Quantity</p>
                        <p class="text-2xl font-bold text-gray-900">{{ formatNumber(summaryTotals.total_quantity || 0) }}</p>
                    </div>
                    <Package class="w-8 h-8 text-green-500" />
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Total Cost</p>
                        <p class="text-2xl font-bold text-gray-900">{{ formatCurrency(summaryTotals.total_cost || 0) }}</p>
                    </div>
                    <BadgeIcon class="w-8 h-8 text-amber-500" />
                </div>
            </div>
        </div>

        <!-- Enhanced Data Table -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <!-- Loading Overlay -->
            <div v-if="isLoading" class="absolute inset-0 bg-white/80 flex items-center justify-center z-10">
                <div class="flex items-center gap-3 text-gray-600">
                    <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600"></div>
                    <span>Loading...</span>
                </div>
            </div>

            <!-- Desktop Table -->
            <div class="hidden lg:block overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr class="text-xs text-gray-500 uppercase tracking-wider">
                            <th class="px-6 py-4 text-left font-medium">Wastage #</th>
                            <th class="px-6 py-4 text-left font-medium">Store</th>
                            <th class="px-6 py-4 text-center font-medium">Total Qty</th>
                            <th class="px-6 py-4 text-center font-medium">Items</th>
                            <th class="px-6 py-4 text-right font-medium">Total Cost</th>
                            <th class="px-6 py-4 text-center font-medium">Status</th>
                            <th class="px-6 py-4 text-left font-medium">Reason</th>
                            <th class="px-6 py-4 text-left font-medium">Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <tr v-if="!paginatedData.data || paginatedData.data.length === 0" class="hover:bg-gray-50">
                            <td colspan="8" class="text-center py-12 text-gray-500">
                                <div class="flex flex-col items-center">
                                    <Trash2 class="w-12 h-12 text-gray-300 mb-3" />
                                    <span class="text-lg font-medium">No data available</span>
                                    <span class="text-sm text-gray-400 mt-1">Try adjusting your filters or search criteria</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="(item, index) in (paginatedData.data || [])" :key="item.wastage_no" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 text-sm font-mono text-gray-900">{{ item.wastage_no || 'N/A' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900 max-w-xs truncate" :title="item.store">{{ item.store || 'N/A' }}</td>
                            <td class="px-6 py-4 text-sm text-center font-medium text-gray-900">{{ formatNumber(item.total_qty || 0) }}</td>
                            <td class="px-6 py-4 text-sm text-center font-medium text-gray-900">{{ item.items_count || 0 }}</td>
                            <td class="px-6 py-4 text-sm text-right font-medium text-gray-900">{{ formatCurrency(item.total_cost || 0) }}</td>
                            <td class="px-6 py-4 text-center">
                                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full border text-xs font-medium" :class="getStatusClass(item.status).bg">
                                    <div :class="['w-2 h-2 rounded-full', getStatusClass(item.status).dot]"></div>
                                    <span :class="getStatusClass(item.status).text">
                                        {{ getStatusClass(item.status).label }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 max-w-xs truncate" :title="item.reason">{{ item.reason || 'N/A' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ item.formatted_date || 'N/A' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card Layout -->
            <div class="lg:hidden">
                <div v-if="!paginatedData.data || paginatedData.data.length === 0" class="p-8 text-center">
                    <Trash2 class="w-16 h-16 text-gray-300 mx-auto mb-4" />
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No data available</h3>
                    <p class="text-sm text-gray-500">Try adjusting your filters or search criteria</p>
                </div>

                <div class="divide-y divide-gray-100">
                    <div v-for="(item, index) in (paginatedData.data || [])" :key="item.wastage_no" class="p-4 hover:bg-gray-50 transition-colors">
                        <!-- Header Row -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex flex-col">
                                <span class="text-sm font-mono text-gray-900">{{ item.wastage_no || 'N/A' }}</span>
                                <span class="text-xs text-gray-500">{{ item.store || 'N/A' }}</span>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-semibold text-gray-900">
                                    {{ formatCurrency(item.total_cost || 0) }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ formatNumber(item.total_qty || 0) }} qty, {{ item.items_count || 0 }} items
                                </div>
                            </div>
                        </div>

                        <!-- Status and Date -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="inline-flex items-center gap-2 px-2 py-1 rounded-full border text-xs font-medium" :class="getStatusClass(item.status).bg">
                                <div :class="['w-1.5 h-1.5 rounded-full', getStatusClass(item.status).dot]"></div>
                                <span :class="getStatusClass(item.status).text">
                                    {{ getStatusClass(item.status).label }}
                                </span>
                            </div>
                            <span class="text-xs text-gray-500">{{ item.formatted_date || 'N/A' }}</span>
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