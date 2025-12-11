<script setup>
import { ref, watch, computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { throttle } from 'lodash';
import { Filter } from 'lucide-vue-next';
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useToast } from "@/Composables/useToast";
import { useConfirm } from "primevue/useconfirm";
import axios from 'axios';

const props = defineProps({
    report: { type: Array, required: true },
    dynamicHeaders: { type: Array, required: true },
    branches: { type: Object, required: true },
    suppliers: { type: Object, required: true },
    filters: { type: Object, required: true },
    totalBranches: { type: Number, required: true },
    branchStatuses: { type: Object, required: true },
    permissions: { type: Object, required: true },
    availableCategories: { type: Array, required: true },
});

const { toast } = useToast();
const confirm = useConfirm();

const { options: branchesOptions } = useSelectOptions(props.branches);
const { options: suppliersOptions } = useSelectOptions(props.suppliers);

const orderDate = ref(props.filters.order_date || new Date().toISOString().slice(0, 10));
const supplierId = ref(props.filters.supplier_id || 'all');
const categoryFilter = ref(props.filters.category || 'all');

// --- Local Report State ---
const localReport = ref([]);

watch(() => props.report, (newVal) => {
    // Deep copy to detach from props
    localReport.value = JSON.parse(JSON.stringify(newVal));
}, { immediate: true, deep: true });

const isEditingDisabled = (brandCode) => {
    const status = props.branchStatuses[brandCode]?.toLowerCase();
    return status === 'received' || status === 'incomplete';
};

const canUserEditRow = (row) => {
    const isFinishedGood = ['FINISHED GOODS', 'FG', 'FINISHED GOOD'].includes(row.category);
    if (isFinishedGood) {
        return props.permissions.canEditFinishedGood;
    } else {
        return props.permissions.canEditOther;
    }
};

const recalculateRow = (row) => {
    let totalQty = 0;
    let allZero = true;
    let isAllocation = false;
    
    // Iterate over branch headers to calculate totals and check logic
    for (const header of branchHeaders.value) {
        const code = header.field;
        // Handle empty strings or invalid numbers as 0
        const val = row[code];
        const committed = (val === '' || val === null || isNaN(parseFloat(val))) ? 0 : parseFloat(val);
        const approved = parseFloat(row['approved_' + code] || 0);
        
        totalQty += committed;
        
        if (committed > 0) {
            allZero = false;
        }
        
        // Allocation: Committed < Approved
        // Using a small epsilon for float comparison safety if needed, but direct comparison usually suffices for this context
        if (committed < approved) {
            isAllocation = true;
        }
    }
    
    row.total_quantity = totalQty;
    
    if (allZero) {
        row.remarks = '86';
    } else if (isAllocation) {
        row.remarks = 'Allocation';
    } else {
        row.remarks = 'Stock Supported';
    }
};

const handleInput = (row, field) => {
    // If user clears the input, set it to 0 immediately
    if (row[field] === '' || row[field] === null) {
        row[field] = 0;
    }
    recalculateRow(row);
};

const updateCommit = async (rowIndex, field) => {
    const row = localReport.value[rowIndex];
    // Ensure we send a valid number to backend
    const val = row[field];
    const newValue = (val === '' || val === null || isNaN(parseFloat(val))) ? 0 : parseFloat(val);
    const originalValue = props.report[rowIndex] ? parseFloat(props.report[rowIndex][field]) : 0;

    // If value hasn't changed effectively, do nothing
    if (newValue === originalValue) return;

    if (newValue < 0) {
        toast.add({ severity: 'error', summary: 'Invalid Input', detail: 'Quantity must be a non-negative number.', life: 3000 });
        // Revert to original
        row[field] = originalValue;
        recalculateRow(row); // Recalc back to original
        return;
    }

    // --- Optimistic Update ---
    // Update the "last saved" reference immediately so subsequent blurs don't re-trigger
    if (props.report[rowIndex]) {
        props.report[rowIndex][field] = newValue;
        // CRITICAL FIX: Also update the derived fields in props so the watcher (which fires on prop change)
        // doesn't revert localReport's derived values to the old state.
        props.report[rowIndex].total_quantity = row.total_quantity;
        props.report[rowIndex].remarks = row.remarks;
    }

    // Show Success Feedback Immediately
    toast.add({ severity: 'success', summary: 'Saved', detail: 'Quantity updated successfully.', life: 2000 });

    try {
        await axios.post(route('cs-mass-commits.update-commit'), {
            order_date: orderDate.value,
            item_code: row.item_code,
            brand_code: field,
            new_quantity: newValue,
        });

        // Ensure calculations are consistent
        recalculateRow(row);

    } catch (error) {
        console.error('Update failed', error);
        
        // --- Revert on Failure ---
        // Revert local state
        row[field] = originalValue;
        
        // Revert the "last saved" reference
        if (props.report[rowIndex]) {
            props.report[rowIndex][field] = originalValue;
        }
        
        recalculateRow(row);
        
        const errorMsg = error.response?.data?.message || 'Failed to update quantity.';
        toast.add({ severity: 'error', summary: 'Update Failed', detail: errorMsg, life: 5000 });
    }
};

