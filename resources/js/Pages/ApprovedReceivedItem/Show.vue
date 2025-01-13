<script setup>
import { useBackButton } from "@/Composables/useBackButton";
const { backButton } = useBackButton(route("approved-orders.index"));
import { useForm } from "@inertiajs/vue3";
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

const form = useForm({
    id: null,
    remarks: null,
});
const isCancelModalVisible = ref(false);
const openCancelModal = (id) => {
    form.id = id;
    isCancelModalVisible.value = true;
};

import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
const confirm = useConfirm();
const { toast } = useToast();

const cancelApproveStatus = () => {
    form.put(route("approved-orders.cancel-approve-status"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Cancelled Successfully.",
                life: 3000,
            });
            isCancelModalVisible.value = false;
        },
    });
};
</script>

<template>
    <Layout :heading="`Order Number ${order.order_number}`">
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Item</TH>
                    <TH>Inventory Code</TH>
                    <TH>Received Date</TH>
                    <TH>Quantity Received</TH>
                    <TH>Status?</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items.data" :key="item.id">
                        <TD>{{ item.id }}</TD>
                        <TD>{{
                            item.store_order_item.product_inventory.name
                        }}</TD>
                        <TD>{{
                            item.store_order_item.product_inventory
                                .inventory_code
                        }}</TD>
                        <TD>{{ item.received_date }}</TD>
                        <TD>{{ item.quantity_received }}</TD>
                        <TD>{{ item.status }}</TD>
                        <TD>
                            <Button
                                @click="openCancelModal(item.id)"
                                variant="link"
                                class="text-yellow-500 p-0"
                            >
                                Cancel Approve Status
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="item in items.data" :key="item.id">
                    <MobileTableHeading
                        :title="`${item.store_order_item.product_inventory.name} (${item.store_order_item.product_inventory.inventory_code})`"
                        class="gap-3"
                    >
                        <Button
                            @click="openCancelModal(item.id)"
                            variant="link"
                            class="text-yellow-500 p-0 text-xs"
                        >
                            Cancel Approve Status
                        </Button>
                    </MobileTableHeading>
                    <LabelXS>Status: {{ item.status }}</LabelXS>
                    <LabelXS
                        >Quantity Received:
                        {{ item.quantity_received }}</LabelXS
                    >
                    <LabelXS>Received Date: {{ item.received_date }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="items" />
        </TableContainer>
        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>

        <Dialog v-model:open="isCancelModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Cancel Approve Status</DialogTitle>
                    <DialogDescription>
                        Please input all the required fields.
                    </DialogDescription>
                </DialogHeader>
                <DivFlexCol class="gap-3">
                    <InputContainer>
                        <LabelXS>Remarks</LabelXS>
                        <Textarea type="number" v-model="form.remarks" />
                        <FormError>{{ form.errors.remarks }}</FormError>
                    </InputContainer>
                </DivFlexCol>
                <DialogFooter class="justify-end">
                    <Button @click="cancelApproveStatus">Submit</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
