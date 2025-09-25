<script setup>
import { ref, watch, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { throttle } from 'lodash';
import { Filter, Check, X } from 'lucide-vue-next';
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useToast } from "@/Composables/useToast";
import { useConfirm } from "primevue/useconfirm";

const props = defineProps({
    report: { type: Array, required: true },
    dynamicHeaders: { type: Array, required: true },
    branches: { type: Object, required: true },
    suppliers: { type: Object, required: true },
    filters: { type: Object, required: true },
    totalBranches: { type: Number, required: true },
    branchStatuses: { type: Object, required: true }, // NEW PROP
});

const { toast } = useToast();
const confirm = useConfirm();

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
    const input = el.querySelector('input');
    if (input) {
      input.focus();
      input.select();
    } else if (typeof el.focus === 'function') {
      el.focus();
      if (typeof el.select === 'function') {
        el.select();
      }
    }
  }
}

const isEditingDisabled = (brandCode) => {
    const status = props.branchStatuses[brandCode]?.toLowerCase();
    return status === 'received' || status === 'incomplete';
};

const startEditing = (row, field, rowIndex) => {
    if (isEditingDisabled(field)) {
        toast.add({
            severity: 'warn',
            summary: 'Editing Disabled',
            detail: `Cannot edit commits for this branch as its order has already been processed.`,
            life: 4000,
        });
        return;
    }
    editingCell.value = { rowIndex, field };
    editValue.value = row[field];
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

// --- Confirm All Logic ---
const confirmAllCommits = () => {
    confirm.require({
        message: `Are you sure you want to commit all orders for ${orderDate.value}? This action cannot be undone.`,
        header: 'Confirm All Commits',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.post(route('cs-mass-commits.confirm-all'), {
                order_date: orderDate.value,
                supplier_id: supplierId.value,
            }, {
                onSuccess: () => {
                    toast.add({ severity: 'success', summary: 'Success', detail: 'All orders have been committed.', life: 3000 });
                },
                onError: (errors) => {
                    const errorMsg = Object.values(errors)[0] || 'An unknown error occurred during the commit process.';
                    toast.add({ severity: 'error', summary: 'Commit Failed', detail: errorMsg, life: 5000 });
                }
            });
        },
    });
};

// --- Status Badge Color (Copied from MassOrders/Index.vue) ---
const statusBadgeColor = (status) => {
    switch (status?.toUpperCase()) {
        case "APPROVED": return "bg-green-500 text-white";
        case "RECEIVED": return "bg-green-500 text-white";
        case "PENDING": return "bg-yellow-500 text-white";
        case "COMMITED": return "bg-blue-500 text-white";
        case "REJECTED": return "bg-red-400 text-white";
        default: return "bg-gray-500 text-white";
    }
};

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

const canConfirmAny = computed(() => {
    const statuses = Object.values(props.branchStatuses);
    if (statuses.length === 0) {
        return false;
    }
    // Check if there is at least one branch that is NOT received or incomplete
    return statuses.some(status =>
        status?.toLowerCase() !== 'received' && status?.toLowerCase() !== 'incomplete'
    );
});

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

                <div class="flex items-center gap-2 ml-auto">
                    <Button @click="resetFilters" variant="outline">
                        Reset Filters
                    </Button>
                    <Button v-if="canConfirmAny" @click="confirmAllCommits" variant="destructive">
                        Confirm All Commits
                    </Button>
                </div>
            </TableHeader>
            
            <div class="bg-white border rounded-md shadow-sm">
                <div class="overflow-x-auto max-h-[75vh] overflow-y-auto">
                    <table class="min-w-full">
                        <thead class="bg-slate-100 sticky top-0 z-10 text-slate-800 shadow-sm">
                            <!-- Main Header Row -->
                            <tr class="text-sm">
                                <!-- Static Headers -->
                                <th v-for="header in staticHeaders" :key="header.field" rowspan="2" 
                                    class="px-4 py-3 text-left whitespace-nowrap font-bold border-b-2 border-slate-200 bg-slate-200">
                                    {{ header.label }}
                                </th>
                                
                                <!-- Group Header for Branches -->
                                <th :colspan="branchCount" class="px-4 py-4 text-center bg-blue-100 border-b-2 border-slate-200">
                                   <div class="flex justify-center items-center gap-2 font-bold text-blue-800">
                                        <span>BRANCH QUANTITIES</span>
                                        <Filter class="w-4 h-4" />
                                   </div>
                                </th>
                                
                                <!-- Trailing Headers -->
                                <th v-for="header in trailingHeaders" :key="header.field" rowspan="2" 
                                    class="px-4 py-3 text-right whitespace-nowrap font-bold border-b-2 border-slate-200 bg-slate-200">
                                    {{ header.label }}
                                </th>
                            </tr>
                            <!-- Sub-Header Row for Branches -->
                            <tr>
                                <th v-for="header in branchHeaders" :key="header.field" 
                                    class="px-4 py-2 text-center whitespace-nowrap font-semibold border-b-2 border-slate-200 bg-blue-50">
                                    <div>{{ header.label.replace(' Qty', '') }}</div>
                                    <div v-if="props.branchStatuses[header.field]" class="text-xs font-normal mt-1">
                                        <span :class="statusBadgeColor(props.branchStatuses[header.field])" class="px-2 py-1 rounded-full shadow-sm">
                                            {{ props.branchStatuses[header.field].toUpperCase() }}
                                        </span>
                                    </div>
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
                                                                        <div v-else
                                        @click="startEditing(row, header.field, rowIndex)"
                                        class="p-1 rounded min-h-[36px] flex items-center justify-end transition-all duration-150"
                                        :class="{
                                            'cursor-pointer hover:bg-blue-100 hover:ring-1 hover:ring-blue-400': !isEditingDisabled(header.field),
                                            'cursor-not-allowed bg-gray-50 text-gray-500': isEditingDisabled(header.field)
                                        }"
                                    >
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