const handleEnterKey = (event) => {
    event.preventDefault(); // Prevent default Enter key behavior (e.g., form submission)

    const inputs = Array.from(
        document.querySelectorAll('td input[type="number"]:not([disabled])')
    ); // Select all editable number inputs in table cells
    
    const currentIndex = inputs.indexOf(event.target);

    if (currentIndex > -1 && currentIndex < inputs.length - 1) {
        const nextInput = inputs[currentIndex + 1];
        nextInput.focus();
        nextInput.select(); // Select text for easy overwriting
    } else {
        // If it's the last input, or no other input found, just blur
        event.target.blur();
    }
};

// --- Confirm All Logic ---
const isProcessing = ref(false);

const confirmAllCommits = () => {
    if (isProcessing.value) {
        toast.add({
            severity: 'warn',
            summary: 'Processing',
            detail: 'Commit process is already running. Please wait.',
            life: 3000
        });
        return;
    }

    confirm.require({
        message: `Are you sure you want to commit all orders for ${orderDate.value}? This action cannot be undone.`,
        header: 'Confirm All Commits',
        icon: 'pi pi-exclamation-triangle',
        acceptClass: 'p-button-success',
        rejectClass: 'p-button-danger',
        accept: () => {
            console.log('CS Mass Commits - Starting confirm-all request', {
                order_date: orderDate.value,
                supplier_id: supplierId.value,
                timestamp: new Date().toISOString()
            });

            isProcessing.value = true;

            router.post(route('cs-mass-commits.confirm-all'), {
                order_date: orderDate.value,
                supplier_id: supplierId.value,
            }, {
                preserveState: true,
                preserveScroll: true,
                onStart: () => {
                    console.log('CS Mass Commits - Request started');
                    toast.add({
                        severity: 'info',
                        summary: 'Processing',
                        detail: 'Committing orders... This may take a moment.',
                        life: 2000
                    });
                },
                onSuccess: (page) => {
                    console.log('CS Mass Commits - Request successful', {
                        response: page,
                        timestamp: new Date().toISOString()
                    });

                    isProcessing.value = false;

                    // Extract message from flash data if available
                    const flashMessage = page.props.flash?.success || page.props.flash?.info;
                    const messageText = flashMessage || 'Orders have been processed.';
                    const messageType = page.props.flash?.success ? 'success' : (page.props.flash?.info ? 'info' : 'success');

                    toast.add({
                        severity: messageType,
                        summary: 'Success',
                        detail: messageText,
                        life: 4000
                    });

                    // Force a page reload to refresh the data
                    setTimeout(() => {
                        router.reload({
                            preserveScroll: true,
                            onSuccess: () => {
                                console.log('CS Mass Commits - Page reloaded successfully');
                            }
                        });
                    }, 500);
                },
                onError: (errors) => {
                    console.error('CS Mass Commits - Request failed', {
                        errors: errors,
                        timestamp: new Date().toISOString()
                    });

                    isProcessing.value = false;

                    const errorMsg = Object.values(errors)[0] || 'An unknown error occurred during the commit process.';
                    toast.add({
                        severity: 'error',
                        summary: 'Commit Failed',
                        detail: errorMsg,
                        life: 6000
                    });
                },
                onFinish: () => {
                    console.log('CS Mass Commits - Request finished');
                    isProcessing.value = false;
                }
            });
        },
        reject: () => {
            console.log('CS Mass Commits - User cancelled the commit operation');
        }
    });
};

