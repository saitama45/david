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

// CRITICAL FIX: Extract branch headers reliably for colgroup and dynamic rendering
const branchHeaders = computed(() => {
    // dynamicHeaders contains all headers. The branch headers are from index 3 up to length - 2.
    return props.dynamicHeaders.slice(3, props.dynamicHeaders.length - 2);
});

const branchCount = computed(() => branchHeaders.value.length);

// colspan should be branchCount
const dynamicHeadersColspan = computed(() => branchCount.value);

// total columns = 3 static + branchCount + 2 trailing static
const totalColumns = computed(() => 3 + branchCount.value + 2);

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
            
            <!-- CRITICAL FIX: Use native table elements and colgroup for robust alignment -->
            <div class="bg-white border rounded-md shadow-sm">
                <!-- CRITICAL FIX: Removed the "Report Data" div -->
                <!-- <div class="px-4 py-3 border-b">
                    <span class="font-semibold text-gray-700">Report Data</span>
                </div> -->

                <div class="overflow-x-auto">
                    <table class="min-w-full table-fixed" style="table-layout: fixed;">
                        <!-- Important: colgroup fixes widths for header and body, using percentages -->
                        <colgroup>
                            <!-- Static Columns -->
                            <col style="width: 10%;"> <!-- ITEM CODE -->
                            <col style="width: 25%;"> <!-- ITEM NAME -->
                            <col style="width: 8%;">  <!-- UNIT -->
                            
                            <!-- Dynamic Branch Quantities -->
                            <template v-for="(header, i) in branchHeaders" :key="`col-branch-${i}`">
                                <col style="width: 5%;"> <!-- Each dynamic column gets a fixed percentage -->
                            </template>

                            <!-- Static Trailing Columns -->
                            <col style="width: 10%;"> <!-- TOTAL -->
                            <col style="width: 7%;">  <!-- WHSE -->
                        </colgroup>

                        <thead class="bg-white">
                            <tr class="text-sm text-gray-600">
                                <!-- Static Headers -->
                                <th rowspan="2" class="px-4 py-3 text-left whitespace-nowrap">ITEM CODE</th>
                                <th rowspan="2" class="px-4 py-3 text-left whitespace-nowrap">ITEM NAME</th>
                                <th rowspan="2" class="px-4 py-3 text-left whitespace-nowrap">UNIT</th>
                                
                                <!-- Dynamic Branch Headers -->
                                <th :colspan="dynamicHeadersColspan" class="px-4 py-3 text-center bg-gray-100">
                                   <div class="flex justify-center items-center gap-2">
                                        <span class="font-weight: bold;">BRANCH QUANTITIES</span>
                                        <Filter class="w-4 h-4 text-gray-500" />
                                   </div>
                                </th>
                                
                                <!-- Static Trailing Headers -->
                                <th rowspan="2" class="px-4 py-3 text-right whitespace-nowrap">TOTAL</th>
                                <th rowspan="2" class="px-4 py-3 text-right whitespace-nowrap">WHSE</th>
                            </tr>
                            <tr>
                                <!-- Dynamic Branch Codes (second row of header) -->
                                <template v-for="(header, idx) in branchHeaders" :key="`branch-header-${idx}`">
                                    <th class="px-4 py-3 text-right whitespace-nowrap">
                                        {{ header.label.split(' ')[0] }} <!-- Extracts 'NNTOL' from 'NNTOL Qty' -->
                                    </th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, rowIndex) in report" :key="rowIndex" class="border-t">
                                <td class="px-4 py-3 text-left whitespace-nowrap">{{ row.item_code }}</td>
                                <td class="px-4 py-3 text-left whitespace-nowrap">{{ row.item_name }}</td>
                                <td class="px-4 py-3 text-left whitespace-nowrap">{{ row.unit }}</td>
                                
                                <!-- Dynamic Branch Quantities -->
                                <template v-for="(header, colIndex) in branchHeaders" :key="`branch-data-${rowIndex}-${colIndex}`">
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        {{ row[header.field] }}
                                    </td>
                                </template>

                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ row.total_quantity }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ row.whse }}</td>
                            </tr>
                            <tr v-if="report.length === 0">
                                <td :colspan="totalColumns" class="text-center p-4">No data available for the selected filters.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </TableContainer>
    </Layout>
</template>
