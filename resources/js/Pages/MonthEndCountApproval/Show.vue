<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Check, X, Pencil, Save, Ban, ArrowLeft } from 'lucide-vue-next';
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";

// Correct props for the Approval page
const props = defineProps({
    schedule: { type: Object, required: true },
    branch: { type: Object, required: true },
    countItems: { type: Object, required: true },
    canEditItems: { type: Boolean, required: true },
    canApproveLevel1: { type: Boolean, required: true },
    canApproveLevel2: { type: Boolean, required: true },
});

const confirm = useConfirm();
const { toast } = useToast();

const editingCell = ref(null);
const editValue = ref('');

// Custom directive to focus and select text on mount
const vFocusSelect = {
  mounted: (el) => {
    const input = el.tagName === 'INPUT' ? el : el.querySelector('input');
    if (input) {
      input.focus();
      input.select();
    }
  }
}

const getMonthName = (monthNumber) => {
    if (!monthNumber) return '';
    const date = new Date();
    date.setMonth(monthNumber - 1);
    return date.toLocaleString('en-US', { month: 'long' });
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    const correctedDate = new Date(date.getTime() + userTimezoneOffset);
    return new Intl.DateTimeFormat('en-US', {
        year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(correctedDate);
};

// Approver can edit items awaiting their approval
const isEditableStatus = (itemStatus) => {
    return itemStatus === 'pending_level1_approval';
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
    editingCell.value = { itemId: item.id, field };
    editValue.value = item[field];
};

const cancelEditing = () => {
    editingCell.value = null;
    editValue.value = '';
};

const saveItemEdit = (item) => {
    if (!editingCell.value) return;
    const { field } = editingCell.value;
    let newValue = editValue.value;

    if (['bulk_qty', 'loose_qty'].includes(field)) {
        newValue = parseFloat(newValue);
        if (isNaN(newValue) || newValue < 0) {
            toast.add({ severity: 'error', summary: 'Invalid Input', detail: `${field} must be a non-negative number.`, life: 3000 });
            return;
        }
    }

    router.put(route('month-end-count-approvals.update-item', item.id), {
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

// --- Approval/Rejection Logic ---
const approve = () => {
    confirm.require({
        message: 'Are you sure you want to approve this count?',
        header: 'Confirm Approval',
        icon: 'pi pi-check-circle',
        accept: () => {
            router.post(route('month-end-count-approvals.approve-level1', { schedule_id: props.schedule.id, branch_id: props.branch.id }), {}, {
                onSuccess: () => {
                    toast.add({ severity: 'success', summary: 'Approved', detail: 'Count has been approved.', life: 3000 });
                },
                onError: (errors) => {
                    const errorMsg = Object.values(errors)[0] || 'An unknown error occurred.';
                    toast.add({ severity: 'error', summary: 'Approval Failed', detail: errorMsg, life: 5000 });
                }
            });
        },
    });
};

const goBack = () => {
    router.get(route('month-end-count-approvals.index'));
};

// Check if there are any items that can be approved
const hasPendingL1Items = computed(() => {
    return props.countItems.data.some(item => item.status === 'pending_level1_approval');
});

const branchStatus = computed(() => {
    return props.countItems.data.length > 0 ? props.countItems.data[0].status : props.schedule.status;
});

</script>

<template>
    <Head :title="`Month End Approval - ${branch.name}`" />

    <Layout :heading="`Month End Approval - ${branch.name}`">
        <div class="mb-6 p-4 bg-white rounded-lg shadow-md">
            <h3 class="text-lg font-semibold mb-2">Schedule Details</h3>
            <p><strong>Year:</strong> {{ schedule.year }}</p>
            <p><strong>Month:</strong> {{ getMonthName(schedule.month) }}</p>
            <p><strong>Calculated Date:</strong> {{ formatDate(schedule.calculated_date) }}</p>
            <p><strong>Branch:</strong> {{ branch.name }}</p>
            <p><strong>Current Status:</strong>
                <Badge class="capitalize" :class="{
                    'bg-yellow-500 text-white': branchStatus === 'pending' || branchStatus === 'uploaded',
                    'bg-purple-500 text-white': branchStatus === 'pending_level1_approval',
                    'bg-teal-500 text-white': branchStatus === 'level1_approved',
                    'bg-green-500 text-white': branchStatus === 'level2_approved',
                    'bg-red-500 text-white': branchStatus === 'rejected' || branchStatus === 'expired',
                }">{{ branchStatus ? branchStatus.replace(/_/g, ' ') : '' }}</Badge>
            </p>
        </div>

        <div class="flex justify-between items-center mb-4">
            <Button @click="goBack" variant="outline">
                <ArrowLeft class="h-4 w-4 mr-2" /> Back
            </Button>
            <div class="flex gap-4" v-if="hasPendingL1Items">
                <Button v-if="canApproveLevel1" @click="approve" variant="success" class="bg-green-600 hover:bg-green-700 text-white">
                    <Check class="h-4 w-4 mr-2" /> Approve
                </Button>
            </div>
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
                        <tr v-if="!countItems.data.length">
                            <td colspan="12" class="text-center py-4">No items awaiting approval found for this count.</td>
                        </tr>
                        <tr v-for="item in countItems.data" :key="item.id">
                            <TD>{{ item.item_code }}</TD>
                            <TD>{{ item.item_name }}</TD>
                            <TD>{{ item.uom }}</TD>
                            <TD>{{ item.packaging_config }}</TD>
                            <TD>{{ item.config }}</TD>
                            <TD>
                                <div v-if="editingCell && editingCell.itemId === item.id && editingCell.field === 'bulk_qty'" class="flex items-center gap-1">
                                    <Input v-focus-select type="number" v-model="editValue" class="w-24 text-right py-1" @keyup.enter="saveItemEdit(item)" @keyup.esc="cancelEditing" />
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-green-600 hover:bg-green-100" @click="saveItemEdit(item)"><Save class="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-red-600 hover:bg-red-100" @click="cancelEditing"><X class="h-4 w-4" /></Button>
                                </div>
                                <div v-else
                                    @click="startEditing(item, 'bulk_qty')"
                                    class="p-1 rounded min-h-[36px] flex items-center justify-end transition-all duration-150"
                                    :class="{
                                        'cursor-pointer hover:bg-blue-100 hover:ring-1 hover:ring-blue-400 bg-blue-50': canEditItems && isEditableStatus(item.status),
                                        'cursor-not-allowed bg-gray-50 text-gray-500': !canEditItems || !isEditableStatus(item.status)
                                    }"
                                >
                                    {{ item.bulk_qty }}
                                    <Pencil v-if="canEditItems && isEditableStatus(item.status)" class="h-3 w-3 ml-1 text-gray-500" />
                                </div>
                            </TD>
                            <TD>
                                <div v-if="editingCell && editingCell.itemId === item.id && editingCell.field === 'loose_qty'" class="flex items-center gap-1">
                                    <Input v-focus-select type="number" v-model="editValue" class="w-24 text-right py-1" @keyup.enter="saveItemEdit(item)" @keyup.esc="cancelEditing" />
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-green-600 hover:bg-green-100" @click="saveItemEdit(item)"><Save class="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-red-600 hover:bg-red-100" @click="cancelEditing"><X class="h-4 w-4" /></Button>
                                </div>
                                <div v-else
                                    @click="startEditing(item, 'loose_qty')"
                                    class="p-1 rounded min-h-[36px] flex items-center justify-end transition-all duration-150"
                                    :class="{
                                        'cursor-pointer hover:bg-blue-100 hover:ring-1 hover:ring-blue-400 bg-blue-50': canEditItems && isEditableStatus(item.status),
                                        'cursor-not-allowed bg-gray-50 text-gray-500': !canEditItems || !isEditableStatus(item.status)
                                    }"
                                >
                                    {{ item.loose_qty }}
                                    <Pencil v-if="canEditItems && isEditableStatus(item.status)" class="h-3 w-3 ml-1 text-gray-500" />
                                </div>
                            </TD>
                            <TD>
                                <div v-if="editingCell && editingCell.itemId === item.id && editingCell.field === 'loose_uom'" class="flex items-center gap-1">
                                    <Input v-focus-select type="text" v-model="editValue" class="w-24 text-right py-1" @keyup.enter="saveItemEdit(item)" @keyup.esc="cancelEditing" />
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-green-600 hover:bg-green-100" @click="saveItemEdit(item)"><Save class="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-red-600 hover:bg-red-100" @click="cancelEditing"><X class="h-4 w-4" /></Button>
                                </div>
                                <div v-else
                                    @click="startEditing(item, 'loose_uom')"
                                    class="p-1 rounded min-h-[36px] flex items-center justify-end transition-all duration-150"
                                    :class="{
                                        'cursor-pointer hover:bg-blue-100 hover:ring-1 hover:ring-blue-400 bg-blue-50': canEditItems && isEditableStatus(item.status),
                                        'cursor-not-allowed bg-gray-50 text-gray-500': !canEditItems || !isEditableStatus(item.status)
                                    }"
                                >
                                    {{ item.loose_uom }}
                                    <Pencil v-if="canEditItems && isEditableStatus(item.status)" class="h-3 w-3 ml-1 text-gray-500" />
                                </div>
                            </TD>
                            <TD>
                                <div v-if="editingCell && editingCell.itemId === item.id && editingCell.field === 'remarks'" class="flex items-center gap-1">
                                    <Input v-focus-select type="text" v-model="editValue" class="w-24 text-right py-1" @keyup.enter="saveItemEdit(item)" @keyup.esc="cancelEditing" />
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-green-600 hover:bg-green-100" @click="saveItemEdit(item)"><Save class="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" class="h-7 w-7 text-red-600 hover:bg-red-100" @click="cancelEditing"><X class="h-4 w-4" /></Button>
                                </div>
                                <div v-else
                                    @click="startEditing(item, 'remarks')"
                                    class="p-1 rounded min-h-[36px] flex items-center justify-end transition-all duration-150"
                                    :class="{
                                        'cursor-pointer hover:bg-blue-100 hover:ring-1 hover:ring-blue-400 bg-blue-50': canEditItems && isEditableStatus(item.status),
                                        'cursor-not-allowed bg-gray-50 text-gray-500': !canEditItems || !isEditableStatus(item.status)
                                    }"
                                >
                                    {{ item.remarks }}
                                    <Pencil v-if="canEditItems && isEditableStatus(item.status)" class="h-3 w-3 ml-1 text-gray-500" />
                                </div>
                            </TD>
                            <TD>{{ calculateTotalQty(item) }}</TD>
                            <TD>
                                <Badge class="capitalize" :class="{
                                    'bg-yellow-500 text-white': item.status === 'pending_level1_approval',
                                    'bg-teal-500 text-white': item.status === 'level1_approved',
                                    'bg-green-500 text-white': item.status === 'level2_approved',
                                    'bg-red-500 text-white': item.status === 'rejected' || item.status === 'expired',
                                }">{{ item.status ? item.status.replace(/_/g, ' ') : '' }}</Badge>
                            </TD>
                            <TD>{{ item.uploader ? `${item.uploader.first_name} ${item.uploader.last_name}` : 'N/A' }}</TD>
                        </tr>
                    </TableBody>
                </Table>
            </div>
            <Pagination :data="countItems" />
        </TableContainer>
    </Layout>
</template>