// --- Status Badge Color (Copied from MassOrders/Index.vue) ---
const statusBadgeColor = (status) => {
    switch (status?.toUpperCase()) {
        case "RECEIVED": return "bg-green-500 text-white";
        case "APPROVED": return "bg-teal-500 text-white";
        case "INCOMPLETE": return "bg-orange-500 text-white";
        case "PENDING": return "bg-yellow-500 text-white";
        case "COMMITTED": return "bg-blue-500 text-white";
        case "PARTIAL_COMMITTED": return "bg-indigo-500 text-white";
        case "REJECTED": return "bg-red-500 text-white";
        default: return "bg-gray-500 text-white";
    }
};

watch([orderDate, supplierId, categoryFilter], throttle(() => {
    router.get(
        route('cs-mass-commits.index'),
        {
            order_date: orderDate.value,
            supplier_id: supplierId.value,
            category: categoryFilter.value,
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
    categoryFilter.value = 'all';
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

const staticHeaders = computed(() => props.dynamicHeaders.slice(0, 5));
const branchHeaders = computed(() => props.dynamicHeaders.slice(5, -3));
const trailingHeaders = computed(() => props.dynamicHeaders.slice(-3));

const branchCount = computed(() => branchHeaders.value.length);
const totalColumns = computed(() => staticHeaders.value.length + branchCount.value + trailingHeaders.value.length);

const sortedReport = computed(() => localReport.value);

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

                <div class="flex items-center gap-4">
                    <label for="category_filter" class="text-sm font-medium text-gray-700">Category:</label>
                    <Select
                        id="category_filter"
                        filter
                        placeholder="All Categories"
                        v-model="categoryFilter"
                        :options="props.availableCategories.map(c => ({ label: c, value: c }))"
                        optionLabel="label"
                        optionValue="value"
                        class="w-64"
                    >
                        <template #header>
                            <div class="p-2">
                                <Button text @click="categoryFilter = 'all'" class="w-full text-left">All Categories</Button>
                            </div>
                        </template>
                    </Select>
                </div>

                <div class="flex items-center gap-2 ml-auto">
                    <Button @click="resetFilters" variant="outline">
                        Reset Filters
                    </Button>
                    <Button
                        v-if="canConfirmAny"
                        @click="confirmAllCommits"
                        variant="destructive"
                        :disabled="isProcessing"
                        :class="{ 'opacity-50 cursor-not-allowed': isProcessing }"
                    >
                        <span v-if="isProcessing" class="flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Processing...
                        </span>
                        <span v-else>Confirm All Commits</span>
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
                            <tr v-if="sortedReport.length === 0">
                                <td :colspan="totalColumns" class="text-center p-4">No data available for the selected filters.</td>
                            </tr>
                            <tr v-for="(row, rowIndex) in sortedReport" :key="rowIndex" class="border-t">
                                <td v-for="header in staticHeaders" :key="header.field" class="px-4 py-3 text-left whitespace-nowrap">
                                    {{ row[header.field] }}
                                </td>
                                
                                <td v-for="header in branchHeaders" :key="header.field" class="px-4 py-3 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1">
                                        <span 
                                            v-if="row['approved_' + header.field] !== undefined && parseFloat(row['approved_' + header.field]) > 0"
                                            class="text-xs font-medium px-2 py-1 rounded-md bg-blue-100 text-blue-800"
                                            title="Approved Quantity"
                                        >
                                            {{ parseFloat(row['approved_' + header.field]).toFixed(2) }}
                                        </span>
                                        <input
                                            type="number"
                                            v-model="row[header.field]"
                                            class="w-24 px-2 py-1 border rounded text-right transition-colors"
                                            :class="[
                                                !isEditingDisabled(header.field) && canUserEditRow(row)
                                                    ? 'border-gray-300 focus:ring-1 focus:ring-blue-500 focus:border-blue-500'
                                                    : 'border-gray-200 bg-gray-50 text-gray-400 cursor-not-allowed'
                                            ]"
                                            :disabled="isEditingDisabled(header.field) || !canUserEditRow(row)"
                                            @input="handleInput(row, header.field)"
                                            @blur="updateCommit(rowIndex, header.field)"
                                            @keydown.enter="handleEnterKey"
                                        />
                                    </div>
                                </td>

                                <td v-for="header in trailingHeaders" :key="header.field"
                                    class="px-4 py-3 text-right whitespace-nowrap">
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

