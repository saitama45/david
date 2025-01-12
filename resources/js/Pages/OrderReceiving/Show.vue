<script setup>
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";
import { X } from "lucide-vue-next";

import { useConfirm } from "primevue/useconfirm";
import Camera from "@/Pages/Camera.vue";
import dayjs from "dayjs";
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
    id: null,
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
                detail: "Your receive request has been successfully submitted. Please wait for approval.",
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
    if (deliveryReceiptForm.id) {
        updateDeliveryReceiptNumber();
    } else {
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
    }
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
    id: null,
    quantity_received: null,
    expiry_date: null,
});

const openEditModalForm = (id) => {
    const data = props.receiveDatesHistory;
    const existingItemIndex = data.findIndex((history) => history.id === id);
    const history = data[existingItemIndex];

    editReceiveDetailsForm.id = history.id;
    editReceiveDetailsForm.quantity_received = history.quantity_received;
    editReceiveDetailsForm.expiry_date = history.expiry_date;
    isEditModalVisible.value = true;
};

const updateReceiveDetails = () => {
    isLoading.value = true;
    editReceiveDetailsForm.post(
        route("orders-receiving.update-receiving-history"),
        {
            onSuccess: (page) => {
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: "Updated Successfully.",
                    life: 5000,
                });
                isLoading.value = false;
                isEditModalVisible.value = false;
            },
            onError: (errors) => {
                isLoading.value = false;
            },
        }
    );
};

const isViewModalVisible = ref(false);
const selectedItem = ref();
const openViewModalForm = (id) => {
    const data = props.receiveDatesHistory;
    const existingItemIndex = data.findIndex((history) => history.id === id);
    const history = data[existingItemIndex];
    selectedItem.value = history;
    isViewModalVisible.value = true;
};

const selectedImage = ref(null);
const isEnlargedImageVisible = ref(false);

const enlargeImage = (image) => {
    selectedImage.value = image;
    isEnlargedImageVisible.value = true;
};

const deleteImageForm = useForm({
    id: null,
});

const deleteImage = () => {
    confirm.require({
        message: "Are you sure you want to delete this image?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Remove",
            severity: "danger",
        },
        accept: () => {
            deleteImageForm.post(route("destroy"), {
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Image deletd successfully.",
                        life: 5000,
                    });
                    isLoading.value = false;
                },
                onError: (err) => {
                    isLoading.value = false;
                    console.log(err);
                },
            });
        },
    });
};

const editDeliveryReceiptNumber = (id, number, remakrs) => {
    deliveryReceiptForm.id = id;
    deliveryReceiptForm.delivery_receipt_number = number;
    deliveryReceiptForm.remarks = remakrs;
    showDeliveryReceiptForm.value = true;
};

const updateDeliveryReceiptNumber = () => {
    deliveryReceiptForm.put(
        route(
            "orders-receiving.update-delivery-receipt-number",
            deliveryReceiptForm.id
        ),
        {
            onSuccess: () => {
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: "Delivery Receipt Updated Successfully.",
                    life: 5000,
                });
                showDeliveryReceiptForm.value = false;
                isLoading.value = false;
                deliveryReceiptForm.reset();
            },
            onError: (e) => {
                console.log(e);
                isLoading.value = false;
            },
        }
    );
};

