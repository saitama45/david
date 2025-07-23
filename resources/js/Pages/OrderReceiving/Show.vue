<script setup>
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";
import { X, Eye } from "lucide-vue-next"; // Import Eye icon

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
// Ensure orderedItems has at least one item before accessing index 1
itemDetails.value = props.orderedItems.length > 0 ? props.orderedItems[0] : null; // Changed to index 0 for safety
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
                detail: "Error",
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
                        <TH>Remarks</TH>
                        <TH>Created at</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="receipt in order.delivery_receipts" :key="receipt.id">
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
                                        receipt.remarks
                                    )
                                "
                            />
                            <DeleteButton
                                @click="deleteDeliveryReceiptNumber(receipt.id)"
                            />
                        </MobileTableHeading>
                        <LabelXS
                            >Remarks: {{ receipt.remarks ?? "N/a" }}</LabelXS
                        >
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
                        <TH>Id</TH>
                        <TH>Remarks By</TH>
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
                            <TD>{{ remarks.created_at }}</TD>
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
                        <TH>UOM / Packaging</TH>
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
                            <TD class="text-xs"
                                >{{
                                    orderItem.supplier_item.uom
                                }}
                                / {{ orderItem.uom }}</TD
                            >
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
                            <TD>{{ history.received_date }}</TD>
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
                        <p>{{ itemDetails.supplier_item.item_name }}</p>
                    </div>
                    <div>
                        <span class="text-xs">Inventory Code</span>
                        <p>
                            {{ itemDetails.supplier_item.ItemCode }}
                        </p>
                    </div>
                    <div>
                        <span class="text-xs">UOM</span>
                        <p>
                            {{
                                itemDetails.supplier_item.uom
                            }}
                        </p>
                    </div>
                    <div>
                        <span class="text-xs">Cost</span>
                        <p>
                            {{ parseFloat(itemDetails.cost_per_quantity).toFixed(2) }}
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
                        <span class="text-xs">Quantity Committed</span>
                        <p>
                            {{ itemDetails.quantity_commited }}
                        </p>
                    </div>
                    <div>
                        <span class="text-xs">Quantity Received</span>
                        <p>
                            {{ itemDetails.quantity_received }}
                        </p>
                    </div>
                    <div>
                        <span class="text-xs">Total Cost</span>
                        <p>
                            {{ parseFloat(itemDetails.total_cost).toFixed(2) }}
                        </p>
                    </div>
                    <div>
                        <span class="text-xs">Remarks</span>
                        <p>
                            {{ itemDetails.remarks ?? 'N/a' }}
                        </p>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="showReceiveForm">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Receive Order Item</DialogTitle>
                    <DialogDescription>
                        Please input all the required fields.
                    </DialogDescription>
                </DialogHeader>
                <InputContainer>
                    <LabelXS>Quantity Received</LabelXS>
                    <Input type="number" v-model="form.quantity_received" />
                    <FormError>{{ form.errors.quantity_received }}</FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Received Date</LabelXS>
                    <Input type="datetime-local" v-model="form.received_date" />
                    <FormError>{{ form.errors.received_date }}</FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Expiry Date</LabelXS>
                    <Input type="date" v-model="form.expiry_date" />
                    <FormError>{{ form.errors.expiry_date }}</FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Remarks</LabelXS>
                    <Textarea v-model="form.remarks" />
                    <FormError>{{ form.errors.remarks }}</FormError>
                </InputContainer>

                <DialogFooter>
                    <Button
                        @click="submitReceivingForm"
                        :disabled="isLoading"
                        type="submit"
                        class="gap-2"
                    >
                        Submit
                        <span v-if="isLoading"><Loading /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isEditModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Edit Receive Details</DialogTitle>
                    <DialogDescription>
                        Please input all the required fields.
                    </DialogDescription>
                </DialogHeader>
                <InputContainer>
                    <LabelXS>Quantity Received</LabelXS>
                    <Input type="number" v-model="editReceiveDetailsForm.quantity_received" />
                    <FormError>{{ editReceiveDetailsForm.errors.quantity_received }}</FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Expiry Date</LabelXS>
                    <Input type="date" v-model="editReceiveDetailsForm.expiry_date" />
                    <FormError>{{ editReceiveDetailsForm.errors.expiry_date }}</FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>Remarks</LabelXS>
                    <Textarea v-model="editReceiveDetailsForm.remarks" />
                    <FormError>{{ editReceiveDetailsForm.errors.remarks }}</FormError>
                </InputContainer>

                <DialogFooter>
                    <Button
                        @click="updateReceiveDetails"
                        :disabled="isLoading"
                        type="submit"
                        class="gap-2"
                    >
                        Update
                        <span v-if="isLoading"><Loading /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isViewModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>View Receive History Details</DialogTitle>
                    <DialogDescription>
                        Receive History Information
                    </DialogDescription>
                </DialogHeader>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <span class="text-xs">Item Name</span>
                        <p>{{ selectedItem.store_order_item.supplier_item.item_name }}</p>
                    </div>
                    <div>
                        <span class="text-xs">Item Code</span>
                        <p>{{ selectedItem.store_order_item.supplier_item.ItemCode }}</p>
                    </div>
                    <div>
                        <span class="text-xs">Quantity Received</span>
                        <p>{{ selectedItem.quantity_received }}</p>
                    </div>
                    <div>
                        <span class="text-xs">Received By</span>
                        <p>{{ selectedItem.receiver?.first_name }} {{ selectedItem.receiver?.last_name }}</p>
                    </div>
                    <div>
                        <span class="text-xs">Received Date</span>
                        <p>{{ selectedItem.received_date }}</p>
                    </div>
                    <div>
                        <span class="text-xs">Expiry Date</span>
                        <p>{{ selectedItem.expiry_date ?? 'N/a' }}</p>
                    </div>
                    <div>
                        <span class="text-xs">Remarks</span>
                        <p>{{ selectedItem.remarks ?? 'N/a' }}</p>
                    </div>
                    <div>
                        <span class="text-xs">Status</span>
                        <p>{{ selectedItem.status }}</p>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isEnlargedImageVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Enlarged Image</DialogTitle>
                    <DialogDescription>
                        Full view of the attached image.
                    </DialogDescription>
                </DialogHeader>
                <img :src="selectedImage?.image_url" alt="Enlarged Image" class="w-full h-auto object-contain" />
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isImageModalVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Attach Image</DialogTitle>
                    <DialogDescription>
                        Capture or upload an image for this order.
                    </DialogDescription>
                </DialogHeader>
                <Camera :orderId="order.id" />
            </DialogContent>
        </Dialog>
    </Layout>
</template>
