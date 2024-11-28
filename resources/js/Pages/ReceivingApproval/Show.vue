<script setup>
import { useBackButton } from "@/Composables/useBackButton";
import Checkbox from "primevue/checkbox";
import { useForm } from "@inertiajs/vue3";
const { backButton } = useBackButton(route("receiving-approvals.index"));

import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
const toast = useToast();
const confirm = useConfirm();

const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
    items: {
        type: Object,
        required: true,
    },
});

const selectedItems = ref([]);

const approveReceivedItemForm = useForm({
    id: null,
});
const approveAllItems = () => {
    approveReceivedItemForm.id = [];
    approveReceivedItemForm.id = props.items.map((item) => item.id);
    confirm.require({
        message: "Are you sure you want to approve all the items status?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "success",
        },
        accept: () => {
            approveReceivedItemForm.post(
                route("receiving-approvals.approve-received-item"),
                {
                    onSuccess: () => {
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: "Received Items Status Approved Successfully.",
                            life: 3000,
                        });
                    },
                }
            );
        },
    });
};

const approveSeletedItems = () => {
    approveReceivedItemForm.id = selectedItems.value;
    confirm.require({
        message: "Are you sure you want to approve the selected items status?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "success",
        },
        accept: () => {
            approveReceivedItemForm.post(
                route("receiving-approvals.approve-received-item"),
                {
                    onSuccess: () => {
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: "Received Item Status Approved Successfully.",
                            life: 3000,
                        });
                    },
                }
            );
        },
    });
};

const approveReceivedItem = (id) => {
    approveReceivedItemForm.id = id;
    confirm.require({
        message: "Are you sure you want to approve this received item status?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "success",
        },
        accept: () => {
            approveReceivedItemForm.post(
                route("receiving-approvals.approve-received-item"),
                {
                    onSuccess: () => {
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: "Received Item Status Approved Successfully.",
                            life: 3000,
                        });
                    },
                }
            );
        },
    });
};
</script>

<template>
    <Layout :heading="`Order Number ${order.order_number}`">
        <TableContainer>
            <TableHeader class="justify-between">
                <Button
                    v-if="selectedItems.length > 0"
                    @click="approveSeletedItems"
                    variant="outline"
                    >Approve Selected Items</Button
                >
                <Button class="bg-green-500" @click="approveAllItems"
                    >Approve All</Button
                >
            </TableHeader>
            <Table>
                <TableHead>
                    <TH> </TH>
                    <TH>Item</TH>
                    <TH>Inventory Code</TH>
                    <TH>Received Date</TH>
                    <TH>Quantity Received</TH>
                    <TH>Is Approved?</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items" :key="item.id">
                        <TD>
                            <Checkbox
                                v-model="selectedItems"
                                :value="item.id"
                                :inputId="`item-${item.id}`"
                            />
                        </TD>
                        <TD>{{
                            item.store_order_item.product_inventory.name
                        }}</TD>
                        <TD>{{
                            item.store_order_item.product_inventory
                                .inventory_code
                        }}</TD>
                        <TD>{{ item.received_date }}</TD>
                        <TD>{{ item.quantity_received }}</TD>
                        <TD>{{ item.is_approved == 1 ? "Yes" : "No" }}</TD>
                        <TD>
                            <Button
                                @click="approveReceivedItem(item.id)"
                                variant="link"
                                class="text-green-500 p-0"
                            >
                                Approve
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>
