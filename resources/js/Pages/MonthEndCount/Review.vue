<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed, nextTick } from 'vue';
import { Check, X, Pencil, Save, Ban, ArrowLeft } from 'lucide-vue-next';
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";

const props = defineProps({
    schedule: { type: Object, required: true },
    branch: { type: Object, required: true },
    countItems: { type: Array, required: true }, // Changed from Object to Array
    canEditItems: { type: Boolean, required: true },
});

const confirm = useConfirm();
const { toast } = useToast();

const editingCell = ref(null); // { itemId, field }
const editValue = ref('');
const editInput = ref(null);

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

const getMonthName = (monthNumber) => {
    const date = new Date();
    date.setMonth(monthNumber - 1); // Month is 0-indexed
    return date.toLocaleString('en-US', { month: 'long' });
};

const isEditableStatus = (itemStatus) => {
    return itemStatus === 'uploaded' || itemStatus === 'upload';
};

const calculateTotalQty = (item) => {
    const bulk = parseFloat(item.bulk_qty) || 0;
    const loose = parseFloat(item.loose_qty) || 0;
    const config = parseFloat(item.config) || 1;
    if (config === 0) return bulk + loose;
    return (bulk + (loose / config)).toFixed(4);
};

const startEditing = (item, field) => {
    if (!props.canEditItems || !isEditableStatus(item.status)) {
        toast.add({ severity: 'warn', summary: 'Editing Disabled', detail: 'You do not have permission or the item status prevents editing.', life: 3000 });
        return;
    }
    if (field === 'total_qty') return;

    if (field === 'config' && item.packaging_config) {
        toast.add({ severity: 'warn', summary: 'Editing Disabled', detail: 'Config cannot be edited when Packaging Config has a value.', life: 4000 });
        return;
    }

    editingCell.value = { itemId: item.id, field };
    editValue.value = item[field];

    nextTick(() => {
        if (editInput.value) {
            editInput.value.select();
        }
    });
};

const cancelEditing = () => {
    editingCell.value = null;
    editValue.value = '';
};

const saveItemEdit = (item) => {
    if (!editingCell.value) return;

    const { field } = editingCell.value;
    let newValue = editValue.value;

    if (['bulk_qty', 'loose_qty', 'config'].includes(field)) {
        newValue = parseFloat(newValue);
        if (isNaN(newValue) || (field === 'config' && newValue <= 0) || (field !== 'config' && newValue < 0)) {
            const message = field === 'config' ? 'Config must be a positive number.' : `${field} must be a non-negative number.`;
            toast.add({ severity: 'error', summary: 'Invalid Input', detail: message, life: 3000 });
            return;
        }
    }

    router.put(route('month-end-count.update-review-item', item.id), {
        [field]: newValue,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({ severity: 'success', summary: 'Success', detail: 'Item updated successfully.', life: 3000 });
            cancelEditing();
        },
        onError: (errors) => {
            const errorMsg = Object.values(errors)[0] || 'An unknown error occurred.';
            toast.add({ severity: 'error', summary: 'Update Failed', detail: errorMsg, life: 5000 });
        }
    });
};

const submitForApproval = () => {
    confirm.require({
        message: 'Are you sure you want to submit this count for Level 1 approval? You will no longer be able to edit it.',
        header: 'Confirm Submission',
        icon: 'pi pi-exclamation-triangle',
        accept: () => {
            router.post(route('month-end-count.submit-for-approval', { schedule: props.schedule.id, branch: props.branch.id }), {}, {
                onSuccess: () => {
                    toast.add({ severity: 'success', summary: 'Success', detail: 'Count submitted for Level 1 approval.', life: 3000 });
                },
                onError: (errors) => {
                    const errorMsg = Object.values(errors)[0] || 'An unknown error occurred.';
                    toast.add({ severity: 'error', summary: 'Submission Failed', detail: errorMsg, life: 5000 });
                }
            });
        },
    });
};

const goBack = () => {
    router.get(route('month-end-count.index'));
};

const hasPendingItems = computed(() => {
    return props.countItems.some(item => item.status === 'uploaded');
});

const branchStatus = computed(() => {
    return props.countItems.length > 0 ? props.countItems[0].status : props.schedule.status;
});

</script>

