<script setup>
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { throttle } from 'lodash';
import { Filter } from 'lucide-vue-next';
import { useSelectOptions } from "@/Composables/useSelectOptions";

// CRITICAL FIX: Removed component imports, assuming global registration or central import
// import Layout from '@/Layouts/AuthenticatedLayout.vue';
// import TableContainer from '../../Components/TableContainer.vue';
// import TableHeader from '../../Components/TableHeader.vue';
// import Table from '../../Components/Table.vue';
// import TableHead from '../../Components/TableHead.vue';
// import TableBody from '../../Components/TableBody.vue';
// import TH from '../../Components/TH.vue';
// import TD from '../../Components/TD.vue';
// import Input from '../../Components/Input.vue';
// import Select from '../../Components/Select.vue';
// import Button from '../../Components/Button.vue';


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
        type: Object, // Laravel Collection mapped to options
        required: true,
    },
    suppliers: {
        type: Object, // Laravel Collection mapped to options
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
};

const exportRoute = computed(() =>
    route('reports.consolidated-so.export', {
        order_date: orderDate.value,
        supplier_id: supplierId.value,
    })
);

// Helper to format date for display
const formatDisplayDate = (dateString) => {
    if (!dateString) return 'N/a';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    } catch (e) {
        console.error("Error formatting date:", dateString, e);
        return dateString;
    }
};

// Calculate the colspan for the dynamic branch headers
const dynamicHeadersColspan = computed(() => {
    // 3 static headers at the start (ITEM CODE, ITEM NAME, UNIT)
    // 2 static headers at the end (TOTAL, WHSE)
    // The rest are dynamic branch headers
    return props.dynamicHeaders.length - 3 - 2;
});

// Calculate total number of columns for table width
const totalColumns = computed(() => props.dynamicHeaders.length);

// CRITICAL FIX: Calculate percentage widths for columns
const calculateColWidth = (type) => {
    const baseWidth = 100; // Total percentage for all columns
    const staticCols = 5; // ITEM CODE, ITEM NAME, UNIT, TOTAL, WHSE
    const dynamicCols = dynamicHeadersColspan.value;

    const totalCalculatedCols = staticCols + dynamicCols;

    // Define base percentages for static columns
    const itemCodePct = 10; // ITEM CODE
    const itemNamePct = 25; // ITEM NAME
    const unitPct = 8;    // UNIT
    const totalPct = 10;   // TOTAL
    const whsePct = 7;    // WHSE

    const staticWidthSum = itemCodePct + itemNamePct + unitPct + totalPct + whsePct;
    const remainingPctForDynamic = baseWidth - staticWidthSum;

    if (dynamicCols === 0) {
        // If no dynamic columns, distribute remaining percentage among static if needed
        // For simplicity, we'll just ensure static columns have their defined width
        switch (type) {
            case 'item_code': return `${itemCodePct}%`;
            case 'item_name': return `${itemNamePct}%`;
            case 'unit': return `${unitPct}%`;
            case 'total': return `${totalPct}%`;
            case 'whse': return `${whsePct}%`;
            default: return '0%'; // Should not happen if dynamicCols is 0
        }
    } else {
        const dynamicColPct = remainingPctForDynamic / dynamicCols;
        switch (type) {
            case 'item_code': return `${itemCodePct}%`;
            case 'item_name': return `${itemNamePct}%`;
            case 'unit': return `${unitPct}%`;
            case 'dynamic': return `${dynamicColPct}%`;
            case 'total': return `${totalPct}%`;
            case 'whse': return `${whsePct}%`;
            default: return '0%';
        }
    }
};

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
            
            <!-- CRITICAL FIX: Wrap the table in a div for better layout control and use native table elements -->
            <div style="display: block; overflow-x: auto; width: 100%;">
                <table class="min-w-full" style="table-layout: fixed;">
                    <!-- CRITICAL FIX: Define column widths using <colgroup> and <col> tags with percentages -->
                    <colgroup>
                        <col :style="{ width: calculateColWidth('item_code') }"> <!-- ITEM CODE -->
                        <col :style="{ width: calculateColWidth('item_name') }"> <!-- ITEM NAME -->
                        <col :style="{ width: calculateColWidth('unit') }">  <!-- UNIT -->
                        <template v-for="(header, index) in dynamicHeaders">
                            <col v-if="index >= 3 && index < dynamicHeaders.length - 2" :key="`col-${index}`" :style="{ width: calculateColWidth('dynamic') }"> <!-- Dynamic Branch Quantities -->
                        </template>
                        <col :style="{ width: calculateColWidth('total') }"> <!-- TOTAL -->
                        <col :style="{ width: calculateColWidth('whse') }">  <!-- WHSE -->
                    </colgroup>

                    <thead>
                        <tr>
                            <!-- Static Headers -->
                            <th rowspan="2" class="text-left whitespace-nowrap p-2">ITEM CODE</th>
                            <th rowspan="2" class="text-left whitespace-nowrap p-2">ITEM NAME</th>
                            <th rowspan="2" class="text-left whitespace-nowrap p-2">UNIT</th>
                            
                            <!-- Dynamic Branch Headers -->
                            <th :colspan="dynamicHeadersColspan" class="text-center bg-gray-100 p-2">
                               <div class="flex justify-center items-center gap-2">
                                    <span class="font-weight: bold;">BRANCH QUANTITIES</span>
                                    <Filter class="w-4 h-4 text-gray-500" />
                               </div>
                            </th>
                            
                            <!-- Static Trailing Headers -->
                            <th rowspan="2" class="text-right whitespace-nowrap p-2">TOTAL</th>
                            <th rowspan="2" class="text-right whitespace-nowrap p-2">WHSE</th>
                        </tr>
                        <tr>
                            <!-- Dynamic Branch Codes (second row of header) -->
                            <template v-for="(header, index) in dynamicHeaders">
                                <th v-if="index >= 3 && index < dynamicHeaders.length - 2" :key="`branch-header-${index}`" class="text-right whitespace-nowrap p-2">
                                    {{ header.label.split(' ')[0] }} <!-- Extracts 'NNTOL' from 'NNTOL Qty' -->
                                </th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, rowIndex) in report" :key="rowIndex">
                            <td class="text-left whitespace-nowrap p-2">{{ row.item_code }}</td>
                            <td class="text-left whitespace-nowrap p-2">{{ row.item_name }}</td>
                            <td class="text-left whitespace-nowrap p-2">{{ row.unit }}</td>
                            
                            <!-- Dynamic Branch Quantities -->
                            <template v-for="(header, colIndex) in dynamicHeaders">
                                <td v-if="colIndex >= 3 && colIndex < dynamicHeaders.length - 2" :key="`branch-data-${rowIndex}-${colIndex}`" class="text-right whitespace-nowrap p-2">
                                    {{ row[header.field] }}
                                </td>
                            </template>

                            <td class="text-right whitespace-nowrap p-2">{{ row.total_quantity }}</td>
                            <td class="text-right whitespace-nowrap p-2">{{ row.whse }}</td>
                        </tr>
                        <tr v-if="report.length === 0">
                            <td :colspan="totalColumns" class="text-center p-4">No data available for the selected filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </TableContainer>
    </Layout>
</template>
