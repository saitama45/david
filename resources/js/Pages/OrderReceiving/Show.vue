<script setup>
import { ref, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";
import { X, Eye } from "lucide-vue-next";

import { useConfirm } from "primevue/useconfirm";
import Camera from "@/Pages/Camera.vue";
import dayjs from "dayjs";
import utc from 'dayjs/plugin/utc'; // Import UTC plugin
import timezone from 'dayjs/plugin/timezone'; // Import Timezone plugin

// Extend dayjs with the plugins
dayjs.extend(utc);
dayjs.extend(timezone);

// Set the default timezone for dayjs.tz() operations to Asia/Manila
dayjs.tz.setDefault('Asia/Manila');


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

const orderStatus = ref(props.order.order_status);
console.log(orderStatus.value);

const isImageModalVisible = ref(false);
const openImageModal = () => {
    isImageModalVisible.value = true;
};

const targetId = ref(null);
const itemDetails = ref(null);
const form = useForm({
    quantity_received: null,
    // Initialize received_date to current local date and time in YYYY-MM-DDTHH:mm format
    received_date: new Date().toISOString().slice(0, 16),
    expiry_date: null,
    remarks: null,
});

const deliveryReceiptForm = useForm({
    id: null,
    store_order_id: props.order.id,
    delivery_receipt_number: null,
    sap_so_number: null, // Added new field for SAP SO Number
    remarks: null,
});

const showItemDetails = ref(false);
// Ensure orderedItems has at least one item before accessing index 0
itemDetails.value = props.orderedItems.length > 0 ? props.orderedItems[0] : null; 
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
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "Failed to submit receive request.",
                life: 5000,
            });
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
                        detail: "Delivery Receipt Added Successfully.",
                        life: 5000,
                    });
                    showDeliveryReceiptForm.value = false;
                    isLoading.value = false;
                    deliveryReceiptForm.reset();
                },
                onError: (e) => {
                    console.log(e);
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "Failed to add delivery receipt.",
                        life: 5000,
                    });
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
                            detail: errors.message || "An error occurred.",
                            life: 5000,
                        });
                    },
                }
            );
        },
    });
};

const isEditModalVisible = ref(false);

watch(isEditModalVisible, (value) => {
    if (!value) {
        editReceiveDetailsForm.reset();
        editReceiveDetailsForm.clearErrors();
        isLoading.value = false;
    }
});
const editReceiveDetailsForm = useForm({
    id: null,
    quantity_received: null,
    expiry_date: null,
    remarks: null,
});

const openEditModalForm = (id) => {
    const data = props.receiveDatesHistory;
    const existingItemIndex = data.findIndex((history) => history.id === id);
    const history = data[existingItemIndex];

    editReceiveDetailsForm.id = history.id;
    editReceiveDetailsForm.quantity_received = history.quantity_received;
    editReceiveDetailsForm.expiry_date = history.expiry_date;
    editReceiveDetailsForm.remarks = history.remarks;
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
                toast.add({
                    severity: "error",
                    summary: "Error",
                    detail: errors.message || "Failed to update receive details.",
                    life: 5000,
                });
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
                        detail: "Image deleted successfully.",
                        life: 5000,
                    });
                    isLoading.value = false;
                },
                onError: (err) => {
                    isLoading.value = false;
                    console.log(err);
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: err.message || "Failed to delete image.",
                        life: 5000,
                    });
                },
            });
        },
    });
};

const editDeliveryReceiptNumber = (id, number, sapSoNumber, remarks) => { // Added sapSoNumber parameter
    deliveryReceiptForm.id = id;
    deliveryReceiptForm.delivery_receipt_number = number;
    deliveryReceiptForm.sap_so_number = sapSoNumber; // Set sap_so_number for editing
    deliveryReceiptForm.remarks = remarks;
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
                toast.add({
                    severity: "error",
                    summary: "Error",
                    detail: "Failed to update delivery receipt.",
                    life: 5000,
                });
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
                            detail: err.message || "An error occurred.",
                            life: 5000,
                        });
                    },
                }
            );
        },
    });
};

