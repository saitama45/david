<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Check, ArrowLeft } from 'lucide-vue-next';
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";

const props = defineProps({
    schedule: { type: Object, required: true },
    branch: { type: Object, required: true },
    countItems: { type: Array, required: true },
    canApproveLevel2: { type: Boolean, required: true },
});

const confirm = useConfirm();
const { toast } = useToast();

const getMonthName = (monthNumber) => {
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

const calculateTotalQty = (item) => {
    const bulk = parseFloat(item.bulk_qty) || 0;
    const loose = parseFloat(item.loose_qty) || 0;
    const config = parseFloat(item.config) || 1;
    if (config === 0) return bulk + loose;
    return (bulk + (loose / config)).toFixed(4);
};

const approve = () => {
    confirm.require({
        message: 'Are you sure you want to approve this count for Level 2?',
        header: 'Confirm Level 2 Approval',
        icon: 'pi pi-check-circle',
        rejectClass: 'p-button-danger',
        accept: () => {
            router.post(route('month-end-count-approvals-level2.approve', { schedule_id: props.schedule.id, branch_id: props.branch.id }), {}, {
                onSuccess: () => {
                    const reviewUrl = route('month-end-count.review', { schedule: props.schedule.id, branch: props.branch.id });
                    const linkHtml = `Count has been approved for Level 2. <a href="${reviewUrl}" target="_blank" class="text-blue-500 hover:text-blue-700 underline">View Ref No. ${props.schedule.id}/${props.branch.id}</a>`;
                    toast.add({ severity: 'success', summary: 'Approved', detail: linkHtml, life: 5000 });
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
    router.get(route('month-end-count-approvals-level2.index'));
};

const branchStatus = computed(() => {
    return props.countItems.length > 0 ? props.countItems[0].status : props.schedule.status;
});

const hasItemsForApproval = computed(() => {
    return props.countItems.some(item => item.status === 'level1_approved');
});

</script>

<template>
    <Head :title="`MEC Level 2 Approval - ${branch.name}`" />

    <Layout :heading="`MEC Level 2 Approval - ${branch.name}`">
        <div class="mb-6 p-4 bg-white rounded-lg shadow-md">
            <h3 class="text-lg font-semibold mb-2">Schedule Details</h3>
            <p><strong>Year:</strong> {{ schedule.year }}</p>
            <p><strong>Month:</strong> {{ getMonthName(schedule.month) }}</p>
            <p><strong>MEC Schedule Date:</strong> {{ formatDate(schedule.calculated_date) }}</p>
            <p><strong>Branch:</strong> {{ branch.name }}</p>
            <p><strong>Current Status:</strong>
                <Badge class="capitalize bg-blue-500 text-white">{{ branchStatus.replace(/_/g, ' ') }}</Badge>
            </p>
        </div>

        <div class="flex justify-between items-center mb-4">
            <Button @click="goBack" variant="outline">
                <ArrowLeft class="h-4 w-4 mr-2" /> Back
            </Button>
            <div v-if="hasItemsForApproval">
                <Button v-if="canApproveLevel2" @click="approve" variant="success" class="bg-green-600 hover:bg-green-700 text-white">
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
                        <TH>Category 1</TH>
                        <TH>Area</TH>
                        <TH>Category 2</TH>
                        <TH>Packaging</TH>
                        <TH>Conversion</TH>
                        <TH>Bulk UOM</TH>
                        <TH>Loose UOM</TH>
                        <TH>Current SOH</TH>
                        <TH>Bulk Qty</TH>
                        <TH>Loose Qty</TH>
                        <TH>Remarks</TH>
                        <TH>Total Qty</TH>
                        <TH>Status</TH>
                        <TH>Uploaded By</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-if="!countItems.length">
                            <td colspan="16" class="text-center py-4">No items found for this count.</td>
                        </tr>
                        <tr v-for="item in countItems" :key="item.id">
                            <TD>{{ item.item_code }}</TD>
                            <TD>{{ item.item_name }}</TD>
                            <TD>{{ item.category }}</TD>
                            <TD>{{ item.area }}</TD>
                            <TD>{{ item.category2 }}</TD>
                            <TD>{{ item.packaging_config }}</TD>
                            <TD>{{ item.config }}</TD>
                            <TD>{{ item.uom }}</TD>
                            <TD>{{ item.loose_uom }}</TD>
                            <TD>{{ item.current_soh }}</TD>
                            <TD>{{ item.bulk_qty }}</TD>
                            <TD>{{ item.loose_qty }}</TD>
                            <TD>{{ item.remarks }}</TD>
                            <TD>{{ calculateTotalQty(item) }}</TD>
                            <TD>
                                <Badge class="capitalize bg-blue-500 text-white">{{ item.status.replace('_', ' ') }}</Badge>
                            </TD>
                            <TD>{{ item.uploader ? `${item.uploader.first_name} ${item.uploader.last_name}` : 'N/A' }}</TD>
                        </tr>
                    </TableBody>
                </Table>
            </div>
        </TableContainer>
    </Layout>
</template>
