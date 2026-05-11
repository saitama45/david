<script setup>
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { throttle } from 'lodash';
import { Filter, Search, ArrowUpDown, ArrowUp, ArrowDown } from 'lucide-vue-next';
import { useSelectOptions } from "@/composables/useSelectOptions";

const props = defineProps({
    report: {
        type: Array,
        required: true,
    },
    dynamicHeaders: {
        type: Array,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
    suppliers: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
    totalBranches: {
        type: Number,
        required: true,
    }
});

const { options: branchesOptions } = useSelectOptions(props.branches);
const { options: suppliersOptions } = useSelectOptions(props.suppliers);

const orderDate = ref(props.filters.order_date || new Date().toISOString().slice(0, 10));
const supplierId = ref(props.filters.supplier_id || 'all');
const searchQuery = ref('');
const sortField = ref('');
const sortDirection = ref('asc');

watch([orderDate, supplierId], throttle(() => {
    router.get(
        route('reports.consolidated-so.index'),
        {
            order_date: orderDate.value,
            supplier_id: supplierId.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    );
}, 300));

const handleSort = (field) => {
    if (sortField.value === field) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = field;
        sortDirection.value = 'asc';
    }
};

const filteredAndSortedData = computed(() => {
    let result = [...props.report];

    // Filter
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        result = result.filter(row => {
            return Object.values(row).some(val => 
                String(val).toLowerCase().includes(query)
            );
        });
    }

    // Sort
    if (sortField.value) {
        result.sort((a, b) => {
            let valA = a[sortField.value];
            let valB = b[sortField.value];

            // Handle numeric values
            const numA = parseFloat(valA);
            const numB = parseFloat(valB);
            
            if (!isNaN(numA) && !isNaN(numB)) {
                valA = numA;
                valB = numB;
            } else {
                valA = String(valA).toLowerCase();
                valB = String(valB).toLowerCase();
            }

            if (valA < valB) return sortDirection.value === 'asc' ? -1 : 1;
            if (valA > valB) return sortDirection.value === 'asc' ? 1 : -1;
            return 0;
        });
    }

    return result;
});

const resetFilters = () => {
    orderDate.value = new Date().toISOString().slice(0, 10);
    supplierId.value = 'all';
    searchQuery.value = '';
    sortField.value = '';
};

const exportRoute = computed(() =>
    route('reports.consolidated-so.export', {
        order_date: orderDate.value,
        supplier_id: supplierId.value,
    })
);

// --- NEW: Computed properties to dynamically separate headers ---
const staticHeaders = computed(() => props.dynamicHeaders.slice(0, 4));
const branchHeaders = computed(() => props.dynamicHeaders.slice(4, -2));
const trailingHeaders = computed(() => props.dynamicHeaders.slice(-2));

const branchCount = computed(() => branchHeaders.value.length);
const totalColumns = computed(() => staticHeaders.value.length + branchCount.value + trailingHeaders.value.length);

</script>

<template>
    <Layout heading="Consolidated SO Report" :hasExcelDownload="true" :exportRoute="exportRoute">
        <TableContainer>
            <TableHeader class="flex-wrap">
                <div class="flex items-center gap-4">
                    <label for="order_date" class="text-sm font-medium text-gray-700">Date:</label>
                    <Input
                        id="order_date"
                        type="date"
                        v-model="orderDate"
                        class="w-48"
                    />
                </div>

                <div class="flex items-center gap-4">
                    <label for="supplier_filter" class="text-sm font-medium text-gray-700">Supplier:</label>
                    <Select
                        id="supplier_filter"
                        filter
                        placeholder="Select a Supplier"
                        v-model="supplierId"
                        :options="suppliersOptions"
                        optionLabel="label"
                        optionValue="value"
                        class="w-64"
                    />
                </div>

                <div class="flex items-center gap-2">
                    <div class="relative">
                        <Search class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 transform -translate-y-1/2" />
                        <Input
                            v-model="searchQuery"
                            placeholder="Global Search..."
                            class="pl-9 w-64"
                        />
                    </div>
                </div>

                <Button @click="resetFilters" variant="outline" class="ml-auto">
                    Reset Filters
                </Button>
            </TableHeader>
            
            <div class="bg-white border rounded-md shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-white sticky -top-4 lg:-top-6 z-20">
                            <tr class="text-sm text-gray-600">
                                <!-- DYNAMIC STATIC HEADERS -->
                                <th v-for="header in staticHeaders" :key="header.field" rowspan="2" 
                                    @click="handleSort(header.field)"
                                    class="px-4 py-3 text-left whitespace-nowrap font-semibold cursor-pointer hover:bg-gray-50 transition-colors group">
                                    <div class="flex items-center gap-2">
                                        {{ header.label }}
                                        <ArrowUp v-if="sortField === header.field && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === header.field && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-gray-400" />
                                    </div>
                                </th>
                                
                                <!-- Dynamic Branch Headers -->
                                <th :colspan="branchCount" class="px-4 py-3 text-center bg-gray-100">
                                   <div class="flex justify-center items-center gap-2">
                                        <span class="font-semibold">BRANCH QUANTITIES</span>
                                        <Filter class="w-4 h-4 text-gray-500" />
                                   </div>
                                </th>
                                
                                <!-- DYNAMIC TRAILING HEADERS -->
                                <th v-for="header in trailingHeaders" :key="header.field" rowspan="2" 
                                    @click="handleSort(header.field)"
                                    class="px-4 py-3 text-right whitespace-nowrap font-semibold cursor-pointer hover:bg-gray-50 transition-colors group">
                                    <div class="flex items-center justify-end gap-2">
                                        {{ header.label }}
                                        <ArrowUp v-if="sortField === header.field && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === header.field && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-gray-400" />
                                    </div>
                                </th>
                            </tr>
                            <tr>
                                <!-- Dynamic Branch Codes (second row of header) -->
                                <th v-for="header in branchHeaders" :key="header.field" 
                                    @click="handleSort(header.field)"
                                    class="px-4 py-3 text-right whitespace-nowrap font-semibold cursor-pointer hover:bg-gray-50 transition-colors group">
                                    <div class="flex items-center justify-end gap-2">
                                        {{ header.label.replace(' Qty', '') }}
                                        <ArrowUp v-if="sortField === header.field && sortDirection === 'asc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowDown v-else-if="sortField === header.field && sortDirection === 'desc'" class="w-3 h-3 text-blue-600" />
                                        <ArrowUpDown v-else class="w-3 h-3 text-gray-300 group-hover:text-gray-400" />
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="filteredAndSortedData.length === 0">
                                <td :colspan="totalColumns" class="text-center p-4">No data available for the selected filters.</td>
                            </tr>
                            <tr v-for="(row, rowIndex) in filteredAndSortedData" :key="rowIndex" class="border-t hover:bg-gray-50 transition-colors">
                                <!-- DYNAMIC STATIC CELLS -->
                                <td v-for="header in staticHeaders" :key="header.field" class="px-4 py-3 text-left whitespace-nowrap">
                                    {{ row[header.field] }}
                                </td>
                                
                                <!-- Dynamic Branch Quantities -->
                                <td v-for="header in branchHeaders" :key="header.field" class="px-4 py-3 text-right whitespace-nowrap">
                                    {{ row[header.field] }}
                                </td>

                                <!-- DYNAMIC TRAILING CELLS -->
                                <td v-for="header in trailingHeaders" :key="header.field" class="px-4 py-3 text-right whitespace-nowrap">
                                    {{ row[header.field] }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </TableContainer>
    </Layout>
</template>