const confirmReceive = () => {
    const form = useForm({
        store_order_id: props.order.id,
    });

    form.put(route("orders-receiving.confirm-receive", props.order.id), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Action Completed.",
                life: 5000,
            });
        },
        onError: (err) => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "Error confirming receive: " + (err.message || "An unknown error occurred."),
                life: 5000,
            });
        },
    });
};
</script>

<template>
    <Layout heading="Order Details">
        <DivFlexCol class="gap-3">
            <Card class="p-5 grid sm:grid-cols-4 gap-5">
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
                    <LabelXS>Order Status: </LabelXS>
                    <SpanBold>{{ order.order_status.toUpperCase() }}</SpanBold>
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
                    <SpanBold class="text-xs">Delivery Receipts</SpanBold>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH>Id</TH>
                        <TH>Number</TH>
                        <TH>SAP SO Number</TH> <!-- New column header -->
                        <TH>Remarks</TH>
                        <TH>Created at</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="receipt in order.delivery_receipts" :key="receipt.id">
                            <TD>{{ receipt.id }}</TD>
                            <TD>{{ receipt.delivery_receipt_number }}</TD>
                            <TD>{{ receipt.sap_so_number }}</TD> <!-- Display SAP SO Number -->
                            <TD>{{ receipt.remarks }}</TD>
                            <TD>{{ dayjs.utc(receipt.created_at).tz('Asia/Manila').format("MMMM D, YYYY h:mm A") }}</TD>
                            <TD>
                                <DivFlexCenter class="gap-3">
                                    <EditButton
                                        @click="
                                            editDeliveryReceiptNumber(
                                                receipt.id,
                                                receipt.delivery_receipt_number,
                                                receipt.sap_so_number, // Pass sap_so_number
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

                <MobileTableContainer>
                    <MobileTableRow
                        v-for="receipt in order.delivery_receipts"
                        :key="receipt.id"
                    >
                        <MobileTableHeading
                            :title="`${receipt.delivery_receipt_number}`"
                        >
                            <EditButton
                                @click="
                                    editDeliveryReceiptNumber(
                                        receipt.id,
                                        receipt.delivery_receipt_number,
                                        receipt.sap_so_number, // Pass sap_so_number
                                        receipt.remarks
                                    )
                                "
                            />
                            <DeleteButton
                                @click="deleteDeliveryReceiptNumber(receipt.id)"
                            />
                        </MobileTableHeading>
                        <LabelXS
                            >SAP SO Number: {{ receipt.sap_so_number ?? "N/a" }}</LabelXS
                        > <!-- Display SAP SO Number -->
                        <LabelXS
                            >Remarks: {{ receipt.remarks ?? "N/a" }}</LabelXS
                        >
                        <LabelXS>Created at: {{ dayjs.utc(receipt.created_at).tz('Asia/Manila').format("MMMM D, YYYY h:mm A") }}</LabelXS>
                    </MobileTableRow>
                    <SpanBold v-if="order.delivery_receipts.length < 1"
                        >None</SpanBold
                    >
                </MobileTableContainer>
            </TableContainer>

            <TableContainer>
                <TableHeader>
                    <SpanBold class="text-xs">Remarks</SpanBold>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH> Id </TH>
                        <TH> Remarks By</TH>
                        <TH>Action</TH>
                        <TH>Remarks</TH>
                        <TH>Created At</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="remarks in order.store_order_remarks" :key="remarks.id">
                            <TD>{{ remarks.id }}</TD>
                            <TD
                                >{{ remarks.user.first_name }}
                                {{ remarks.user.last_name }}</TD
                            >
                            <TD>
                                {{ remarks.action.toUpperCase() }}
                            </TD>
                            <TD>{{ remarks.remarks }}</TD>
                            <TD>{{ dayjs(remarks.created_at).tz('Asia/Manila').format("MMMM D, YYYY h:mm A") }}</TD>
                        </tr>
                    </TableBody>
                </Table>

                <MobileTableContainer>
                    <MobileTableRow
                        v-for="remarks in order.store_order_remarks"
                        :key="remarks.id"
                    >
                        <MobileTableHeading
                            :title="`${remarks.action.toUpperCase()}`"
                        >
                            <ShowButton />
                        </MobileTableHeading>
                        <LabelXS>Remarks: {{ remarks.remarks }}</LabelXS>
                        <LabelXS>Created at: {{ dayjs(remarks.created_at).tz('Asia/Manila').format("MMMM D, YYYY h:mm A") }}</LabelXS>
                    </MobileTableRow>
                    <SpanBold v-if="order.store_order_remarks.length < 1"
                        >None</SpanBold
                    >
                </MobileTableContainer>
            </TableContainer>

            <Card class="p-5">
                <InputContainer class="col-span-4">
                    <LabelXS>Image Attachments: </LabelXS>
                    <DivFlexCenter
                        class="gap-4 overflow-auto overflow-x-auto scrollbar-thin scrollbar-track-gray-100 scrollbar-thumb-gray-300 hover:scrollbar-thumb-gray-400"
                    >
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
                    <SpanBold class="text-xs">Ordered Items</SpanBold>
                    <DivFlexCenter class="gap-3">
                        <Button
                            class="text-xs px-2 sm:px-4"
                            @click="openImageModal"
                            >Attach Image</Button
                        >
                        <Button
                            class="text-xs px-2 sm:px-4"
                            @click="showDeliveryReceiptForm = true"
                            >Add Delivery Number</Button
                        >
                    </DivFlexCenter>
                </DivFlexCenter>
                <Table>
                    <TableHead>
                        <TH> Item Code </TH>
                        <TH> Name </TH>
                        <TH>BaseUOM</TH> <!-- New column header for BaseUOM -->
                        <TH>UOM</TH> <!-- Changed from UOM / Packaging -->
                        <TH> Ordered </TH>
                        <TH>Approved</TH>
                        <TH> Commited</TH>
                        <TH> Received</TH>
                        <TH> Actions </TH>
                    </TableHead>

                    <TableBody>
                        <tr v-for="orderItem in orderedItems" :key="orderItem.id">
                            <TD>{{ orderItem.supplier_item.ItemCode }}</TD>
                            <TD>{{ orderItem.supplier_item.item_name }}</TD>
                            <TD>{{ orderItem.supplier_item.sap_masterfile?.BaseUOM }}</TD> <!-- Display BaseUOM -->
                            <TD class="text-xs">{{ orderItem.supplier_item.uom }}</TD> <!-- Display UOM from StoreOrderItem (packaging UOM) -->
                            <TD>{{ orderItem.quantity_ordered }}</TD>

                            <TD>{{ orderItem.quantity_approved }}</TD>
                            <TD>{{ orderItem.quantity_commited }}</TD>

                            <TD>{{ orderItem.quantity_received }}</TD>
                            <TD class="w-[90px]">
                                <DivFlexCenter class="gap-1">
                                    <ShowButton
                                        @click="opentItemDetails(orderItem.id)"
                                    >
                                        <Eye />
                                    </ShowButton>
                                    <Button
                                        v-if="orderStatus === 'incomplete'"
                                        @click="openReceiveForm(orderItem.id)"
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

                <MobileTableContainer>
                    <MobileTableRow
                        v-for="orderItem in orderedItems"
                        :key="orderItem.id"
                    >
                        <MobileTableHeading
                            :title="`${orderItem.supplier_item.item_name} (${orderItem.supplier_item.ItemCode})`"
                        >
                            <Button
                                v-if="canReceive"
                                @click="openReceiveForm(orderItem.id)"
                                class="text-green-500"
                                variant="link"
                            >
                                Receive
                            </Button>
                        </MobileTableHeading>
                        <LabelXS>BaseUOM: {{ orderItem.supplier_item.sap_masterfile?.BaseUOM }}</LabelXS> <!-- New line for BaseUOM -->
                        <LabelXS>UOM: {{ orderItem.uom }}</LabelXS> <!-- Existing UOM line -->
                        <LabelXS
                            >Quantity Received:
                            {{ orderItem.quantity_received }}</LabelXS
                        >
                    </MobileTableRow>
                </MobileTableContainer>
            </TableContainer>

            <TableContainer>
                <TableHeader>
                    <CardTitle>Receiving History</CardTitle>
                    <Button
                        v-if="order.order_status != 'received'"
                        @click="confirmReceive"
                    >
                        Confirm Receive
                    </Button>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH> Id </TH>
                        <TH> Item </TH>
                        <TH> Item Code </TH>
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
                                history.store_order_item.supplier_item.item_name
                            }}</TD>
                            <TD>{{
                                history.store_order_item.supplier_item.ItemCode
                            }}</TD>
                            <TD>{{ history.quantity_received }}</TD>
                            <TD>{{ dayjs(history.received_date).tz('Asia/Manila').format("MMMM D, YYYY h:mm A") }}</TD>
                            <TD>{{ history.status }}</TD>
                            <TD>
                                <DivFlexCenter class="gap-3">
                                    <ShowButton
                                        @click="openViewModalForm(history.id)"
                                    />
                                    <EditButton
                                        v-if="history.status === 'pending'"
                                        @click="openEditModalForm(history.id)"
                                    />
                                    <DeleteButton
                                        v-if="history.status === 'pending'"
                                        @click="deleteReceiveDate(history.id)"
                                    />
                                </DivFlexCenter>
                            </TD>
                        </tr>
                    </TableBody>
                </Table>

                <MobileTableContainer>
                    <MobileTableRow
                        v-for="history in receiveDatesHistory"
                        :key="history.id"
                    >
                        <MobileTableHeading
                            :title="`${history.store_order_item.supplier_item.item_name} (${history.store_order_item.supplier_item.ItemCode})`"
                        >
                            <ShowButton
                                class="size-5 gap mr-0"
                                @click="openViewModalForm(history.id)"
                            />
                            <EditButton
                                class="size-5 gap mr-1"
                                v-if="history.status === 'pending'"
                                @click="openEditModalForm(history.id)"
                            />
                            <DeleteButton
                                class="size-5 gap mr-1"
                                v-if="history.status === 'pending'"
                                @click="deleteReceiveDate(history.id)"
                            />
                        </MobileTableHeading>
                        <LabelXS
                            >Received: {{ history.quantity_received }}</LabelXS
                        >
                        <LabelXS
                            >Status: {{ history.status.toUpperCase() }}</LabelXS
                        >
                    </MobileTableRow>
                    <SpanBold v-if="receiveDatesHistory.length < 1"
                        >None</SpanBold
                    >
                </MobileTableContainer>
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
                        <Label class="text-xs">SAP SO Number</Label>
                        <Input
                            v-model="
                                deliveryReceiptForm.sap_so_number
                            "
                        />
                        <FormError>{{
                            deliveryReceiptForm.errors.sap_so_number
                        }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs">Remarks</Label>
                        <Input
                            v-model="
                                deliveryReceiptForm.remarks
                            "
                        />
                        <FormError>{{
                            deliveryReceiptForm.errors.remarks
                        }}</FormError>
                    </InputContainer>
                </div>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        @click="showDeliveryReceiptForm = false"
                        >Cancel</Button
                    >
                    <Button @click="submitDeliveryReceiptForm"
                        >Submit</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="showReceiveForm">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Receive Form</DialogTitle>
                    <DialogDescription
                        >Input all the important details</DialogDescription
                    >
                </DialogHeader>
                <div class="space-y-3">
                    <InputContainer>
                        <Label class="text-xs">Quantity Received</Label>
                        <Input
                            v-model="form.quantity_received"
                            type="number"
                        />
                        <FormError>{{
                            form.errors.quantity_received
                        }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs">Received Date</Label>
                        <Input
                            v-model="form.received_date"
                            type="datetime-local"
                        />
                        <FormError>{{
                            form.errors.received_date
                        }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs">Expiry Date</Label>
                        <Input
                            v-model="form.expiry_date"
                            type="date"
                        />
                        <FormError>{{
                            form.errors.expiry_date
                        }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs">Remarks</Label>
                        <Input
                            v-model="form.remarks"
                        />
                        <FormError>{{
                            form.errors.remarks
                        }}</FormError>
                    </InputContainer>
                </div>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        @click="showReceiveForm = false"
                        >Cancel</Button
                    >
                    <Button @click="submitReceivingForm"
                        >Submit</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isEditModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Edit Receive Details</DialogTitle>
                    <DialogDescription
                        >Update the receive information below.</DialogDescription
                    >
                </DialogHeader>
                <div class="space-y-3">
                    <InputContainer>
                        <Label class="text-xs">Quantity Received</Label>
                        <Input
                            v-model="editReceiveDetailsForm.quantity_received"
                            type="number"
                        />
                        <FormError>{{
                            editReceiveDetailsForm.errors.quantity_received
                        }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs">Expiry Date</Label>
                        <Input
                            v-model="editReceiveDetailsForm.expiry_date"
                            type="date"
                        />
                        <FormError>{{
                            editReceiveDetailsForm.errors.expiry_date
                        }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs">Remarks</Label>
                        <Input
                            v-model="editReceiveDetailsForm.remarks"
                        />
                        <FormError>{{
                            editReceiveDetailsForm.errors.remarks
                        }}</FormError>
                    </InputContainer>
                </div>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        @click="isEditModalVisible = false"
                        >Cancel</Button
                    >
                    <Button @click="updateReceiveDetails"
                        >Update</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isViewModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Receive History Details</DialogTitle>
                    <DialogDescription
                        >View the details of the received item.</DialogDescription
                    >
                </DialogHeader>
                <div class="space-y-3">
                    <InputContainer>
                        <LabelXS>Item Name:</LabelXS>
                        <SpanBold>{{ selectedItem?.store_order_item.supplier_item.item_name }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Item Code:</LabelXS>
                        <SpanBold>{{ selectedItem?.store_order_item.supplier_item.ItemCode }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Quantity Received:</LabelXS>
                        <SpanBold>{{ selectedItem?.quantity_received }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Received By:</LabelXS>
                        <SpanBold>{{ selectedItem?.received_by_user?.first_name }} {{ selectedItem?.received_by_user?.last_name }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Received Date:</LabelXS>
                        <SpanBold>{{ dayjs(selectedItem?.received_date).tz('Asia/Manila').format("MMMM D, YYYY h:mm A") }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Expiry Date:</LabelXS>
                        <SpanBold>{{ selectedItem?.expiry_date }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Remarks:</LabelXS>
                        <SpanBold>{{ selectedItem?.remarks }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Status:</LabelXS>
                        <SpanBold>{{ selectedItem?.status }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Approval Action By:</LabelXS>
                        <SpanBold>{{ selectedItem?.approval_action_by_user?.first_name }} {{ selectedItem?.approval_action_by_user?.last_name }}</SpanBold>
                    </InputContainer>
                </div>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        @click="isViewModalVisible = false"
                        >Close</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isEnlargedImageVisible">
            <DialogContent class="sm:max-w-[900px]">
                <DialogHeader>
                    <DialogTitle>Enlarged Image</DialogTitle>
                </DialogHeader>
                <div class="flex justify-center items-center">
                    <img :src="selectedImage?.image_url" class="max-w-full max-h-[80vh] object-contain" />
                </div>
                <DialogFooter>
                    <Button variant="ghost" @click="isEnlargedImageVisible = false">Close</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isImageModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Attach Image</DialogTitle>
                    <DialogDescription>
                        Upload an image for this order.
                    </DialogDescription>
                </DialogHeader>
                <Camera
                    :orderId="order.id"
                    @image-uploaded="isImageModalVisible = false"
                />
                <DialogFooter>
                    <Button variant="ghost" @click="isImageModalVisible = false">Cancel</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