const deleteDeliveryReceiptNumber = (id) => {
    confirm.require({
        message: "Are you sure you want to delete this delivery receipt?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Remove",
            severity: "danger",
        },
        accept: () => {
            router.delete(
                route("orders-receiving.delete-delivery-receipt-number", id),
                {},
                {
                    onSuccess: () => {
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: "Delivery Receipt Deleted Successfully.",
                            life: 5000,
                        });
                    },
                    onError: (err) => {
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
                        >{{ order.approver?.first_name }}
                        {{ order.approver?.last_name }}</SpanBold
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
            </Card>

            <TableContainer>
                <TableHeader>
                    <SpanBold>Delivery Receipts</SpanBold>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH>Id</TH>
                        <TH>Number</TH>
                        <TH>Remarks</TH>
                        <TH>Created at</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="receipt in order.delivery_receipts">
                            <TD>{{ receipt.id }}</TD>
                            <TD>{{ receipt.delivery_receipt_number }}</TD>
                            <TD>{{ receipt.remarks }}</TD>
                            <TD>{{
                                dayjs(receipt.created_at).format("MMMM D, YYYY")
                            }}</TD>
                            <TD>
                                <DivFlexCenter class="gap-3">
                                    <EditButton
                                        @click="
                                            editDeliveryReceiptNumber(
                                                receipt.id,
                                                receipt.delivery_receipt_number,
                                                receipt.remarks
                                            )
                                        "
                                    />
                                    <DeleteButton
                                        @click="
                                            deleteDeliveryReceiptNumber(
                                                receipt.id
                                            )
                                        "
                                    />
                                </DivFlexCenter>
                            </TD>
                        </tr>
                    </TableBody>
                </Table>
            </TableContainer>

            <TableContainer>
                <TableHeader>
                    <SpanBold>Remarks</SpanBold>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH>Id</TH>
                        <TH>Remarks By</TH>
                        <TH>Action</TH>
                        <TH>Remarks</TH>
                        <TH>Created At</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="remarks in order.store_order_remarks">
                            <TD>{{ remarks.id }}</TD>
                            <TD
                                >{{ remarks.user.first_name }}
                                {{ remarks.user.last_name }}</TD
                            >
                            <TD>
                                {{ remarks.action.toUpperCase() }}
                            </TD>
                            <TD>{{ remarks.remarks }}</TD>
                            <TD>{{ remarks.created_at }}</TD>
                        </tr>
                    </TableBody>
                </Table>
            </TableContainer>

            <Card class="p-5">
                <InputContainer class="col-span-4">
                    <LabelXS>Image Attachments: </LabelXS>
                    <DivFlexCenter class="gap-4">
                        <div
                            v-for="image in images"
                            :key="image.id"
                            class="relative"
                        >
                            <button
                                @click="
                                    deleteImageForm.id = image.id;
                                    deleteImage();
                                "
                                class="absolute -right-2 -top-2 text-white size-5 rounded-full bg-red-500"
                            >
                                <X class="size-5" />
                            </button>
                            <img
                                :src="image.image_url"
                                class="size-24 cursor-pointer hover:opacity-80 transition-opacity"
                                @click="enlargeImage(image)"
                            />
                        </div>
                    </DivFlexCenter>
                    <SpanBold v-if="images.length < 1">None</SpanBold>
                </InputContainer>
            </Card>

            <TableContainer class="col-span-2 min-w-fit">
                <DivFlexCenter class="justify-between">
                    <SpanBold>Ordered Items</SpanBold>
                    <DivFlexCenter class="gap-3">
                        <Button @click="openImageModal">Attach Image</Button>
                        <Button @click="showDeliveryReceiptForm = true"
                            >Add Delivery Number</Button
                        >
                    </DivFlexCenter>
                </DivFlexCenter>
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
                        <TH> Status</TH>
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
                            <TD>{{ history.status }}</TD>
                            <TD>
                                <DivFlexCenter class="gap-3">
                                    <ShowButton
                                        @click="openViewModalForm(history.id)"
                                    />
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
        <!-- Dleivery receipt form -->
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
                        >Submit <span v-if="isLoading"><Loading /></span
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
                        <span class="text-xs">Quantity Approved</span>
                        <p>
                            {{ itemDetails.quantity_approved }}
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

        <!-- Image Viewer -->
        <Dialog v-model:open="isEnlargedImageVisible">
            <DialogContent
                class="sm:max-w-[90vw] h-[90vh] p-0 flex items-center justify-center"
            >
                <button
                    @click="isEnlargedImageVisible = false"
                    class="absolute right-4 top-4 rounded-sm ring-offset-background transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 bg-white/80 p-2"
                ></button>
                <img
                    v-if="selectedImage"
                    :src="selectedImage.image_url"
                    class="max-h-full max-w-full object-contain"
                    alt="Enlarged image"
                />
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

        <!-- View Modal -->
        <Dialog v-model:open="isViewModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Received Item Details</DialogTitle>
                </DialogHeader>
                <section class="grid grid-cols-2 gap-5">
                    <InputContainer>
                        <LabelXS>Item</LabelXS>
                        <SpanBold
                            >{{
                                selectedItem.store_order_item.product_inventory
                                    .name
                            }}
                            ({{
                                selectedItem.store_order_item.product_inventory
                                    .inventory_code
                            }})</SpanBold
                        >
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Received By</LabelXS>
                        <SpanBold
                            >{{ selectedItem.receiver.first_name }}
                            {{ selectedItem.receiver.last_name }}</SpanBold
                        >
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Quantity Received</LabelXS>
                        <SpanBold>{{
                            selectedItem.quantity_received
                        }}</SpanBold>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Received At</LabelXS>
                        <SpanBold>{{ selectedItem.received_date }}</SpanBold>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Expiry Date</LabelXS>
                        <SpanBold>{{ selectedItem.expiry_date }}</SpanBold>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Is approved?</LabelXS>
                        <SpanBold>{{
                            selectedItem.is_approved ? "Yes" : "No"
                        }}</SpanBold>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Remarks</LabelXS>
                        <SpanBold>{{ selectedItem.remarks ?? "N/a" }}</SpanBold>
                    </InputContainer>
                </section>
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
                        @click="updateReceiveDetails"
                    >
                        Update
                        <span v-if="isLoading"><Loading /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
