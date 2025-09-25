<script setup>
import { ref, watch, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { throttle } from 'lodash';
import { Filter, Check, X } from 'lucide-vue-next';
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useToast } from "@/Composables/useToast";

const props = defineProps({
    report: { type: Array, required: true },
    dynamicHeaders: { type: Array, required: true },
    branches: { type: Object, required: true },
    suppliers: { type: Object, required: true },
    filters: { type: Object, required: true },
    totalBranches: { type: Number, required: true },
});

const { toast } = useToast();

const { options: branchesOptions } = useSelectOptions(props.branches);
const { options: suppliersOptions } = useSelectOptions(props.suppliers);

const orderDate = ref(props.filters.order_date || new Date().toISOString().slice(0, 10));
const supplierId = ref(props.filters.supplier_id || 'all');

// --- Inline Editing State ---
const editingCell = ref(null); // { rowIndex, field }
const editValue = ref('');

// Custom directive to focus and select text on mount
const vFocusSelect = {
  mounted: (el) => {
    // Find the actual input element, which might be nested inside the component
    const input = el.querySelector('input');
    if (input) {
      input.focus();
      input.select();
    } else if (typeof el.focus === 'function') {
      // Fallback for plain elements or components that expose focus directly
      el.focus();
      if (typeof el.select === 'function') {
        el.select();
      }
    }
  }
}

const startEditing = (row, field, rowIndex) => {
    editingCell.value = { rowIndex, field };
    editValue.value = row[field];
    // The v-focus-select directive will handle focus and selection automatically
};

const cancelEditing = () => {
    editingCell.value = null;
    editValue.value = '';
};

const saveCommit = () => {
    if (!editingCell.value) return;

    const { rowIndex, field } = editingCell.value;
    const row = props.report[rowIndex];
    const newValue = parseFloat(editValue.value);

    if (isNaN(newValue) || newValue < 0) {
        toast.add({ severity: 'error', summary: 'Invalid Input', detail: 'Quantity must be a non-negative number.', life: 3000 });
        return;
    }

    router.post(route('cs-mass-commits.update-commit'), {
        order_date: orderDate.value,
        item_code: row.item_code,
        brand_code: field,
        new_quantity: newValue,
    }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            toast.add({ severity: 'success', summary: 'Success', detail: 'Commit quantity updated.', life: 3000 });
            cancelEditing();
        },
        onError: (errors) => {
            const errorMsg = Object.values(errors)[0] || 'An unknown error occurred.';
            toast.add({ severity: 'error', summary: 'Update Failed', detail: errorMsg, life: 5000 });
        }
    });
};
// --- End Inline Editing ---

watch([orderDate, supplierId], throttle(() => {
    router.get(
        route('cs-mass-commits.index'),
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
    route('cs-mass-commits.export', {
        order_date: orderDate.value,
        supplier_id: supplierId.value,
    })
);

const staticHeaders = computed(() => props.dynamicHeaders.slice(0, 4));
const branchHeaders = computed(() => props.dynamicHeaders.slice(4, -2));
const trailingHeaders = computed(() => props.dynamicHeaders.slice(-2));

const branchCount = computed(() => branchHeaders.value.length);
const totalColumns = computed(() => staticHeaders.value.length + branchCount.value + trailingHeaders.value.length);

</script>

<template>
    <Layout heading="CS Mass Commits" :hasExcelDownload="true" :exportRoute="exportRoute">
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
                <div class="overflow-x-auto max-h-[75vh] overflow-y-auto">
                    <table class="min-w-full">
                        <thead class="bg-white sticky top-0 z-10">
                            <tr class="text-sm text-gray-600">
                                <th v-for="header in staticHeaders" :key="header.field" rowspan="2" class="px-4 py-3 text-left whitespace-nowrap font-semibold">
                                    {{ header.label }}
                                </th>
                                
                                <th :colspan="branchCount" class="px-4 py-3 text-center bg-gray-100">
                                   <div class="flex justify-center items-center gap-2">
                                        <span class="font-semibold">BRANCH QUANTITIES</span>
                                        <Filter class="w-4 h-4 text-gray-500" />
                                   </div>
                                </th>
                                
                                <th v-for="header in trailingHeaders" :key="header.field" rowspan="2" class="px-4 py-3 text-right whitespace-nowrap font-semibold">
                                    {{ header.label }}
                                </th>
                            </tr>
                            <tr>
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
                                <td v-for="header in staticHeaders" :key="header.field" class="px-4 py-3 text-left whitespace-nowrap">
                                    {{ row[header.field] }}
                                </td>
                                
                                <td v-for="header in branchHeaders" :key="header.field" class="px-4 py-3 text-right whitespace-nowrap">
                                    <div v-if="editingCell && editingCell.rowIndex === rowIndex && editingCell.field === header.field" class="flex items-center justify-end gap-1">
                                        <Input v-focus-select type="number" v-model="editValue" class="w-24 text-right py-1" @keyup.enter="saveCommit" @keyup.esc="cancelEditing" />
                                        <Button variant="ghost" size="icon" class="h-7 w-7 text-green-600 hover:bg-green-100" @click="saveCommit"><Check class="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" class="h-7 w-7 text-red-600 hover:bg-red-100" @click="cancelEditing"><X class="h-4 w-4" /></Button>
                                    </div>
                                    <div v-else @click="startEditing(row, header.field, rowIndex)" class="cursor-pointer p-1 rounded min-h-[36px] flex items-center justify-end transition-all duration-150 hover:bg-blue-100 hover:ring-1 hover:ring-blue-400">
                                        {{ row[header.field] }}
                                    </div>
                                </td>

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