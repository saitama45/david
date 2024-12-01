<script setup>
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
const toast = useToast();

const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
    orderedItems: {
        type: Object,
        required: true,
    },
    receiveDatesHistory: {
        type: Object,
        required: true,
    },
});

const targetId = ref(null);
const itemDetails = ref(null);
const form = useForm({
    quantity_received: null,
    received_date:
        new Date().toLocaleDateString("en-CA") +
        "T" +
        new Date().toLocaleTimeString("en-PH", {
            hour: "2-digit",
            minute: "2-digit",
            hour12: false,
        }),
    expiry_date: null,
    remarks: null,
});

const deliveryReceiptForm = useForm({
    store_order_id: props.order.id,
    delivery_receipt_number: null,
    remarks: null,
});

const showItemDetails = ref(false);
itemDetails.value = props.orderedItems[1];
const opentItemDetails = (id) => {
    const index = props.orderedItems.findIndex((order) => order.id === id);
    itemDetails.value = props.orderedItems[index];
    showItemDetails.value = true;
};

const showReceiveForm = ref(false);
const showDeliveryReceiptForm = ref(false);

const openReceiveForm = (id) => {
    targetId.value = id;
    showReceiveForm.value = true;
};

const submitReceivingForm = () => {
    isLoading.value = true;
    form.post(route("orders-receiving.receive", targetId.value), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Received Quantity Updated Successfully.",
                life: 5000,
            });
            showReceiveForm.value = false;
            isLoading.value = false;
            form.reset();
        },
        onError: (e) => {
            console.log(e);
        },
    });
};

const submitDeliveryReceiptForm = () => {
    isLoading.value = true;
    deliveryReceiptForm.post(
        route("orders-receiving.add-delivery-receipt-number"),
        {
            onSuccess: () => {
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: "Received Quantity Updated Successfully.",
                    life: 5000,
                });
                showReceiveForm.value = false;
                isLoading.value = false;
                deliveryReceiptForm.reset();
            },
            onError: (e) => {
                console.log(e);
            },
        }
    );
};
const isLoading = ref(false);
</script>

