<script setup>
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { throttle } from 'lodash';
import { Filter } from 'lucide-vue-next';
import { useSelectOptions } from "@/Composables/useSelectOptions";

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

const resetFilters = () => {
    orderDate.value = new Date().toISOString().slice(0, 10);
    supplierId.value = 'all';
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

                <Button @click="resetFilters" variant="outline" class="ml-auto">
                    Reset Filters
                </Button>
            </TableHeader>
            
            <div class="bg-white border rounded-md shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-white">
                            <tr class="text-sm text-gray-600">
                                <!-- DYNAMIC STATIC HEADERS -->
                                <th v-for="header in staticHeaders" :key="header.field" rowspan="2" class="px-4 py-3 text-left whitespace-nowrap font-semibold">
                                    {{ header.label }}
                                </th>
                                
                                <!-- Dynamic Branch Headers -->
                                <th :colspan="branchCount" class="px-4 py-3 text-center bg-gray-100">
                                   <div class="flex justify-center items-center gap-2">
                                        <span class="font-semibold">BRANCH QUANTITIES</span>
                                        <Filter class="w-4 h-4 text-gray-500" />
                                   </div>
                                </th>
                                
                                <!-- DYNAMIC TRAILING HEADERS -->
                                <th v-for="header in trailingHeaders" :key="header.field" rowspan="2" class="px-4 py-3 text-right whitespace-nowrap font-semibold">
                                    {{ header.label }}
                                </th>
                            </tr>
                            <tr>
                                <!-- Dynamic Branch Codes (second row of header) -->
                                <th v-for="header in branchHeaders" :key="header.field" class="px-4 py-3 text-right whitespace-nowrap font-semibold">
                                    {{ header.label.replace(' Qty', '') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="report.length === 0">
                                <td :colspan="totalColumns" class="text-center p-4">No data available for the selected filters.</td>
                            </tr>
                            <tr v-for="(row, rowIndex) in report" :key="rowIndex" class="border-t">
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