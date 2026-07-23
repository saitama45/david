<script setup>
import { ref, watch, computed } from "vue";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
import { Calendar, Search, RotateCcw, Download, Filter, ChevronDown, ChevronUp, Package, CalendarDays, Building2, Badge as BadgeIcon, Trash2, Image as ImageIcon, Loader2, ArrowUpDown, ArrowUp, ArrowDown, TrendingUp, ListOrdered } from "lucide-vue-next";
import { useAuth } from "@/composables/useAuth";
import SearchableSelect from "@/components/ui/select/SearchableSelect.vue";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';

const props = defineProps({
    wastages: {
        type: Array,
        default: () => [],
    },
    paginatedData: {
        type: Object,
        default: null,
    },
    topItems: {
        type: Array,
        default: () => [],
    },
    tabs: {
        type: Array,
        default: () => [],
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

// Column filters
const filterWastageNo = ref(props.filters.filter_wastage_no || '');
const filterStore = ref(props.filters.filter_store || '');
const filterItem = ref(props.filters.filter_item || '');
const filterStatus = ref(props.filters.filter_status || '');
const filterReason = ref(props.filters.filter_reason || '');

// Sorting
const sortField = ref(props.filters.sort_field || '');
const sortDirection = ref(props.filters.sort_direction || 'asc');

// Image attachment states
const showImageModal = ref(false);
const selectedItemImages = ref([]);
const imageLoadingStates = ref({});
const imageErrors = ref({});
const urlAttempts = ref({});

// Image processing logic
const transformGoogleDriveUrl = (url, attemptIndex = 0) => {
    if (!url) {
        return { url: '', isGoogleDrive: false, hasMoreFallbacks: false };
    }

    if (url.includes('drive.google.com')) {
        try {
            let fileId = null;

            if (url.includes('/file/d/')) {
                const match = url.match(/\/file\/d\/([a-zA-Z0-9_-]+)/);
                if (match) {
                    fileId = match[1];
                }
            } else if (url.includes('open?id=')) {
                const match = url.match(/[?&]id=([a-zA-Z0-9_-]+)/);
                if (match) {
                    fileId = match[1];
                }
            }

            if (fileId) {
                const urlFormats = [
                    `/proxy/google-drive/${fileId}`,
                    `https://drive.google.com/thumbnail?id=${fileId}&sz=s400`,
                    `https://drive.google.com/uc?export=view&id=${fileId}`,
                    `https://drive.google.com/uc?export=download&id=${fileId}`,
                    `https://docs.google.com/uc?export=view&id=${fileId}`,
                    `https://lh3.googleusercontent.com/d/${fileId}=s400`
                ];

                if (attemptIndex < urlFormats.length) {
                    const transformedUrl = urlFormats[attemptIndex];
                    return {
                        url: transformedUrl,
                        isGoogleDrive: true,
                        hasMoreFallbacks: attemptIndex < urlFormats.length - 1,
                        fileId,
                        attemptIndex,
                        totalFormats: urlFormats.length,
                        allFormats: urlFormats
                    };
                } else {
                    return { url: '', isGoogleDrive: true, hasMoreFallbacks: false, fileId };
                }
            }
        } catch (error) {
            console.error('Error transforming Google Drive URL:', error, 'URL:', url);
        }
    }
    return { url, isGoogleDrive: false, hasMoreFallbacks: false };
};

const getItemImages = (item) => {
    let rawUrls = [];
    try {
        if (item.image_url) {
            rawUrls = typeof item.image_url === 'string' ? JSON.parse(item.image_url) : item.image_url;
        }
    } catch (e) {
        console.error('Error parsing item images:', e);
    }

    if (!Array.isArray(rawUrls)) {
        rawUrls = rawUrls ? [rawUrls] : [];
    }

    return rawUrls.map(originalUrl => {
        if (urlAttempts.value[originalUrl] === undefined) {
            urlAttempts.value[originalUrl] = 0;
        }

        const currentAttempt = urlAttempts.value[originalUrl];
        const urlInfo = transformGoogleDriveUrl(originalUrl, currentAttempt);

        return {
            type: 'existing',
            url: urlInfo.url,
            id: originalUrl,
            originalUrl,
            urlInfo,
            attemptIndex: currentAttempt
        };
    });
};

const handleImageLoad = (imageId) => {
    imageLoadingStates.value[imageId] = false;
    imageErrors.value[imageId] = null;
    urlAttempts.value[imageId] = 0;
};

const handleImageError = (imageId, image) => {
    if (image.urlInfo && image.urlInfo.isGoogleDrive && image.urlInfo.hasMoreFallbacks) {
        const nextAttempt = (urlAttempts.value[imageId] || 0) + 1;
        const newAttempts = { ...urlAttempts.value };
        newAttempts[imageId] = nextAttempt;
        urlAttempts.value = newAttempts;
        return;
    }

    imageLoadingStates.value[imageId] = false;
    imageErrors.value[imageId] = 'Error';
};

const initializeImageLoading = (image) => {
    const imageId = image.id;
    imageLoadingStates.value[imageId] = true;
    imageErrors.value[imageId] = null;
};

const handleImageClick = (image) => {
    if (imageLoadingStates.value[image.id] || imageErrors.value[image.id]) {
        return;
    }
    window.open(image.url, '_blank');
};

const openImageModal = (item) => {
    selectedItemImages.value = getItemImages(item);
    showImageModal.value = true;
};

// Per page options with proper labels
const perPageOptions = [
    { label: '25 rows', value: 25 },
    { label: '50 rows', value: 50 },
    { label: '100 rows', value: 100 },
    { label: '200 rows', value: 200 }
];

// Top waste items options
const topLimitOptions = [
    { label: 'Top 5', value: 5 },
    { label: 'Top 10', value: 10 },
    { label: 'Top 20', value: 20 },
    { label: 'Top 50', value: 50 },
    { label: 'All items', value: 0 }
];

const topSortOptions = [
    { label: 'Total Amount', value: 'total_amount' },
    { label: 'Total Quantity', value: 'total_qty' }
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
const status = ref(props.filters.status || 'approved_lvl2');
const search = ref(props.filters.search || '');
const perPage = ref(props.filters.per_page || 50);
const activeTab = ref(props.filters.tab || 'details');
const topLimit = ref(props.filters.top_limit ?? 10);
const topSort = ref(props.filters.top_sort || 'total_amount');

// Tab actually rendered by the server response
const renderedTab = computed(() => props.filters.tab || 'details');
const isTopItemsTab = computed(() => renderedTab.value === 'top_items');

const { hasAccess } = useAuth();

// Enhanced filter management with loading states
const updateFilters = (tab = activeTab.value) => {
    isLoading.value = true;
    router.get(
        route('reports.wastage-report.index'),
        {
            tab,
            top_limit: topLimit.value,
            top_sort: topSort.value,
            date_from: dateFrom.value,
            date_to: dateTo.value,
            store_branch_id: storeBranchId.value,
            status: status.value,
            search: search.value,
            per_page: perPage.value,
            sort_field: sortField.value,
            sort_direction: sortDirection.value,
            filter_wastage_no: filterWastageNo.value,
            filter_store: filterStore.value,
            filter_item: filterItem.value,
            filter_status: filterStatus.value,
            filter_reason: filterReason.value,
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
watch([dateFrom, dateTo, storeBranchId, status, perPage, topLimit, topSort],
    throttle(() => updateFilters(), 300)
);

// Watch for search and column filters with longer throttling
watch([search, filterWastageNo, filterStore, filterItem, filterStatus, filterReason],
    throttle(() => updateFilters(), 500)
);

// Tab switching
const selectTab = (tab) => {
    if (tab.enabled === false || activeTab.value === tab.key) return;

    activeTab.value = tab.key;
    updateFilters(tab.key);
};

// Quick range helper for the per-month report
const applyLastTwelveMonths = () => {
    const today = new Date();
    const start = new Date(today.getFullYear(), today.getMonth() - 11, 1);

    const toInput = (date) => {
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${date.getFullYear()}-${month}-${day}`;
    };

    dateFrom.value = toInput(start);
    dateTo.value = toInput(today);
};

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
    if (filterWastageNo.value) count++;
    if (filterStore.value) count++;
    if (filterItem.value) count++;
    if (filterStatus.value) count++;
    if (filterReason.value) count++;
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
    status.value = 'approved_lvl2';
    search.value = '';
    perPage.value = 50;
    filterWastageNo.value = '';
    filterStore.value = '';
    filterItem.value = '';
    filterStatus.value = '';
    filterReason.value = '';
    sortField.value = '';
    sortDirection.value = 'asc';
    topLimit.value = 10;
    topSort.value = 'total_amount';
};

// Export route
const exportRoute = computed(() =>
    route('reports.wastage-report.export', {
        tab: renderedTab.value,
        top_limit: topLimit.value,
        top_sort: topSort.value,
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

// Format quantity keeping decimals (wastage qty is stored with 3 decimals)
const formatQty = (num) => {
    const value = Number(num || 0);
    return new Intl.NumberFormat('en-PH', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 3
    }).format(value);
};

// Format percentage share
const formatPercent = (num) => `${Number(num || 0).toFixed(1)}%`;

// Highlight styling for the first three ranks of each month
const getRankClass = (rank) => {
    if (rank === 1) return 'bg-red-100 text-red-700 border-red-200';
    if (rank === 2) return 'bg-orange-100 text-orange-700 border-orange-200';
    if (rank === 3) return 'bg-amber-100 text-amber-700 border-amber-200';
    return 'bg-gray-100 text-gray-600 border-gray-200';
};

function formatDate(dateString) {
  if (!dateString) return 'N/A'
  const date = new Date(dateString)

  // Format date: MM/DD/YYYY
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const year = date.getFullYear()

  // Format time: HH:MM A.M./P.M.
  let hours = date.getHours()
  const minutes = String(date.getMinutes()).padStart(2, '0')
  const ampm = hours >= 12 ? 'P.M.' : 'A.M.'
  hours = hours % 12
  hours = hours ? hours : 12 // 0 should be 12

  return `${month}/${day}/${year} ${String(hours).padStart(2, '0')}:${minutes} ${ampm}`
}

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
        <!-- Report Tabs -->
        <div v-if="tabs.length" class="mb-5 overflow-x-auto">
            <div class="inline-flex min-w-full gap-2 border-b border-gray-200">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    :disabled="tab.enabled === false"
                    @click="selectTab(tab)"
                    class="whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition"
                    :class="[
                        activeTab === tab.key
                            ? 'border-blue-600 text-blue-700'
                            : 'border-transparent text-gray-500 hover:text-gray-800',
                        tab.enabled === false ? 'cursor-not-allowed opacity-45 hover:text-gray-500' : ''
                    ]"
                >
                    {{ tab.label }}
                </button>
            </div>
        </div>

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
                <div :class="['grid grid-cols-1 md:grid-cols-2 gap-4', isTopItemsTab ? 'lg:grid-cols-6' : 'lg:grid-cols-5']">
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

                    <!-- Per Page (details tab only) -->
                    <div v-if="!isTopItemsTab" class="space-y-2">
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

                    <!-- Top N per month (top items tab only) -->
                    <div v-if="isTopItemsTab" class="space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <ListOrdered class="w-4 h-4" />
                            Show per Month
                        </label>
                        <Select
                            v-model="topLimit"
                            :options="topLimitOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                            :clearable="false"
                        />
                    </div>

                    <!-- Ranking basis (top items tab only) -->
                    <div v-if="isTopItemsTab" class="space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                            <TrendingUp class="w-4 h-4" />
                            Rank By
                        </label>
                        <Select
                            v-model="topSort"
                            :options="topSortOptions"
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
                        <template v-if="isTopItemsTab">
                            {{ topItems.length }} month{{ topItems.length === 1 ? '' : 's' }} in the selected range
                        </template>
                        <template v-else>
                            Showing {{ paginatedData?.data?.length || 0 }} of {{ paginatedData?.total || 0 }} results
                        </template>
                    </div>
                    <div class="flex items-center gap-3">
                        <Button
                            v-if="isTopItemsTab"
                            @click="applyLastTwelveMonths"
                            variant="outline"
                            class="flex items-center gap-2 px-4 py-2 border-gray-300 hover:bg-gray-50 transition-colors"
                        >
                            <CalendarDays class="w-4 h-4" />
                            Last 12 Months
                        </Button>
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
                        <p class="text-2xl font-bold text-gray-900">{{ formatQty(summaryTotals.total_quantity || 0) }}</p>
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
        <div v-if="!isTopItemsTab" class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-visible">
            <!-- Loading Overlay -->
            <div v-if="isLoading" class="absolute inset-0 bg-white/80 flex items-center justify-center z-10">
                <div class="flex items-center gap-3 text-gray-600">
                    <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600"></div>
                    <span>Loading...</span>
                </div>
            </div>

            <!-- Desktop Table -->
            <div class="hidden lg:block overflow-visible">
                <table class="min-w-full border-separate border-spacing-0">
                    <thead class="sticky -top-4 lg:-top-6 z-20 bg-gray-50 shadow-sm border-b border-gray-200">
                        <tr class="text-xs text-gray-500 uppercase tracking-wider">
                            <th @click="handleSort('wastage_no')" class="px-6 py-4 text-left font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-1">
                                        <span>Wastage #</span>
                                        <ArrowUp v-if="sortField === 'wastage_no' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'wastage_no' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterWastageNo" @click.stop placeholder="Filter..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
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
                            <th @click="handleSort('item_description')" class="px-6 py-4 text-left font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-1">
                                        <span>Item Description</span>
                                        <ArrowUp v-if="sortField === 'item_description' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'item_description' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterItem" @click.stop placeholder="Filter Item..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
                                </div>
                            </th>
                            <th rowspan="2" @click="handleSort('wastage_qty')" class="px-6 py-4 text-center font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-center gap-1">
                                    <span>Qty</span>
                                    <ArrowUp v-if="sortField === 'wastage_qty' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'wastage_qty' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th rowspan="2" @click="handleSort('cost')" class="px-6 py-4 text-right font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-end gap-1">
                                    <span>Unit Cost</span>
                                    <ArrowUp v-if="sortField === 'cost' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'cost' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th rowspan="2" @click="handleSort('total_cost')" class="px-6 py-4 text-right font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-end gap-1">
                                    <span>Total Cost</span>
                                    <ArrowUp v-if="sortField === 'total_cost' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'total_cost' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                </div>
                            </th>
                            <th @click="handleSort('wastage_status')" class="px-6 py-4 text-center font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-center gap-1">
                                        <span>Status</span>
                                        <ArrowUp v-if="sortField === 'wastage_status' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'wastage_status' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterStatus" @click.stop placeholder="Filter..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
                                </div>
                            </th>
                            <th @click="handleSort('reason')" class="px-6 py-4 text-left font-medium border-r border-gray-100 cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between gap-1">
                                        <span>Reason</span>
                                        <ArrowUp v-if="sortField === 'reason' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === 'reason' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                    </div>
                                    <Input v-model="filterReason" @click.stop placeholder="Filter..." class="h-7 text-[10px] font-normal normal-case border-gray-200 focus:ring-1 focus:ring-blue-100" />
                                </div>
                            </th>
                            <th rowspan="2" class="px-6 py-4 text-center font-medium border-r border-gray-100">Attachments</th>
                            <th rowspan="2" @click="handleSort('created_at')" class="px-6 py-4 text-left font-medium cursor-pointer hover:bg-gray-100 group transition-colors">
                                <div class="flex items-center justify-between gap-1">
                                    <span>Date</span>
                                    <ArrowUp v-if="sortField === 'created_at' && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowDown v-else-if="sortField === 'created_at' && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                    <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-blue-400" />
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <tr v-if="!paginatedData.data || paginatedData.data.length === 0" class="hover:bg-gray-50">
                            <td colspan="10" class="text-center py-12 text-gray-500">
                                <div class="flex flex-col items-center">
                                    <Trash2 class="w-12 h-12 text-gray-300 mb-3" />
                                    <span class="text-lg font-medium">No data available</span>
                                    <span class="text-sm text-gray-400 mt-1">Try adjusting your filters or search criteria</span>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="item in paginatedData.data" :key="item.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 text-sm font-mono text-gray-900">{{ item.wastage_no || 'N/A' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900 max-w-xs truncate" :title="item.store_branch.name">{{ item.store_branch.name || 'N/A' }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                <span class="font-mono">{{ item.sap_masterfile.ItemCode || 'N/A' }}</span> -
                                <span>{{ item.sap_masterfile.ItemDescription || 'No description' }}</span>
                                <span class="text-gray-500">({{ item.sap_masterfile.BaseUOM || 'N/A' }})</span>
                            </td>
                            <td class="px-6 py-4 text-sm text-center font-medium text-gray-900">{{ formatNumber(item.wastage_qty || 0) }}</td>
                            <td class="px-6 py-4 text-sm text-right font-medium text-gray-900">{{ formatCurrency(item.cost || 0) }}</td>
                            <td class="px-6 py-4 text-sm text-right font-medium text-gray-900">{{ formatCurrency((item.wastage_qty || 0) * (item.cost || 0)) }}</td>
                            <td class="px-6 py-4 text-center">
                                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full border text-xs font-medium" :class="getStatusClass(item.wastage_status).bg">
                                    <div :class="['w-2 h-2 rounded-full', getStatusClass(item.wastage_status).dot]"></div>
                                    <span :class="getStatusClass(item.wastage_status).text">
                                        {{ getStatusClass(item.wastage_status).label }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 max-w-xs truncate" :title="item.reason">{{ item.reason || 'No reason specified' }}</td>
                            <td class="px-6 py-4 text-center">
                                <Button
                                    v-if="getItemImages(item).length > 0"
                                    variant="outline"
                                    size="sm"
                                    class="h-8 px-2 text-xs flex items-center gap-1"
                                    @click="openImageModal(item)"
                                >
                                    <ImageIcon class="w-3 h-3" />
                                    <span>{{ getItemImages(item).length }}</span>
                                </Button>
                                <span v-else class="text-xs text-gray-400">None</span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ formatDate(item.created_at) }}</td>
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
                    <div v-for="item in paginatedData.data" :key="item.id" class="p-4 hover:bg-gray-50 transition-colors">
                        <!-- Header Row -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex flex-col flex-1">
                                <span class="text-sm font-mono text-gray-900">{{ item.wastage_no || 'N/A' }}</span>
                                <span class="text-xs text-gray-500">{{ item.store_branch.name || 'N/A' }}</span>
                                <div class="text-xs text-gray-700 mt-1">
                                    <span class="font-mono">{{ item.sap_masterfile.ItemCode || 'N/A' }}</span> - {{ item.sap_masterfile.ItemDescription || 'No description' }}
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-semibold text-gray-900">
                                    {{ formatCurrency((item.wastage_qty || 0) * (item.cost || 0)) }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ formatNumber(item.wastage_qty || 0) }} {{ item.sap_masterfile.BaseUOM || 'N/A' }} @ {{ formatCurrency(item.cost || 0) }}
                                </div>
                            </div>
                        </div>

                        <!-- Status and Date -->
                        <div class="flex items-center justify-between mb-2">
                            <div class="inline-flex items-center gap-2 px-2 py-1 rounded-full border text-xs font-medium" :class="getStatusClass(item.wastage_status).bg">
                                <div :class="['w-1.5 h-1.5 rounded-full', getStatusClass(item.wastage_status).dot]"></div>
                                <span :class="getStatusClass(item.wastage_status).text">
                                    {{ getStatusClass(item.wastage_status).label }}
                                </span>
                            </div>
                            <span class="text-xs text-gray-500">{{ formatDate(item.created_at) }}</span>
                        </div>

                        <!-- Reason -->
                        <div v-if="item.reason" class="text-xs text-gray-600 mb-2">
                            <span class="font-medium">Reason:</span> {{ item.reason }}
                        </div>

                        <!-- Attachments (Mobile) -->
                        <div v-if="getItemImages(item).length > 0" class="mt-2 pt-2 border-t border-gray-50 flex items-center justify-between">
                            <span class="text-xs text-gray-500">Attachments:</span>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-7 px-2 text-xs flex items-center gap-1 text-blue-600 hover:text-blue-700 hover:bg-blue-50"
                                @click="openImageModal(item)"
                            >
                                <ImageIcon class="w-3 h-3" />
                                <span>View {{ getItemImages(item).length }} images</span>
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Pagination -->
        <div v-if="!isTopItemsTab && paginatedData" class="bg-white px-6 py-4 border-t border-gray-200 mt-6 rounded-b-xl">
            <Pagination :data="paginatedData" />
        </div>

        <!-- Top Waste Items per Month -->
        <div v-if="isTopItemsTab" class="space-y-6">
            <!-- Loading Overlay -->
            <div v-if="isLoading" class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-700">
                Loading report data...
            </div>

            <!-- Empty State -->
            <div v-if="!topItems.length" class="bg-white rounded-xl border border-gray-200 shadow-sm p-12 text-center">
                <TrendingUp class="w-12 h-12 text-gray-300 mx-auto mb-3" />
                <h3 class="text-lg font-medium text-gray-900">No wastage in the selected range</h3>
                <p class="text-sm text-gray-500 mt-1">Widen the date range or change the status filter to see monthly rankings.</p>
            </div>

            <!-- One card per month, newest first -->
            <div
                v-for="month in topItems"
                :key="month.period"
                class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden"
            >
                <!-- Month Header -->
                <div class="flex flex-col gap-3 border-b border-gray-200 bg-gray-50 px-6 py-4 md:flex-row md:items-center md:justify-between">
                    <div class="flex items-center gap-3">
                        <div class="rounded-lg bg-blue-100 p-2">
                            <CalendarDays class="w-5 h-5 text-blue-600" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ month.period_label }}</h3>
                            <p class="text-xs text-gray-500">
                                {{ formatNumber(month.item_count) }} item{{ month.item_count === 1 ? '' : 's' }} wasted
                                <span v-if="topLimit && month.item_count > month.items.length">
                                    &middot; showing top {{ month.items.length }}
                                </span>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-6">
                        <div class="text-right">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Month Qty</p>
                            <p class="text-lg font-semibold text-gray-900">{{ formatQty(month.month_total_qty) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Month Amount</p>
                            <p class="text-lg font-semibold text-gray-900">{{ formatCurrency(month.month_total_amount) }}</p>
                        </div>
                    </div>
                </div>

                <!-- Desktop Table -->
                <div class="hidden lg:block overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-white border-b border-gray-200">
                            <tr class="text-xs text-gray-500 uppercase tracking-wider">
                                <th class="px-6 py-3 text-center font-medium w-20">Rank</th>
                                <th class="px-6 py-3 text-left font-medium">Item</th>
                                <th class="px-6 py-3 text-center font-medium">UoM</th>
                                <th class="px-6 py-3 text-right font-medium">Total Qty</th>
                                <th class="px-6 py-3 text-right font-medium">Total Amount</th>
                                <th class="px-6 py-3 text-right font-medium">% of Month</th>
                                <th class="px-6 py-3 text-center font-medium">Records</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="item in month.items" :key="item.sap_masterfile_id" class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 text-center">
                                    <span
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-full border text-xs font-semibold"
                                        :class="getRankClass(item.rank)"
                                    >
                                        {{ item.rank }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <span class="font-mono text-gray-600">{{ item.item_code }}</span> -
                                    <span>{{ item.item_description }}</span>
                                </td>
                                <td class="px-6 py-4 text-sm text-center text-gray-600">{{ item.uom }}</td>
                                <td class="px-6 py-4 text-sm text-right font-medium text-gray-900">{{ formatQty(item.total_qty) }}</td>
                                <td class="px-6 py-4 text-sm text-right font-semibold text-gray-900">{{ formatCurrency(item.total_amount) }}</td>
                                <td class="px-6 py-4 text-sm text-right text-gray-600">
                                    <div class="flex items-center justify-end gap-2">
                                        <div class="hidden xl:block h-1.5 w-16 rounded-full bg-gray-100">
                                            <div class="h-1.5 rounded-full bg-blue-500" :style="{ width: Math.min(item.amount_share, 100) + '%' }"></div>
                                        </div>
                                        <span>{{ formatPercent(item.amount_share) }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-center text-gray-600">{{ formatNumber(item.record_count) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Card Layout -->
                <div class="lg:hidden divide-y divide-gray-100">
                    <div v-for="item in month.items" :key="item.sap_masterfile_id" class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3 flex-1">
                                <span
                                    class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold"
                                    :class="getRankClass(item.rank)"
                                >
                                    {{ item.rank }}
                                </span>
                                <div class="min-w-0">
                                    <p class="text-xs font-mono text-gray-500">{{ item.item_code }}</p>
                                    <p class="text-sm text-gray-900">{{ item.item_description }}</p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        {{ formatQty(item.total_qty) }} {{ item.uom }} &middot; {{ formatNumber(item.record_count) }} record{{ item.record_count === 1 ? '' : 's' }}
                                    </p>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-sm font-semibold text-gray-900">{{ formatCurrency(item.total_amount) }}</p>
                                <p class="text-xs text-gray-500">{{ formatPercent(item.amount_share) }} of month</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Image Attachment Dialog -->
        <Dialog v-model:open="showImageModal">
            <DialogContent class="sm:max-w-md md:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Image Attachments</DialogTitle>
                </DialogHeader>
                <div class="p-2">
                    <div v-if="selectedItemImages.length > 0" class="flex flex-wrap gap-4 justify-center">
                        <div
                            v-for="image in selectedItemImages"
                            :key="image.id"
                            class="relative group cursor-pointer"
                            @click="handleImageClick(image)"
                        >
                            <div
                                v-if="imageLoadingStates[image.id]"
                                class="w-24 h-24 md:w-32 md:h-32 bg-gray-100 rounded-lg flex items-center justify-center border border-gray-200"
                            >
                                <Loader2 class="w-6 h-6 text-blue-600 animate-spin" />
                            </div>
                            <div
                                v-else-if="imageErrors[image.id]"
                                class="w-24 h-24 md:w-32 md:h-32 bg-red-50 rounded-lg flex flex-col items-center justify-center border border-red-100 text-red-500 p-2 text-center"
                            >
                                <ImageIcon class="w-6 h-6 mb-1 opacity-50" />
                                <span class="text-[10px]">Error loading image</span>
                            </div>
                            <div v-else class="relative overflow-hidden rounded-lg border border-gray-200 shadow-sm transition-all group-hover:shadow-md">
                                <img
                                    :src="image.url"
                                    class="w-24 h-24 md:w-32 md:h-32 object-cover transition-transform duration-300 group-hover:scale-110"
                                    @load="() => handleImageLoad(image.id)"
                                    @error="() => handleImageError(image.id, image)"
                                    @loadstart="() => initializeImageLoading(image)"
                                />
                                <div class="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors flex items-center justify-center">
                                    <div class="opacity-0 group-hover:opacity-100 bg-white/90 p-1.5 rounded-full shadow-sm transition-opacity">
                                        <Search class="w-4 h-4 text-gray-700" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center py-8 text-gray-500 italic">
                        No images found for this item.
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
