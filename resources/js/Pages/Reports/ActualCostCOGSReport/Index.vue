<script setup>
import { ref, watch, computed } from "vue";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { Calendar, Search, RotateCcw, Download, Filter, ChevronDown, ChevronUp, Package, CalendarDays, Building2, Badge as BadgeIcon, Calculator, ArrowLeftRight } from "lucide-vue-next";
import { useAuth } from "@/Composables/useAuth";
import MultiSelect from "primevue/multiselect";
import Select from "@/Components/ui/select/Select.vue";

const props = defineProps({
    reportData: {
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
    monthOptions: {
        type: Array,
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
        label: `${store.name} (${store.brand_code})`,
        value: store.id,
        searchTerms: [store.name, store.brand_code].join(' ').toLowerCase()
    }));
});

// Initialize filters with default values
const year = ref(props.filters.year || new Date().getFullYear());
const month = ref(props.filters.month || new Date().getMonth() + 1);
const storeIds = ref(props.filters.store_ids || []);
const search = ref(props.filters.search || '');
const perPage = ref(props.filters.per_page || 50);

const { hasAccess } = useAuth();

// Enhanced filter management with loading states
const updateFilters = () => {
    isLoading.value = true;
    router.get(
        route('reports.actual-cost-cogs-report.index'),
        {
            year: year.value,
            month: month.value,
            store_ids: storeIds.value,
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
watch([year, month, storeIds, perPage],
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
    if (year.value) count++;
    if (month.value) count++;
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
    year.value = new Date().getFullYear();
    month.value = new Date().getMonth() + 1;
    storeIds.value = props.assignedStoreIds;
    search.value = '';
    perPage.value = 50;
};

// Export route
const exportRoute = computed(() =>
    route('reports.actual-cost-cogs-report.export', {
        year: year.value,
        month: month.value,
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
        beginningInventory: 0,
        beginningValue: 0,
        deliveries: 0,
        deliveriesValue: 0,
        interco: 0,
        intercoValue: 0,
        endingInventory: 0,
        endingValue: 0,
        actualCost: 0,
    };

    props.reportData.forEach(item => {
        totals.beginningInventory += parseFloat(item.beginning_inventory || 0);
        totals.beginningValue += parseFloat(item.beginning_value || 0);
        totals.deliveries += parseFloat(item.deliveries || 0);
        totals.deliveriesValue += parseFloat(item.deliveries_value || 0);
        totals.interco += parseFloat(item.interco || 0);
        totals.intercoValue += parseFloat(item.interco_value || 0);
        totals.endingInventory += parseFloat(item.ending_inventory || 0);
        totals.endingValue += parseFloat(item.ending_value || 0);
        totals.actualCost += parseFloat(item.actual_cost || 0);
    });

    return totals;
});

// Get month name from number
const getMonthName = (monthNumber) => {
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                       'July', 'August', 'September', 'October', 'November', 'December'];
    return monthNames[monthNumber - 1] || 'Unknown';
};
</script>

<template>
    <Layout heading="Actual Cost / COGS Report" :hasExcelDownload="true" :exportRoute="exportRoute">
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
                    <!-- Year Filter -->
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <CalendarDays class="w-4 h-4" />
                            Year
                        </label>
                        <Input
                            type="number"
                            v-model="year"
                            min="2000"
                            max="2099"
                            class="w-full border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 rounded-lg"
                        />
                    </div>

                    <!-- Month Filter -->
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <CalendarDays class="w-4 h-4" />
                            Month
                        </label>
                        <Select
                            v-model="month"
                            :options="monthOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :clearable="false"
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
                            placeholder="All Stores"
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
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Beginning Inventory</p>
                        <p class="text-2xl font-bold text-gray-900">{{ formatNumber(reportTotals.beginningInventory) }}</p>
                        <p class="text-sm text-gray-500">{{ formatCurrency(reportTotals.beginningValue) }}</p>
                    </div>
                    <Package class="w-8 h-8 text-blue-500" />
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Deliveries</p>
                        <p class="text-2xl font-bold text-gray-900">{{ formatNumber(reportTotals.deliveries) }}</p>
                        <p class="text-sm text-gray-500">{{ formatCurrency(reportTotals.deliveriesValue) }}</p>
                    </div>
                    <Calculator class="w-8 h-8 text-green-500" />
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Interco</p>
                        <p class="text-2xl font-bold text-gray-900">{{ formatNumber(reportTotals.interco) }}</p>
                        <p class="text-sm text-gray-500">{{ formatCurrency(reportTotals.intercoValue) }}</p>
                    </div>
                    <ArrowLeftRight class="w-8 h-8 text-purple-500" />
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Ending Inventory</p>
                        <p class="text-2xl font-bold text-gray-900">{{ formatNumber(reportTotals.endingInventory) }}</p>
                        <p class="text-sm text-gray-500">{{ formatCurrency(reportTotals.endingValue) }}</p>
                    </div>
                    <Package class="w-8 h-8 text-amber-500" />
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
                            <th class="px-4 py-3 text-left font-medium">Store Branch</th>
                            <th class="px-4 py-3 text-left font-medium">Item Code</th>
                            <th class="px-4 py-3 text-left font-medium">Item Description</th>
                            <th class="px-4 py-3 text-left font-medium">UoM</th>
                            <th class="px-4 py-3 text-right font-medium">Unit Cost</th>
                            <th class="px-4 py-3 text-center font-medium bg-blue-50" colspan="2">Beginning Inventory</th>
                            <th class="px-4 py-3 text-center font-medium bg-green-50" colspan="2">Deliveries</th>
                            <th class="px-4 py-3 text-center font-medium bg-purple-50" colspan="2">Interco</th>
                            <th class="px-4 py-3 text-center font-medium bg-amber-50" colspan="2">Ending Inventory</th>
                            <th class="px-4 py-3 text-right font-medium">Actual Cost</th>
                        </tr>
                        <tr class="text-xs text-gray-500 uppercase tracking-wider">
                            <th class="px-3 py-2"></th>
                            <th class="px-3 py-2"></th>
                            <th class="px-3 py-2"></th>
                            <th class="px-3 py-2"></th>
                            <th class="px-3 py-2"></th>
                            <!-- Beginning Inventory Sub-headers -->
                            <th class="px-3 py-2 text-center font-medium text-gray-600 bg-blue-50">Qty</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600 bg-blue-50">Value</th>
                            <!-- Deliveries Sub-headers -->
                            <th class="px-3 py-2 text-center font-medium text-gray-600 bg-green-50">Qty</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600 bg-green-50">Value</th>
                            <!-- Interco Sub-headers -->
                            <th class="px-3 py-2 text-center font-medium text-gray-600 bg-purple-50">Qty</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600 bg-purple-50">Value</th>
                            <!-- Ending Inventory Sub-headers -->
                            <th class="px-3 py-2 text-center font-medium text-gray-600 bg-amber-50">Qty</th>
                            <th class="px-3 py-2 text-right font-medium text-gray-600 bg-amber-50">Value</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <tr v-if="!paginatedData.data || paginatedData.data.length === 0" class="hover:bg-gray-50">
                            <td colspan="14" class="text-center py-12 text-gray-500">
                                <div class="flex flex-col items-center">
                                    <Calculator class="w-12 h-12 text-gray-300 mb-3" />
                                    <span class="text-lg font-medium">No data available</span>
                                    <span class="text-sm text-gray-400 mt-1">Try adjusting your filters or search criteria</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="(item, index) in (paginatedData.data || [])" :key="index" class="hover:bg-gray-50 transition-colors">
                            <td class="px-4 py-3 text-sm text-gray-900">{{ item.store_branch || 'N/A' }}</td>
                            <td class="px-4 py-3 text-sm font-mono text-gray-900">{{ item.item_code || 'N/A' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900 max-w-xs truncate" :title="item.item_description">{{ item.item_description || 'N/A' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ item.uom || 'N/A' }}</td>
                            <td class="px-4 py-3 text-sm text-right font-medium text-gray-900">{{ formatCurrency(item.unit_cost) }}</td>

                            <!-- Beginning Inventory -->
                            <td class="px-3 py-3 text-sm text-center font-medium text-gray-900 bg-blue-50">
                                {{ formatNumber(item.beginning_inventory) }}
                            </td>
                            <td class="px-3 py-3 text-sm text-right font-medium text-gray-900 bg-blue-50">
                                {{ formatCurrency(item.beginning_value) }}
                            </td>

                            <!-- Deliveries -->
                            <td class="px-3 py-3 text-sm text-center font-medium text-gray-900 bg-green-50">
                                {{ formatNumber(item.deliveries) }}
                            </td>
                            <td class="px-3 py-3 text-sm text-right font-medium text-gray-900 bg-green-50">
                                {{ formatCurrency(item.deliveries_value) }}
                            </td>

                            <!-- Interco -->
                            <td class="px-3 py-3 text-sm text-center font-medium text-gray-900 bg-purple-50">
                                {{ formatNumber(item.interco) }}
                            </td>
                            <td class="px-3 py-3 text-sm text-right font-medium text-gray-900 bg-purple-50">
                                {{ formatCurrency(item.interco_value) }}
                            </td>

                            <!-- Ending Inventory -->
                            <td class="px-3 py-3 text-sm text-center font-medium text-gray-900 bg-amber-50">
                                {{ formatNumber(item.ending_inventory) }}
                            </td>
                            <td class="px-3 py-3 text-sm text-right font-medium text-gray-900 bg-amber-50">
                                {{ formatCurrency(item.ending_value) }}
                            </td>
                            <td class="px-4 py-3 text-sm text-right font-semibold text-gray-900">
                                {{ formatCurrency(item.actual_cost) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card Layout -->
            <div class="lg:hidden">
                <div v-if="!paginatedData.data || paginatedData.data.length === 0" class="p-8 text-center">
                    <Calculator class="w-16 h-16 text-gray-300 mx-auto mb-4" />
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No data available</h3>
                    <p class="text-sm text-gray-500">Try adjusting your filters or search criteria</p>
                </div>

                <div class="divide-y divide-gray-100">
                    <div v-for="(item, index) in (paginatedData.data || [])" :key="index" class="p-4 hover:bg-gray-50 transition-colors">
                        <!-- Header Row -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex flex-col">
                                <span class="text-sm font-mono text-gray-900">{{ item.item_code || 'N/A' }}</span>
                                <span class="text-xs text-gray-500">{{ item.store_branch || 'N/A' }}</span>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-semibold text-gray-900">
                                    {{ formatCurrency(item.ending_value) }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ formatNumber(item.ending_inventory) }} units
                                </div>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="mb-3">
                            <p class="text-sm text-gray-900 line-clamp-2">{{ item.item_description || 'N/A' }}</p>
                        </div>

                        <!-- Cost and UoM -->
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            <div>
                                <span class="text-xs text-gray-500 block">Unit Cost:</span>
                                <span class="text-sm text-gray-900 block">{{ formatCurrency(item.unit_cost) }}</span>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500 block">UoM:</span>
                                <span class="text-sm text-gray-900 block">{{ item.uom || 'N/A' }}</span>
                            </div>
                        </div>

                        <!-- Inventory Breakdown -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600 bg-blue-50 px-2 py-1 rounded">Beginning:</span>
                                <span class="font-medium">{{ formatNumber(item.beginning_inventory) }} ({{ formatCurrency(item.beginning_value) }})</span>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600 bg-green-50 px-2 py-1 rounded">Deliveries:</span>
                                <span class="font-medium">{{ formatNumber(item.deliveries) }} ({{ formatCurrency(item.deliveries_value) }})</span>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600 bg-purple-50 px-2 py-1 rounded">Interco:</span>
                                <span class="font-medium">{{ formatNumber(item.interco) }} ({{ formatCurrency(item.interco_value) }})</span>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600 bg-gray-100 px-2 py-1 rounded">Actual Cost:</span>
                                <span class="font-medium">{{ formatCurrency(item.actual_cost) }}</span>
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