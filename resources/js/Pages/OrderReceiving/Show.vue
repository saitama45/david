<script setup>
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";

import { useConfirm } from "primevue/useconfirm";
import Camera from "@/Pages/Camera.vue";
const toast = useToast();
const confirm = useConfirm();

import { useBackButton } from "@/Composables/useBackButton";
const { backButton } = useBackButton(route("orders-receiving.index"));

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
    images: {
        type: Object,
        required: true,
    },
});

const isImageModalVisible = ref(false);
const openImageModal = () => {
    isImageModalVisible.value = true;
};

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
            isLoading.value = false;
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
                showDeliveryReceiptForm.value = false;
                isLoading.value = false;
                deliveryReceiptForm.reset();
            },
            onError: (e) => {
                console.log(e);
                showDeliveryReceiptForm.value = false;
                isLoading.value = false;
            },
        }
    );
};
const canReceive = props.order.order_status !== "received";

const isLoading = ref(false);

const deleteReceiveDate = (id) => {
    confirm.require({
        message: "Are you sure you want to delete this history?",
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
            router.post(
                route("orders-receiving.delete-receiving-history", id),
                {},
                {
                    onSuccess: (page) => {
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: "Receive Date History Deleted.",
                            life: 5000,
                        });
                        console.log(page);
                    },
                    onError: (errors) => {
                        toast.add({
                            severity: "error",
                            summary: "Error",
                            detail: err,
                            life: 5000,
                        });
                    },
                }
            );
        },
    });
};

const isEditModalVisible = ref(false);
const editReceiveDetailsForm = useForm({
    quantity_received: null,
    expiry_date: null,
});

const openEditModalForm = (id) => {
    const data = props.receiveDatesHistory;
    const existingItemIndex = data.findIndex((history) => history.id === id);
    const history = data[existingItemIndex];
    console.log(history);

    editReceiveDetailsForm.quantity_received = history.quantity_received;
    editReceiveDetailsForm.expiry_date = history.expiry_date;
    isEditModalVisible.value = true;
};
</script>

<template>
    <Layout heading="Order Details">
        <DivFlexCol class="gap-3">
            <Card class="p-5 grid grid-cols-4 gap-5">
                <InputContainer>
                    <LabelXS>Encoder: </LabelXS>
                    <SpanBold
                        >{{ order.encoder.first_name }}
                        {{ order.encoder.last_name }}</SpanBold
                    >
                </InputContainer>
                <InputContainer>
                    <LabelXS>Order Number: </LabelXS>
                    <SpanBold>{{ order.order_number }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Order Date: </LabelXS>
                    <SpanBold>{{ order.order_date }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Order Request Status: </LabelXS>
                    <SpanBold>{{
                        order.order_request_status.toUpperCase()
                    }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Approver: </LabelXS>
                    <SpanBold
                        >{{ order.approver.first_name }}
                        {{ order.approver.last_name }}</SpanBold
                    >
                    <SpanBold v-if="!order.approver">N/a</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Order Receiving Status: </LabelXS>
                    <SpanBold>{{
                        order.order_status.toUpperCase().replace("_", " ")
                    }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Variant: </LabelXS>
                    <SpanBold>{{ order.variant.toUpperCase() }}</SpanBold>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Approval Action Date: </LabelXS>
                    <SpanBold>{{ order.approval_action_date }}</SpanBold>
                </InputContainer>
                <InputContainer class="col-span-4">
                    <LabelXS>Delivery Receipt Numbers: </LabelXS>
                    <DivFlexCol class="flex-1 gap-2">
                        <SpanBold v-for="receipt in order.delivery_receipts">{{
                            receipt.delivery_receipt_number
                        }}</SpanBold>
                    </DivFlexCol>
                    <SpanBold v-if="order.delivery_receipts.length < 1"
                        >None</SpanBold
                    >
                </InputContainer>
            </Card>

            <Card class="p-5">
                <InputContainer class="col-span-4">
                    <LabelXS>Image Attachments: </LabelXS>
                    <DivFlexCenter class="gap-2">
                        <img
                            v-for="image in images"
                            :src="image.image_url"
                            class="size-24"
                        />
                    </DivFlexCenter>
                    <SpanBold v-if="images.length < 1">None</SpanBold>
                </InputContainer>
            </Card>

            <TableContainer class="col-span-2 min-w-fit">
                <section class="flex justify-end gap-3">
                    <Button @click="openImageModal">Attach Image</Button>
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
                                        v-if="canReceive"
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

            <TableContainer>
                <CardTitle>Receive Dates History</CardTitle>
                <Table>
                    <TableHead>
                        <TH> Id </TH>
                        <TH> Item </TH>
                        <TH> Item Code </TH>
                        <!-- <TH> Received By </TH> -->
                        <TH> Quantity Received</TH>
                        <TH> Received At</TH>
                        <TH> Is Approved?</TH>
                        <TH>Actions</TH>
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
                            <!-- <TD>
                                {{ history.receiver.first_name }}
                                {{ history.receiver.last_name }}
                            </TD> -->
                            <TD>{{ history.quantity_received }}</TD>
                            <TD>{{ history.received_date }}</TD>
                            <TD>{{
                                history.is_approved === 1 ? "Yes" : "No"
                            }}</TD>
                            <TD>
                                <DivFlexCenter class="gap-3">
                                    <ShowButton />
                                    <EditButton
                                        v-if="!history.is_approved"
                                        @click="openEditModalForm(history.id)"
                                    />
                                    <DeleteButton
                                        v-if="!history.is_approved"
                                        @click="deleteReceiveDate(history.id)"
                                    />
                                </DivFlexCenter>
                            </TD>
                        </tr>
                    </TableBody>
                </Table>
            </TableContainer>
        </DivFlexCol>

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

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>

        <!-- Camera Modal -->
        <Dialog v-model:open="isImageModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Image Attachment</DialogTitle>
                    <DialogDescription>
                        Please upload or take a picture to proceed.
                    </DialogDescription>
                </DialogHeader>
                <DivFlexCol class="gap-1 p-2 border border-gray-300 rounded-lg">
                    <Camera
                        :is-modal-open="isImageModalVisible"
                        :store_order_id="order.id"
                        @upload-success="isImageModalVisible = false"
                    />
                </DivFlexCol>
            </DialogContent>
        </Dialog>

        <!-- Receive Detail Edti Modal -->
        <Dialog v-model:open="isEditModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Edit Received Item Details</DialogTitle>
                    <DialogDescription>
                        Please input all the required fields.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-3">
                    <div class="flex flex-col space-y-1">
                        <Label>Quantity Received</Label>
                        <Input
                            v-model="editReceiveDetailsForm.quantity_received"
                            type="number"
                        />
                        <FormError>{{
                            editReceiveDetailsForm.errors.quantity_received
                        }}</FormError>
                    </div>

                    <InputContainer>
                        <Label>Item Expiry Date</Label>
                        <Input
                            type="date"
                            v-model="editReceiveDetailsForm.expiry_date"
                        />
                        <FormError>{{
                            editReceiveDetailsForm.errors.expiry_date
                        }}</FormError>
                    </InputContainer>
                </div>

                <DialogFooter>
                    <Button
                        :disabled="isLoading"
                        type="submit"
                        class="gap-2"
                        @click="submitReceivingForm"
                    >
                        Update
                        <span v-if="isLoading"><Loading /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
