<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm'; // Import useConfirm

const toast = useToast();
const confirm = useConfirm(); // Instantiate useConfirm

const props = defineProps({
    order: Object,
});

const form = useForm({
    items: props.order.store_order_items.map(item => ({
        id: item.id,
        quantity_approved: item.quantity_approved || item.quantity_ordered,
    })),
});

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
                    toast.add({ severity: 'success', summary: 'Success', detail: 'Order approved successfully.', life: 3000 });
                },
                onError: (errors) => {
                    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to approve order.', life: 3000 });
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
                    toast.add({ severity: 'error', summary: 'Error', detail: 'Failed to reject order.', life: 3000 });
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

            <Card>
                <CardHeader>
                    <CardTitle>Items</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHead>
                            <TH>Item</TH>
                            <TH>Ordered</TH>
                            <TH>Approved</TH>
                        </TableHead>
                        <TableBody>
                            <tr v-for="(item, index) in order.store_order_items" :key="item.id">
                                <TD>{{ item.supplier_item.item_name }}</TD>
                                <TD>{{ item.quantity_ordered }}</TD>
                                <TD>
                                    <Input
                                        type="number"
                                        v-model="form.items[index].quantity_approved"
                                        class="w-24"
                                    />
                                </TD>
                            </tr>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <div class="flex justify-end gap-4">
                <Button @click="rejectOrder" variant="destructive">Reject</Button>
                <Button @click="approveOrder" class="bg-green-500 hover:bg-green-300">Approve</Button> <!-- Corrected -->
            </div>
        </div>
    </Layout>
</template>