<template>
    <Head :title="`Month End Count Review - ${branch.name}`" />

    <Layout :heading="`Month End Count Review - ${branch.name}`">
        <div class="mb-6 p-4 bg-white rounded-lg shadow-md">
            <h3 class="text-lg font-semibold mb-2">Schedule Details</h3>
            <p><strong>Year:</strong> {{ schedule.year }}</p>
            <p><strong>Month:</strong> {{ getMonthName(schedule.month) }}</p>
            <p><strong>MEC Schedule Date:</strong> {{ schedule.calculated_date }}</p>
            <p><strong>Branch:</strong> {{ branch.name }}</p>
            <p><strong>Current Status:</strong> 
                <Badge class="capitalize" :class="{
                    'bg-yellow-500 text-white': branchStatus === 'pending' || branchStatus === 'uploaded',
                    'bg-blue-500 text-white': branchStatus === 'level1_approved',
                    'bg-green-500 text-white': branchStatus === 'level2_approved',
                    'bg-red-500 text-white': branchStatus === 'rejected' || branchStatus === 'expired',
                    'bg-purple-500 text-white': branchStatus === 'pending_level1_approval',
                }">{{ branchStatus.replace('_', ' ') }}</Badge>
            </p>
        </div>

        <div class="flex justify-between items-center mb-4">
            <Button @click="goBack" variant="outline">
                <ArrowLeft class="h-4 w-4 mr-2" /> Back
            </Button>
            <Button v-if="hasPendingItems" @click="submitForApproval" variant="success" class="bg-green-600 hover:bg-green-700 text-white">
                <Check class="h-4 w-4 mr-2" /> Submit for Level 1 Approval
            </Button>
        </div>

        <TableContainer>
            <div class="overflow-y-auto max-h-[75vh]">
                <Table>
                    <TableHead class="sticky top-0 z-10 bg-gray-100">
                        <TH>Item Code</TH>
                        <TH>Item Name</TH>
                        <TH>UOM</TH>
                        <TH>Packaging Config</TH>
                        <TH>Config</TH>
                        <TH>Bulk Qty</TH>
                        <TH>Loose Qty</TH>
                        <TH>Loose UOM</TH>
                        <TH>Remarks</TH>
                        <TH>Total Qty</TH>
                        <TH>Status</TH>
                        <TH>Uploaded By</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-if="!countItems.length">
                            <td colspan="12" class="text-center py-4">No count items found for this schedule and branch.</td>
                        </tr>
                        <tr v-for="item in countItems" :key="item.id">
                            <TD>{{ item.item_code }}</TD>
                            <TD>{{ item.item_name }}</TD>
                            <TD>{{ item.uom }}</TD>
                            <TD>{{ item.packaging_config }}</TD>
                            <TD>
                                <div v-if="editingCell && editingCell.itemId === item.id && editingCell.field === 'config'" class="flex items-center gap-1">
                                    <Input ref="editInput" v-focus-select type="number" v-model="editValue" class="w-24 text-right py-1" @keyup.enter="saveItemEdit(item)" @keyup.esc="cancelEditing" />
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-green-600 hover:bg-green-100" @click="saveItemEdit(item)"><Save class="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-red-600 hover:bg-red-100" @click="cancelEditing"><X class="h-4 w-4" /></Button>
                                </div>
                                <div v-else
                                    @click="startEditing(item, 'config')"
                                    class="group p-1 rounded min-h-[36px] flex items-center justify-end transition-all duration-150"
                                    :class="{
                                        'cursor-pointer hover:bg-blue-100 hover:ring-1 hover:ring-blue-400 bg-blue-50': props.canEditItems && isEditableStatus(item.status) && !item.packaging_config,
                                        'cursor-not-allowed bg-gray-50 text-gray-500': !props.canEditItems || !isEditableStatus(item.status) || item.packaging_config
                                    }"
                                >
                                    {{ item.config }}
                                    <Pencil v-if="props.canEditItems && isEditableStatus(item.status) && !item.packaging_config" class="h-3 w-3 ml-1 text-gray-500 opacity-0 group-hover:opacity-100" />
                                </div>
                            </TD>
                            <TD>
                                <div v-if="editingCell && editingCell.itemId === item.id && editingCell.field === 'bulk_qty'" class="flex items-center gap-1">
                                    <Input ref="editInput" v-focus-select type="number" v-model="editValue" class="w-24 text-right py-1" @keyup.enter="saveItemEdit(item)" @keyup.esc="cancelEditing" />
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-green-600 hover:bg-green-100" @click="saveItemEdit(item)"><Save class="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-red-600 hover:bg-red-100" @click="cancelEditing"><X class="h-4 w-4" /></Button>
                                </div>
                                <div v-else
                                    @click="startEditing(item, 'bulk_qty')"
                                    class="group p-1 rounded min-h-[36px] flex items-center justify-end transition-all duration-150"
                                    :class="{
                                        'cursor-pointer hover:bg-blue-100 hover:ring-1 hover:ring-blue-400 bg-blue-50': props.canEditItems && isEditableStatus(item.status),
                                        'cursor-not-allowed bg-gray-50 text-gray-500': !props.canEditItems || !isEditableStatus(item.status)
                                    }"
                                >
                                    {{ item.bulk_qty }}
                                    <Pencil v-if="props.canEditItems && isEditableStatus(item.status)" class="h-3 w-3 ml-1 text-gray-500 opacity-0 group-hover:opacity-100" />
                                </div>
                            </TD>
                            <TD>
                                <div v-if="editingCell && editingCell.itemId === item.id && editingCell.field === 'loose_qty'" class="flex items-center gap-1">
                                    <Input ref="editInput" v-focus-select type="number" v-model="editValue" class="w-24 text-right py-1" @keyup.enter="saveItemEdit(item)" @keyup.esc="cancelEditing" />
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-green-600 hover:bg-green-100" @click="saveItemEdit(item)"><Save class="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-red-600 hover:bg-red-100" @click="cancelEditing"><X class="h-4 w-4" /></Button>
                                </div>
                                <div v-else
                                    @click="startEditing(item, 'loose_qty')"
                                    class="group p-1 rounded min-h-[36px] flex items-center justify-end transition-all duration-150"
                                    :class="{
                                        'cursor-pointer hover:bg-blue-100 hover:ring-1 hover:ring-blue-400 bg-blue-50': props.canEditItems && isEditableStatus(item.status),
                                        'cursor-not-allowed bg-gray-50 text-gray-500': !props.canEditItems || !isEditableStatus(item.status)
                                    }"
                                >
                                    {{ item.loose_qty }}
                                    <Pencil v-if="props.canEditItems && isEditableStatus(item.status)" class="h-3 w-3 ml-1 text-gray-500 opacity-0 group-hover:opacity-100" />
                                </div>
                            </TD>
                            <TD>
                                <div v-if="editingCell && editingCell.itemId === item.id && editingCell.field === 'loose_uom'" class="flex items-center gap-1">
                                    <Input ref="editInput" v-focus-select type="text" v-model="editValue" class="w-24 text-right py-1" @keyup.enter="saveItemEdit(item)" @keyup.esc="cancelEditing" />
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-green-600 hover:bg-green-100" @click="saveItemEdit(item)"><Save class="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-red-600 hover:bg-red-100" @click="cancelEditing"><X class="h-4 w-4" /></Button>
                                </div>
                                <div v-else
                                    @click="startEditing(item, 'loose_uom')"
                                    class="group p-1 rounded min-h-[36px] flex items-center justify-end transition-all duration-150"
                                    :class="{
                                        'cursor-pointer hover:bg-blue-100 hover:ring-1 hover:ring-blue-400 bg-blue-50': props.canEditItems && isEditableStatus(item.status),
                                        'cursor-not-allowed bg-gray-50 text-gray-500': !props.canEditItems || !isEditableStatus(item.status)
                                    }"
                                >
                                    {{ item.loose_uom }}
                                    <Pencil v-if="props.canEditItems && isEditableStatus(item.status)" class="h-3 w-3 ml-1 text-gray-500 opacity-0 group-hover:opacity-100" />
                                </div>
                            </TD>
                            <TD>
                                <div v-if="editingCell && editingCell.itemId === item.id && editingCell.field === 'remarks'" class="flex items-center gap-1">
                                    <Input ref="editInput" v-focus-select type="text" v-model="editValue" class="w-24 text-right py-1" @keyup.enter="saveItemEdit(item)" @keyup.esc="cancelEditing" />
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-green-600 hover:bg-green-100" @click="saveItemEdit(item)"><Save class="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-red-600 hover:bg-red-100" @click="cancelEditing"><X class="h-4 w-4" /></Button>
                                </div>
                                <div v-else
                                    @click="startEditing(item, 'remarks')"
                                    class="group p-1 rounded min-h-[36px] flex items-center justify-end transition-all duration-150"
                                    :class="{
                                        'cursor-pointer hover:bg-blue-100 hover:ring-1 hover:ring-blue-400 bg-blue-50': props.canEditItems && isEditableStatus(item.status),
                                        'cursor-not-allowed bg-gray-50 text-gray-500': !props.canEditItems || !isEditableStatus(item.status)
                                    }"
                                >
                                    {{ item.remarks }}
                                    <Pencil v-if="props.canEditItems && isEditableStatus(item.status)" class="h-3 w-3 ml-1 text-gray-500 opacity-0 group-hover:opacity-100" />
                                </div>
                            </TD>
                            <TD>{{ calculateTotalQty(item) }}</TD>
                            <TD>
                                <Badge class="capitalize" :class="{
                                    'bg-yellow-500 text-white': item.status === 'pending' || item.status === 'uploaded',
                                    'bg-blue-500 text-white': item.status === 'level1_approved',
                                    'bg-green-500 text-white': item.status === 'level2_approved',
                                    'bg-red-500 text-white': item.status === 'rejected' || item.status === 'expired',
                                    'bg-purple-500 text-white': item.status === 'pending_level1_approval',
                                }">{{ item.status.replace('_', ' ') }}</Badge>
                            </TD>
                            <TD>{{ item.uploader ? `${item.uploader.first_name} ${item.uploader.last_name}` : 'N/A' }}</TD>
                        </tr>
                    </TableBody>
                </Table>
            </div>
        </TableContainer>
    </Layout>
</template>