<template>
    <Layout heading="Order Details">
        <div class="grid grid-cols-3 gap-5">
            <Card>
                <CardHeader class="gap-5">
                    <div class="divide-y">
                        <DivFlexCenter class="justify-between py-3">
                            <Label class="flex-1">Order Number: </Label>
                            <Label class="flex-1">{{
                                order.order_number
                            }}</Label>
                        </DivFlexCenter>
                        <DivFlexCenter class="justify-between py-3">
                            <Label class="flex-1">Order Date: </Label>
                            <Label class="flex-1">{{ order.order_date }}</Label>
                        </DivFlexCenter>
                        <DivFlexCenter class="justify-between py-3">
                            <Label class="flex-1">Order Request Status: </Label>
                            <Label class="flex-1">{{
                                order.order_request_status.toUpperCase()
                            }}</Label>
                        </DivFlexCenter>
                        <DivFlexCenter class="justify-between py-3">
                            <Label class="flex-1"
                                >Order Receiving Status:
                            </Label>
                            <Label class="flex-1">{{
                                order.order_status
                                    .toUpperCase()
                                    .replace("_", " ")
                            }}</Label>
                        </DivFlexCenter>
                        <DivFlexCenter class="justify-between py-3">
                            <Label class="flex-1 self-start">Remarks: </Label>
                            <Label class="flex-1">{{
                                order.remarks ?? "None"
                            }}</Label>
                        </DivFlexCenter>
                    </div>
                </CardHeader>
            </Card>
            <TableContainer class="col-span-2 min-w-fit">
                <section class="flex justify-end gap-3">
                    <Button>Attach Image</Button>
                    <Button @click="showDeliveryReceiptForm = true"
                        >Add Delivery Number</Button
                    >
                </section>
                <Table>
                    <TableHead>
                        <TH> Item Code </TH>
                        <TH> Name </TH>
                        <TH> Quantity Received</TH>
                        <TH> Actions </TH>
                    </TableHead>

                    <TableBody>
                        <tr v-for="order in orderedItems" :key="order.id">
                            <TD>{{
                                order.product_inventory.inventory_code
                            }}</TD>
                            <TD>{{ order.product_inventory.name }}</TD>
                            <TD>{{ order.quantity_received }}</TD>
                            <TD class="w-[90px]">
                                <DivFlexCenter class="gap-1">
                                    <ShowButton
                                        @click="opentItemDetails(order.id)"
                                    >
                                        <Eye />
                                    </ShowButton>
                                    <Button
                                        v-if="
                                            order.quantity_ordered !==
                                            order.quantity_received
                                        "
                                        @click="openReceiveForm(order.id)"
                                        class="text-green-500"
                                        variant="link"
                                    >
                                        Receive
                                    </Button>
                                </DivFlexCenter>
                            </TD>
                        </tr>
                    </TableBody>
                </Table>
            </TableContainer>

            <TableContainer class="col-span-3">
                <CardTitle>Receive Dates History</CardTitle>
                <Table>
                    <TableHead>
                        <TH> Id </TH>
                        <TH> Item </TH>
                        <TH> Item Code </TH>
                        <TH> Received By </TH>
                        <TH> Quantity Received</TH>
                        <TH> Received At</TH>
                    </TableHead>
                    <TableBody>
                        <tr
                            v-for="history in receiveDatesHistory"
                            :key="history.id"
                        >
                            <TD>{{ history.id }}</TD>
                            <TD>{{
                                history.store_order_item.product_inventory.name
                            }}</TD>
                            <TD>{{
                                history.store_order_item.product_inventory
                                    .inventory_code
                            }}</TD>
                            <TD>TBD</TD>
                            <TD>{{ history.quantity_received }}</TD>
                            <TD>{{ history.received_date }}</TD>
                        </tr>
                    </TableBody>
                </Table>
            </TableContainer>
        </div>

        <Dialog v-model:open="showDeliveryReceiptForm">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Delivery Receipt Form</DialogTitle>
                    <DialogDescription
                        >Input all the important details</DialogDescription
                    >
                </DialogHeader>
                <div class="space-y-3">
                    <InputContainer>
                        <Label class="text-xs">Delivery Receipt Number</Label>
                        <Input
                            v-model="
                                deliveryReceiptForm.delivery_receipt_number
                            "
                        />
                        <FormError>{{
                            deliveryReceiptForm.errors.delivery_receipt_number
                        }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs">Remarks</Label>
                        <Textarea v-model="deliveryReceiptForm.remarks" />
                        <FormError>{{
                            deliveryReceiptForm.errors.remarks
                        }}</FormError>
                    </InputContainer>
                </div>
                <DialogFooter>
                    <Button
                        :disabled="isLoading"
                        class="gap-2"
                        @click="submitDeliveryReceiptForm"
                        >Add <span><Loading v-if="isLoading" /></span
                    ></Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="showItemDetails">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>View Details</DialogTitle>
                    <DialogDescription
                        >Ordered Item Information</DialogDescription
                    >
                </DialogHeader>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <span class="text-xs">Name</span>
                        <p>{{ itemDetails.product_inventory.name }}</p>
                    </div>
                    <div>
                        <span class="text-xs">Inventory Code</span>
                        <p>
                            {{ itemDetails.product_inventory.inventory_code }}
                        </p>
                    </div>
                    <div>
                        <span class="text-xs">Conversion</span>
                        <p>{{ itemDetails.product_inventory.conversion }}</p>
                    </div>
                    <div>
                        <span class="text-xs">UOM</span>
                        <p>
                            {{
                                itemDetails.product_inventory
                                    .unit_of_measurement.name
                            }}
                        </p>
                    </div>
                    <div>
                        <span class="text-xs">Cost</span>
                        <p>
                            {{ itemDetails.product_inventory.cost }}
                        </p>
                    </div>
                    <div>
                        <span class="text-xs">Quantity Ordered</span>
                        <p>
                            {{ itemDetails.quantity_ordered }}
                        </p>
                    </div>
                    <div>
                        <span class="text-xs">Quantity Received</span>
                        <p>
                            {{ itemDetails.quantity_received }}
                        </p>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="showReceiveForm">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Receiving Form</DialogTitle>
                    <DialogDescription
                        >Please input the quantity of the item you
                        received.</DialogDescription
                    >
                </DialogHeader>
                <div class="space-y-3">
                    <div class="flex flex-col space-y-1">
                        <Label>Quantity Received</Label>
                        <Input v-model="form.quantity_received" type="number" />
                        <FormError>{{
                            form.errors.quantity_received
                        }}</FormError>
                    </div>
                    <InputContainer>
                        <Label>Received Date</Label>
                        <Input
                            type="datetime-local"
                            v-model="form.received_date"
                        />
                        <FormError>{{ form.errors.received_date }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <Label>Item Expiry Date</Label>
                        <Input type="date" v-model="form.expiry_date" />
                        <FormError>{{ form.errors.expiry_date }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <Label>Remarks</Label>
                        <Textarea v-model="form.remarks" />
                        <FormError>{{ form.errors.remarks }}</FormError>
                    </InputContainer>
                </div>
                <DialogFooter>
                    <Button
                        :disabled="isLoading"
                        type="submit"
                        class="gap-2"
                        @click="submitReceivingForm"
                    >
                        Confirm
                        <span><Loading v-if="isLoading" /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
