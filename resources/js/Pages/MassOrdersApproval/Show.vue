<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm'; // Import useConfirm

const toast = useToast();
const confirm = useConfirm(); // Instantiate useConfirm

const props = defineProps({
    order: Object,
    // Why this order can no longer be approved or rejected (already committed, received or
    // rejected), or null while it can. The server refuses both actions on the same rule.
    decisionProblem: {
        type: String,
        default: null,
    },
});

const form = useForm({
    items: props.order.store_order_items.map(item => ({
        id: item.id,
        quantity_approved: item.quantity_approved || item.quantity_ordered,
    })),
});

const isCpoOrder = computed(() => {
    return String(props.order.supplier?.supplier_code || '').trim().toUpperCase() === 'CPO';
});

const formatAmount = (value) => (Number(value) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 });

const approveOrder = () => {
    confirm.require({
        message: "Are you sure you want to approve this mass order?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "success", // Green color for approve
        },
        accept: () => {
            form.post(route('mass-orders-approval.approve', props.order.id), {
                onSuccess: () => {
                    toast.add({
                        severity: 'success',
                        summary: 'Success',
                        detail: isCpoOrder.value ? 'Order approved and committed successfully.' : 'Order approved successfully.',
                        life: 3000
                    });
                },
                onError: (errors) => {
                    toast.add({ severity: 'error', summary: 'Error', detail: errors.error ?? 'Failed to approve order.', life: 6000 });
                }
            });
        },
    });
};

const rejectOrder = () => {
    confirm.require({
        message: "Are you sure you want to reject this mass order?",
        header: "Confirmation",
        icon: "pi pi-info-circle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Reject",
            severity: "danger", // Red color for reject
        },
        accept: () => {
            const rejectForm = useForm({});
            rejectForm.post(route('mass-orders-approval.reject', props.order.id), {
                onSuccess: () => {
                    toast.add({ severity: 'success', summary: 'Success', detail: 'Order rejected successfully.', life: 3000 });
                },
                onError: (errors) => {
                    toast.add({ severity: 'error', summary: 'Error', detail: errors.error ?? 'Failed to reject order.', life: 6000 });
                }
            });
        },
    });
};

</script>

<template>
    <Head :title="`Mass Order Approval - ${order.order_number}`" />
    <Layout :heading="`Mass Order Approval - ${order.order_number}`">
        <div class="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Order Details</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p><strong>Order #:</strong> {{ order.order_number }}</p>
                            <p><strong>Supplier:</strong> {{ order.supplier.name }}</p>
                            <p><strong>Store:</strong> {{ order.store_branch.name }}</p>
                        </div>
                        <div>
                            <p><strong>Order Date:</strong> {{ order.order_date }}</p>
                            <p><strong>Status:</strong> {{ order.order_status }}</p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- A CPO order: check the purchase against the vendor's invoice / receipt. -->
            <Card v-if="isCpoOrder">
                <CardHeader>
                    <CardTitle>Vendor and Invoice / Receipt</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <p><strong>Vendor Name:</strong> {{ order.cpo_vendor_name || order.supplier?.name || 'N/A' }}</p>
                        <p><strong>TIN #:</strong> {{ order.cpo_vendor_tin || 'None' }}</p>
                        <p><strong>Address:</strong> {{ order.cpo_vendor_address || 'N/A' }}</p>
                    </div>
                    <div>
                        <p class="font-semibold mb-1">Invoice / Receipt</p>
                        <div v-if="order.cpo_attachments?.length" class="flex flex-wrap gap-2">
                            <a
                                v-for="file in order.cpo_attachments"
                                :key="file.id"
                                :href="file.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center rounded-md border border-gray-200 bg-gray-50 px-3 py-1.5 text-sm text-blue-600 hover:bg-blue-50"
                            >
                                {{ file.original_name }}
                            </a>
                        </div>
                        <p v-else class="text-sm text-gray-500">No invoice or receipt attached.</p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Items</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHead>
                            <TH>Item</TH>
                            <TH v-if="isCpoOrder">UOM</TH>
                            <TH v-if="isCpoOrder">Cost</TH>
                            <TH>Ordered</TH>
                            <TH>Approved</TH>
                        </TableHead>
                        <TableBody>
                            <tr v-for="(item, index) in order.store_order_items" :key="item.id">
                                <TD>{{ item.supplier_item?.item_name ?? item.item_description }}</TD>
                                <TD v-if="isCpoOrder">{{ item.uom }}</TD>
                                <TD v-if="isCpoOrder">{{ formatAmount(item.cost_per_quantity) }}</TD>
                                <TD>{{ item.quantity_ordered }}</TD>
                                <TD>
                                    <Input
                                        type="number"
                                        v-model="form.items[index].quantity_approved"
                                        class="w-24"
                                        :disabled="!!decisionProblem"
                                    />
                                </TD>
                            </tr>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <!-- Approve and Reject go away once the order is committed, received or rejected. -->
            <p
                v-if="decisionProblem"
                class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800"
            >
                {{ decisionProblem }}
            </p>
            <div v-else class="flex justify-end gap-4">
                <Button @click="rejectOrder" variant="destructive">Reject</Button>
                <Button @click="approveOrder" class="bg-green-500 hover:bg-green-300">Approve</Button> <!-- Corrected -->
            </div>
        </div>
    </Layout>
</template>
