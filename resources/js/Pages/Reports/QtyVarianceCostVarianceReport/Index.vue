<script setup>
import { ref, watch, computed } from "vue";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { Calendar, Search, RotateCcw, Download, Filter, ChevronDown, ChevronUp, Package, CalendarDays, Building2, Badge as BadgeIcon, ChartColumnBig, TrendingUp, TrendingDown } from "lucide-vue-next";
import { useAuth } from "@/Composables/useAuth";
import MultiSelect from "primevue/multiselect";

const props = defineProps({
    varianceData: {
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
    }
});

// Reactive states
const isFiltersCollapsed = ref(false);

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
        label: `${store.name} (${store.brand_code})`,
        value: store.id,
        searchTerms: [store.name, store.brand_code].join(' ').toLowerCase()
    }));
});

// Helper to get current date in YYYY-MM-DD format
const getCurrentDate = () => {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0'); // Months are 0-indexed
    const day = String(today.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

// Helper to get the first day of the current month in YYYY-MM-DD format
const getFirstDayOfMonth = () => {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    return `${year}-${month}-01`;
};

// Initialize filters with default values
const dateFrom = ref(props.filters.date_from || getFirstDayOfMonth());
const dateTo = ref(props.filters.date_to || getCurrentDate());
const storeIds = ref(props.filters.store_ids || []);
const search = ref(props.filters.search || '');
const perPage = ref(props.filters.per_page || 50);

const { hasAccess } = useAuth();

// Enhanced filter management with loading states
const updateFilters = () => {

    router.get(
        route('reports.qty-variance-cost-variance-report.index'),
        {
            date_from: dateFrom.value,
            date_to: dateTo.value,
            store_ids: storeIds.value,
            search: search.value,
            per_page: perPage.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    );
};

// Watch for filter changes and update URL
watch([dateFrom, dateTo, storeIds, perPage],
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
    if (storeIds.value && storeIds.value.length > 0) count++;
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
    dateFrom.value = getFirstDayOfMonth();
    dateTo.value = getCurrentDate();
    storeIds.value = props.assignedStoreIds;
    search.value = '';
    perPage.value = 50;
};

// Export route
const exportRoute = computed(() =>
    route('reports.qty-variance-cost-variance-report.export', {
        date_from: dateFrom.value,
        date_to: dateTo.value,
        store_ids: storeIds.value,
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

// Calculate totals for the report
const reportTotals = computed(() => {
    const totals = {
        actualInventory: 0,
        theoreticalInventory: 0,
        qtyVariance: 0,
        actualCost: 0,
        theoreticalCost: 0,
        costVariance: 0
    };

    props.varianceData.forEach(item => {
        totals.actualInventory += item.actual_inventory || 0;
        totals.theoreticalInventory += item.theoretical_inventory || 0;
        totals.qtyVariance += item.qty_variance || 0;
        totals.actualCost += item.actual_cost || 0;
        totals.theoreticalCost += item.theoretical_cost || 0;
        totals.costVariance += item.cost_variance || 0;
    });

    return totals;
});

// Helper function to get variance styling
const getVarianceClass = (variance) => {
    if (variance > 0) return 'text-green-600 bg-green-50';
    if (variance < 0) return 'text-red-600 bg-red-50';
    return 'text-gray-600 bg-gray-50';
};

// Helper function to get variance icon
const getVarianceIcon = (variance) => {
    return variance > 0 ? TrendingUp : TrendingDown;
};
</script>

<template>
    <Layout heading="Qty Variance / Cost Variance Report" :hasExcelDownload="true" :exportRoute="exportRoute">
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
                            placeholder="Search by item code, description, or store..."
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
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Qty Variance</p>
                        <p class="text-2xl font-bold" :class="reportTotals.qtyVariance >= 0 ? 'text-green-600' : 'text-red-600'">
                            {{ formatNumber(reportTotals.qtyVariance) }}
                        </p>
                    </div>
                    <component :is="getVarianceIcon(reportTotals.qtyVariance)" class="w-8 h-8" :class="reportTotals.qtyVariance >= 0 ? 'text-green-500' : 'text-red-500'" />
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Total Actual Cost</p>
                        <p class="text-2xl font-bold text-gray-900">{{ formatCurrency(reportTotals.actualCost) }}</p>
                    </div>
                    <BadgeIcon class="w-8 h-8 text-amber-500" />
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Cost Variance</p>
                        <p class="text-2xl font-bold" :class="reportTotals.costVariance >= 0 ? 'text-green-600' : 'text-red-600'">
                            {{ formatCurrency(reportTotals.costVariance) }}
                        </p>
                    </div>
                    <component :is="getVarianceIcon(reportTotals.costVariance)" class="w-8 h-8" :class="reportTotals.costVariance >= 0 ? 'text-green-500' : 'text-red-500'" />
                </div>
            </div>
        </div>

        <!-- Enhanced Data Table -->
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">


            <!-- Desktop Table -->
            <div class="hidden lg:block overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr class="text-xs text-gray-500 uppercase tracking-wider">
                            <th class="px-4 py-4 text-left font-medium">Store Branch</th>
                            <th class="px-4 py-4 text-left font-medium">Item Code</th>
                            <th class="px-4 py-4 text-left font-medium">Item Description</th>
                            <th class="px-4 py-4 text-left font-medium">UoM</th>
                            <th class="px-4 py-4 text-right font-medium">Cost</th>
                            <th class="px-4 py-4 text-center font-medium bg-blue-50">Actual Inventory</th>
                            <th class="px-4 py-4 text-center font-medium bg-blue-50">Theoretical Inventory</th>
                            <th class="px-4 py-4 text-center font-medium bg-blue-50">Qty Variance</th>
                            <th class="px-4 py-4 text-right font-medium bg-amber-50">Actual Cost</th>
                            <th class="px-4 py-4 text-right font-medium bg-amber-50">Theoretical Cost</th>
                            <th class="px-4 py-4 text-right font-medium bg-amber-50">Cost Variance</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <tr v-if="!paginatedData.data || paginatedData.data.length === 0" class="hover:bg-gray-50">
                            <td colspan="11" class="text-center py-12 text-gray-500">
                                <div class="flex flex-col items-center">
                                    <ChartColumnBig class="w-12 h-12 text-gray-300 mb-3" />
                                    <span class="text-lg font-medium">No data available</span>
                                    <span class="text-sm text-gray-400 mt-1">Try adjusting your filters or search criteria</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="(item, index) in (paginatedData.data || [])" :key="item.id || index" class="hover:bg-gray-50 transition-colors">
                            <td class="px-4 py-4 text-sm text-gray-900 max-w-xs truncate" :title="item.store_name">{{ item.store_name || 'N/A' }}</td>
                            <td class="px-4 py-4 text-sm font-mono text-gray-900">{{ item.item_code || 'N/A' }}</td>
                            <td class="px-4 py-4 text-sm text-gray-900 max-w-xs truncate" :title="item.item_description">{{ item.item_description || 'N/A' }}</td>
                            <td class="px-4 py-4 text-sm text-gray-600">{{ item.uom || 'N/A' }}</td>
                            <td class="px-4 py-4 text-sm text-right font-medium text-gray-900">{{ formatCurrency(item.cost) }}</td>
                            <td class="px-4 py-4 text-sm text-center font-medium text-gray-900 bg-blue-50">{{ formatNumber(item.actual_inventory) }}</td>
                            <td class="px-4 py-4 text-sm text-center font-medium text-gray-900 bg-blue-50">{{ formatNumber(item.theoretical_inventory) }}</td>
                            <td class="px-4 py-4 text-center font-medium bg-blue-50">
                                <div class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs" :class="getVarianceClass(item.qty_variance)">
                                    <component :is="getVarianceIcon(item.qty_variance)" class="w-3 h-3" />
                                    {{ formatNumber(item.qty_variance) }}
                                </div>
                            </td>
                            <td class="px-4 py-4 text-sm text-right font-medium text-gray-900 bg-amber-50">{{ formatCurrency(item.actual_cost) }}</td>
                            <td class="px-4 py-4 text-sm text-right font-medium text-gray-900 bg-amber-50">{{ formatCurrency(item.theoretical_cost) }}</td>
                            <td class="px-4 py-4 text-right font-medium bg-amber-50">
                                <div class="inline-flex items-center gap-1 px-2 py-1 rounded text-xs" :class="getVarianceClass(item.cost_variance)">
                                    <component :is="getVarianceIcon(item.cost_variance)" class="w-3 h-3" />
                                    {{ formatCurrency(item.cost_variance) }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card Layout -->
            <div class="lg:hidden">
                <div v-if="!paginatedData.data || paginatedData.data.length === 0" class="p-8 text-center">
                    <ChartColumnBig class="w-16 h-16 text-gray-300 mx-auto mb-4" />
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No data available</h3>
                    <p class="text-sm text-gray-500">Try adjusting your filters or search criteria</p>
                </div>

                <div class="divide-y divide-gray-100">
                    <div v-for="(item, index) in (paginatedData.data || [])" :key="item.id || index" class="p-4 hover:bg-gray-50 transition-colors">
                        <!-- Header Row -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex flex-col">
                                <span class="text-sm font-mono text-gray-900">{{ item.item_code || 'N/A' }}</span>
                                <span class="text-xs text-gray-500">{{ item.store_name || 'N/A' }}</span>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-semibold text-gray-900">
                                    {{ formatCurrency(item.cost_variance) }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ formatNumber(item.qty_variance) }} qty variance
                                </div>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="mb-3">
                            <p class="text-sm text-gray-900 line-clamp-2">{{ item.item_description || 'N/A' }}</p>
                        </div>

                        <!-- Variance Breakdown -->
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div class="bg-blue-50 p-2 rounded">
                                <div class="text-xs text-blue-600">Actual Inv</div>
                                <div class="font-medium">{{ formatNumber(item.actual_inventory) }}</div>
                            </div>
                            <div class="bg-blue-50 p-2 rounded">
                                <div class="text-xs text-blue-600">Theoretical Inv</div>
                                <div class="font-medium">{{ formatNumber(item.theoretical_inventory) }}</div>
                            </div>
                            <div class="bg-amber-50 p-2 rounded">
                                <div class="text-xs text-amber-600">Actual Cost</div>
                                <div class="font-medium">{{ formatCurrency(item.actual_cost) }}</div>
                            </div>
                            <div class="bg-amber-50 p-2 rounded">
                                <div class="text-xs text-amber-600">Theoretical Cost</div>
                                <div class="font-medium">{{ formatCurrency(item.theoretical_cost) }}</div>
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