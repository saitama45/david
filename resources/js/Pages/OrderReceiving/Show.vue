<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";
import { X, Eye } from "lucide-vue-next";
import { useConfirm } from "primevue/useconfirm";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc"; // Import UTC plugin
import timezone from "dayjs/plugin/timezone"; // Import Timezone plugin
import { useBackButton } from "@/Composables/useBackButton";

// Extend dayjs with the plugins
dayjs.extend(utc);
dayjs.extend(timezone);

// Set the default timezone for dayjs.tz() operations to Asia/Manila
dayjs.tz.setDefault("Asia/Manila");

const toast = useToast();
const confirm = useConfirm();

const { backButton } = useBackButton(route("orders-receiving.index"));

// Define remarks options for the dropdown
const remarksOptions = [
    { label: 'Damaged goods', value: 'Damaged goods' },
    { label: 'Under Issuance', value: 'Under Issuance' },
    { label: 'Expired goods', value: 'Expired goods' },
    { label: 'Others', value: 'Others' }
];

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
        type: Array,
        required: true,
    },
    images: {
        type: Object,
        required: true,
    },
});

const orderStatus = ref(props.order.order_status);

const isImageUploadModalVisible = ref(false);
const openImageUploadModal = () => {
    isImageUploadModalVisible.value = true;
};

const targetId = ref(null);
const itemDetails = ref(null);
const form = useForm({
    quantity_received: null,
    received_date: new Date().toISOString().slice(0, 16),
    expiry_date: null,
    remarks: null,
});

// New form for handling image uploads.
const imageUploadForm = useForm({
    image: null,
});

// New ref and function to handle image preview before uploading.
const imagePreviewUrl = ref(null);
const onFileChange = (event) => {
    const file = event.target.files[0];
    if (file) {
        imageUploadForm.image = file;
        imagePreviewUrl.value = URL.createObjectURL(file);
    }
};

// New function to submit the uploaded image.
const submitImageUpload = () => {
    imageUploadForm.post(route('orders-receiving.attach-image', props.order.id), {
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: 'Success',
                detail: 'Image attached successfully.',
                life: 3000,
            });
            isImageUploadModalVisible.value = false;
            imageUploadForm.reset();
            imagePreviewUrl.value = null;
        },
        onError: (errors) => {
            // Display the first validation error message
            const firstError = Object.values(errors)[0];
            toast.add({
                severity: 'error',
                summary: 'Upload Error',
                detail: firstError || 'Failed to attach image.',
                life: 5000,
            });
        },
    });
};


const deliveryReceiptForm = useForm({
    id: null,
    store_order_id: props.order.id,
    delivery_receipt_number: null,
    sap_so_number: null,
    remarks: null,
});

const showItemDetails = ref(false);
itemDetails.value = props.orderedItems.length > 0 ? props.orderedItems[0] : null;
const opentItemDetails = (id) => {
    const index = props.orderedItems.findIndex((order) => order.id === id);
    itemDetails.value = props.orderedItems[index];
    showItemDetails.value = true;
};

const showReceiveForm = ref(false);
const isDeliveryReceiptModalVisible = ref(false);

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
                    isDeliveryReceiptModalVisible.value = false;
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
                    isDeliveryReceiptModalVisible.value = false;
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
const currentEditingItem = ref(null);

const closeEditModal = () => {
    isEditModalVisible.value = false;
};

const closeAllModals = () => {
    isEditModalVisible.value = false;
    isImageUploadModalVisible.value = false;
    isDeliveryReceiptModalVisible.value = false;
    isViewModalVisible.value = false;
};

const handleEscapeKey = (event) => {
    if (event.key === "Escape") {
        if (isEditModalVisible.value || isImageUploadModalVisible.value || isDeliveryReceiptModalVisible.value || isViewModalVisible.value) {
            closeAllModals();
        }
    }
};

const handleBackdropClick = (event) => {
    if (event.target === event.currentTarget) {
        closeAllModals();
    }
};

onMounted(() => {
    document.addEventListener("keydown", handleEscapeKey);
});

onUnmounted(() => {
    document.removeEventListener("keydown", handleEscapeKey);
});

watch(isEditModalVisible, (value) => {
    if (!value) {
        editReceiveDetailsForm.reset();
        editReceiveDetailsForm.clearErrors();
        isLoading.value = false;
        currentEditingItem.value = null;
        isTypingCustomRemark.value = false;
    }
});

const isTypingCustomRemark = ref(false);

const onRemarksSelectChange = (event) => {
    if (event.target.value === 'Others') {
        isTypingCustomRemark.value = true;
        editReceiveDetailsForm.remarks = '';
    }
};

const goBackToPresetRemarks = () => {
    isTypingCustomRemark.value = false;
    editReceiveDetailsForm.remarks = '';
};

const editReceiveDetailsForm = useForm({
    id: null,
    quantity_received: null,
    remarks: null,
});

// Computed property for variance calculation
const variance = computed(() => {
    if (!currentEditingItem.value || !editReceiveDetailsForm.quantity_received) {
        return 0;
    }
    const committed = currentEditingItem.value.store_order_item.quantity_commited || 0;
    const received = parseFloat(editReceiveDetailsForm.quantity_received) || 0;
    return received - committed;
});

const canConfirmReceive = computed(() => {
    return props.order.delivery_receipts.length > 0 && props.images.length > 0;
});

const openEditModalForm = (id) => {
    const data = props.receiveDatesHistory;
    const existingItemIndex = data.findIndex((history) => history.id === id);
    const history = data[existingItemIndex];

    currentEditingItem.value = history;
    editReceiveDetailsForm.id = history.id;
    editReceiveDetailsForm.quantity_received = history.quantity_received;
    editReceiveDetailsForm.remarks = history.remarks;

    const predefinedRemarks = remarksOptions.map(option => option.value);
    if (history.remarks && !predefinedRemarks.includes(history.remarks)) {
        isTypingCustomRemark.value = true;
    } else if (history.remarks === 'Others') {
        isTypingCustomRemark.value = true;
        editReceiveDetailsForm.remarks = '';
    } else {
        isTypingCustomRemark.value = false;
    }

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
                    detail:
                        errors.message || "Failed to update receive details.",
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
            deleteImageForm.post(route("destroy"), { // This route is not defined in web.php provided. Assuming it's a generic delete route for images.
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

const editDeliveryReceiptNumber = (id, number, sapSoNumber, remarks) => {
    deliveryReceiptForm.id = id;
    deliveryReceiptForm.delivery_receipt_number = number;
    deliveryReceiptForm.sap_so_number = sapSoNumber;
    deliveryReceiptForm.remarks = remarks;
    isDeliveryReceiptModalVisible.value = true;
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
                detail:
                    "Error confirming receive: " +
                    (err.message || "An unknown error occurred."),
                life: 5000,
            });
        },
    });
};

const promptConfirmReceive = () => {
    if (!canConfirmReceive.value) {
        toast.add({
            severity: "error",
            summary: "Unable to Confirm",
            detail: "A delivery receipt and image are required before confirming.",
            life: 5000,
        });
        return;
    }

    confirm.require({
        message: 'Are you sure you want to confirm all pending received items? This action cannot be undone.',
        header: 'Confirm Receiving',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Cancel',
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: {
            label: 'Confirm',
            severity: 'success',
        },
        accept: () => {
            confirmReceive();
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
                    <CardTitle>Delivery Receipts <span class="text-red-500">*</span></CardTitle>
                    <Button @click="isDeliveryReceiptModalVisible = true"
                        >Add Delivery Number</Button
                    >
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH>Id</TH>
                        <TH>Number</TH>
                        <TH>
                            <span v-if="order.variant.toLowerCase() === 'mass dts'">PO Number</span>
                            <span v-else>SAP SO Number</span>
                        </TH>
                        <TH>Remarks</TH>
                        <TH>Created at</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr
                            v-for="receipt in order.delivery_receipts"
                            :key="receipt.id"
                        >
                            <TD>{{ receipt.id }}</TD>
                            <TD>{{ receipt.delivery_receipt_number }}</TD>
                            <TD>{{ receipt.sap_so_number }}</TD>
                            <TD>{{ receipt.remarks }}</TD>
                            <TD>{{
                                dayjs
                                    .utc(receipt.created_at)
                                    .tz("Asia/Manila")
                                    .format("MMMM D, YYYY h:mm A")
                            }}</TD>
                            <TD>
                                <DivFlexCenter class="gap-3">
                                    <EditButton
                                        @click="
                                            editDeliveryReceiptNumber(
                                                receipt.id,
                                                receipt.delivery_receipt_number,
                                                receipt.sap_so_number,
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
                                        receipt.sap_so_number,
                                        receipt.remarks
                                    )
                                "
                            />
                            <DeleteButton
                                @click="deleteDeliveryReceiptNumber(receipt.id)"
                            />
                        </MobileTableHeading>
                        <LabelXS>
                            <span v-if="order.variant.toLowerCase() === 'mass dts'">PO Number:</span>
                            <span v-else>SAP SO Number:</span>
                            {{ receipt.sap_so_number ?? "N/a" }}
                        </LabelXS>
                        <LabelXS
                            >Remarks: {{ receipt.remarks ?? "N/a" }}</LabelXS
                        >
                        <LabelXS
                            >Created at:
                            {{
                                dayjs
                                    .utc(receipt.created_at)
                                    .tz("Asia/Manila")
                                    .format("MMMM D, YYYY h:mm A")
                            }}</LabelXS
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
                        <TH> Id </TH>
                        <TH> Remarks By</TH>
                        <TH>Action</TH>
                        <TH>Remarks</TH>
                        <TH>Created At</TH>
                    </TableHead>
                    <TableBody>
                        <tr
                            v-for="remarks in order.store_order_remarks"
                            :key="remarks.id"
                        >
                            <TD>{{ remarks.id }}</TD>
                            <TD
                                >{{ remarks.user.first_name }}
                                {{ remarks.user.last_name }}</TD
                            >
                            <TD>
                                {{ remarks.action.toUpperCase() }}
                            </TD>
                            <TD>{{ remarks.remarks }}</TD>
                            <TD>{{
                                dayjs(remarks.created_at)
                                    .tz("Asia/Manila")
                                    .format("MMMM D, YYYY h:mm A")
                            }}</TD>
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
                        <LabelXS
                            >Created at:
                            {{
                                dayjs(remarks.created_at)
                                    .tz("Asia/Manila")
                                    .format("MMMM D, YYYY h:mm A")
                            }}</LabelXS
                        >
                    </MobileTableRow>
                    <SpanBold v-if="order.store_order_remarks.length < 1"
                        >None</SpanBold
                    >
                </MobileTableContainer>
            </TableContainer>

            <Card>
                <TableHeader>
                    <CardTitle>Image Attachments <span class="text-red-500">*</span></CardTitle>
                    <Button @click="openImageUploadModal">Attach Image</Button>
                </TableHeader>
                <div class="p-5">
                    <DivFlexCenter
                        v-if="images.length > 0"
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
                            <!-- Image URL is bound correctly here -->
                            <a :href="image.image_url" target="_blank" rel="noopener noreferrer">
                                <img
                                    :src="image.image_url"
                                    class="size-24 cursor-pointer hover:opacity-80 transition-opacity"
                                />
                            </a>
                        </div>
                    </DivFlexCenter>
                    <SpanBold v-else>None</SpanBold>
                </div>
            </Card>

            <TableContainer class="col-span-2 min-w-fit">
                <TableHeader>
                    <CardTitle>Ordered Items</CardTitle>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH> Item Code </TH>
                        <TH> Name </TH>
                        <TH>BaseUOM</TH>
                        <TH>UOM</TH>
                        <TH> Ordered </TH>
                        <TH>Approved</TH>
                        <TH> Commited</TH>
                        <TH> Received</TH>
                    </TableHead>

                    <TableBody>
                        <tr
                            v-for="orderItem in orderedItems"
                            :key="orderItem.id"
                        >
                            <TD>{{ orderItem.supplier_item.ItemCode }}</TD>
                            <TD>{{ orderItem.supplier_item.item_name }}</TD>
                            <TD>{{
                                orderItem.supplier_item.sap_master_file
                                    ?.BaseUOM
                            }}</TD>
                            <TD class="text-xs">{{
                                orderItem.uom
                            }}</TD>
                            <TD>{{ orderItem.quantity_ordered }}</TD>
                            <TD>{{ orderItem.quantity_approved }}</TD>
                            <TD>{{ orderItem.quantity_commited }}</TD>
                            <TD>{{ orderItem.quantity_received }}</TD>
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
                        </MobileTableHeading>
                        <LabelXS
                            >BaseUOM:
                            {{
                                orderItem.supplier_item.sap_master_file
                                    ?.BaseUOM
                            }}</LabelXS
                        >
                        <LabelXS>UOM: {{ orderItem.uom }}</LabelXS>
                        <LabelXS
                            >Quantity Received:
                            {{ orderItem.quantity_received }}</LabelXS
                        >
                    </MobileTableRow>
                </MobileTableContainer>
            </TableContainer>

            <TableContainer>
                <TableHeader>
                    <CardTitle>Receiving History <span class="text-red-500">*</span></CardTitle>
                    <Button
                        v-if="order.order_status != 'received'"
                        @click="promptConfirmReceive"
                        :disabled="!canConfirmReceive"
                        :title="!canConfirmReceive ? 'A delivery receipt and image are required before confirming.' : 'Confirm all pending received items'"
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
                        <TH> Remarks </TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr
                            v-for="history in receiveDatesHistory"
                            :key="history.id"
                        >
                            <TD>{{ history.id }}</TD>
                            <TD>{{
                                history.store_order_item.supplier_item
                                    .item_name
                            }}</TD>
                            <TD>{{
                                history.store_order_item.supplier_item
                                    .ItemCode
                            }}</TD>
                            <TD>{{ history.quantity_received }}</TD>
                            <TD>{{
                                dayjs(history.received_date)
                                    .tz("Asia/Manila")
                                    .format("MMMM D, YYYY h:mm A")
                            }}</TD>
                            <TD>{{ history.status }}</TD>
                            <TD class="max-w-[200px] truncate">{{ history.remarks }}</TD>
                            <TD>
                                <DivFlexCenter class="gap-3">
                                    <ShowButton
                                        @click="openViewModalForm(history.id)"
                                    />
                                    <EditButton
                                        v-if="history.status === 'pending'"
                                        @click="openEditModalForm(history.id)"
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
                        </MobileTableHeading>
                        <LabelXS
                            >Received: {{ history.quantity_received }}</LabelXS
                        >
                        <LabelXS
                            >Status: {{ history.status.toUpperCase() }}</LabelXS
                        >
                        <LabelXS v-if="history.remarks"
                            >Remarks: {{ history.remarks }}</LabelXS
                        >
                    </MobileTableRow>
                    <SpanBold v-if="receiveDatesHistory.length < 1"
                        >None</SpanBold
                    >
                </MobileTableContainer>
            </TableContainer>
        </DivFlexCol>
        <div
            v-if="isDeliveryReceiptModalVisible"
            class="fixed inset-0 z-50 flex items-center justify-center"
            @click="handleBackdropClick"
        >
            <!-- Backdrop -->
            <div
                class="absolute inset-0 bg-black/50 backdrop-blur-sm"
            ></div>

            <!-- Modal Content -->
            <div
                class="relative z-10 w-full sm:max-w-[600px] mx-4 bg-white rounded-lg shadow-xl border border-gray-200 p-6 transform transition-all"
            >
                <!-- Header -->
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">
                            Delivery Receipt Form
                        </h2>
                        <p class="text-sm text-gray-600 mt-1">
                            Input all the important details
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="isDeliveryReceiptModalVisible = false"
                        class="h-8 w-8 p-0 hover:bg-gray-100"
                    >
                        <svg
                            class="h-4 w-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            ></path>
                        </svg>
                    </Button>
                </div>

                <!-- Form Content -->
                <div class="space-y-3">
                    <InputContainer>
                        <Label class="text-xs">Delivery Receipt Number <span class="text-red-500">*</span></Label>
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
                        <Label class="text-xs">
                            <span v-if="order.variant.toLowerCase() === 'mass dts'">PO Number</span>
                            <span v-else>SAP SO Number</span>
                            <span class="text-red-500">*</span>
                        </Label>
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
                <div class="flex justify-end items-center gap-3 mt-6">
                    <Button
                        variant="ghost"
                        @click="isDeliveryReceiptModalVisible = false"
                        >Cancel</Button
                    >
                    <Button @click="submitDeliveryReceiptForm"
                        >Submit</Button
                    >
                </div>
            </div>
        </div>

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
                        <Label class="text-xs">Quantity Received <span class="text-red-500">*</span></Label>
                        <Input
                            v-model="form.quantity_received"
                            type="number"
                        />
                        <FormError>{{
                            form.errors.quantity_received
                        }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs">Received Date <span class="text-red-500">*</span></Label>
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

        <div
            v-if="isEditModalVisible"
            class="fixed inset-0 z-50 flex items-center justify-center"
            @click="handleBackdropClick"
        >
            <!-- Backdrop -->
            <div
                class="absolute inset-0 bg-black/50 backdrop-blur-sm"
            ></div>

            <!-- Modal Content -->
            <div
                class="relative z-10 w-full sm:max-w-[600px] mx-4 bg-white rounded-lg shadow-xl border border-gray-200 p-6 transform transition-all"
            >
                <!-- Header -->
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">
                            Edit Receive Details
                        </h2>
                        <p class="text-sm text-gray-600 mt-1">
                            Update the receive information below.
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="closeEditModal"
                        class="h-8 w-8 p-0 hover:bg-gray-100"
                    >
                        <svg
                            class="h-4 w-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            ></path>
                        </svg>
                    </Button>
                </div>

                <!-- Form Content -->
                <div class="space-y-3">
                    <InputContainer>
                        <Label class="text-xs">Quantity Received <span class="text-red-500">*</span></Label>
                        <Input
                            v-model="editReceiveDetailsForm.quantity_received"
                            type="number"
                        />
                        <FormError>{{
                            editReceiveDetailsForm.errors.quantity_received
                        }}</FormError>
                        <!-- Variance Display -->
                        <div
                            v-if="
                                currentEditingItem &&
                                editReceiveDetailsForm.quantity_received
                            "
                            class="mt-2 text-sm"
                        >
                            <span class="font-medium">Variance: </span>
                            <span
                                :class="
                                    variance >= 0
                                        ? 'text-green-600'
                                        : 'text-red-600'
                                "
                            >
                                {{ variance >= 0 ? "+" : ""
                                }}{{ variance }}
                            </span>
                            <span class="text-gray-500 text-xs ml-1">
                                (Committed:
                                {{
                                    currentEditingItem.store_order_item
                                        .quantity_commited
                                }})
                            </span>
                        </div>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs">Remarks <span class="text-red-500">*</span></Label>
                        <div v-if="!isTypingCustomRemark">
                            <select
                                v-model="editReceiveDetailsForm.remarks"
                                @change="onRemarksSelectChange"
                                class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm transition-colors placeholder:text-gray-500 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-gray-400 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <option value="" disabled>Select a remark</option>
                                <option
                                    v-for="option in remarksOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                        <div v-else>
                            <textarea
                                v-model="editReceiveDetailsForm.remarks"
                                placeholder="Enter remarks"
                                class="flex min-h-[60px] w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm transition-colors placeholder:text-gray-500 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-gray-400 disabled:cursor-not-allowed disabled:opacity-50"
                            ></textarea>
                            <Button variant="link" class="mt-2 text-xs" @click="goBackToPresetRemarks">Use Presets</Button>
                        </div>
                        <FormError>{{
                            editReceiveDetailsForm.errors.remarks
                        }}</FormError>
                    </InputContainer>
                </div>

                <!-- Footer -->
                <div class="flex justify-end items-center gap-3 mt-6">
                    <Button variant="ghost" @click="closeEditModal"
                        >Cancel</Button
                    >
                    <Button @click="updateReceiveDetails">Update</Button>
                </div>
            </div>
        </div>

        <div
            v-if="isViewModalVisible"
            class="fixed inset-0 z-50 flex items-center justify-center"
            @click="handleBackdropClick"
        >
            <!-- Backdrop -->
            <div
                class="absolute inset-0 bg-black/50 backdrop-blur-sm"
            ></div>

            <!-- Modal Content -->
            <div
                class="relative z-10 w-full sm:max-w-[600px] mx-4 bg-white rounded-lg shadow-xl border border-gray-200 p-6 transform transition-all"
            >
                <!-- Header -->
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">
                            Receive History Details
                        </h2>
                        <p class="text-sm text-gray-600 mt-1">
                            View the details of the received item.
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="isViewModalVisible = false"
                        class="h-8 w-8 p-0 hover:bg-gray-100"
                    >
                        <svg
                            class="h-4 w-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            ></path>
                        </svg>
                    </Button>
                </div>

                <!-- Form Content -->
                <div class="space-y-3">
                    <InputContainer>
                        <LabelXS>Item Name:</LabelXS>
                        <SpanBold>{{
                            selectedItem?.store_order_item.supplier_item
                                .item_name
                        }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Item Code:</LabelXS>
                        <SpanBold>{{
                            selectedItem?.store_order_item.supplier_item
                                .ItemCode
                        }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Quantity Received:</LabelXS>
                        <SpanBold>{{
                            selectedItem?.quantity_received
                        }}</SpanBold>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Received By:</LabelXS>
                        <SpanBold
                            >{{
                                selectedItem?.received_by_user?.first_name
                            }}
                            {{
                                selectedItem?.received_by_user?.last_name
                            }}</SpanBold
                        >
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Received Date:</LabelXS>
                        <SpanBold>{{
                            dayjs(selectedItem?.received_date)
                                .tz("Asia/Manila")
                                .format("MMMM D, YYYY h:mm A")
                        }}</SpanBold>
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
                        <SpanBold
                            >{{
                                selectedItem?.approval_action_by_user
                                    ?.first_name
                            }}
                            {{
                                selectedItem?.approval_action_by_user
                                    ?.last_name
                            }}</SpanBold
                        >
                    </InputContainer>
                </div>

                <!-- Footer -->
                <div class="flex justify-end items-center gap-3 mt-6">
                    <Button
                        variant="ghost"
                        @click="isViewModalVisible = false"
                        >Close</Button
                    >
                </div>
            </div>
        </div>

        <div
            v-if="isImageUploadModalVisible"
            class="fixed inset-0 z-50 flex items-center justify-center"
            @click="handleBackdropClick"
        >
            <!-- Backdrop -->
            <div
                class="absolute inset-0 bg-black/50 backdrop-blur-sm"
            ></div>

            <!-- Modal Content -->
            <div
                class="relative z-10 w-full sm:max-w-[600px] mx-4 bg-white rounded-lg shadow-xl border border-gray-200 p-6 transform transition-all"
            >
                <!-- Header -->
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">
                            Attach Image
                        </h2>
                        <p class="text-sm text-gray-600 mt-1">
                            Select an image file to upload for this order.
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="isImageUploadModalVisible = false"
                        class="h-8 w-8 p-0 hover:bg-gray-100"
                    >
                        <svg
                            class="h-4 w-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            ></path>
                        </svg>
                    </Button>
                </div>

                <!-- Form Content -->
                <div class="space-y-4">
                    <InputContainer>
                        <Label class="text-xs">Image File</Label>
                        <Input
                            type="file"
                            @change="onFileChange"
                            accept="image/png, image/jpeg, image/jpg"
                            class="file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                        />
                        <FormError>{{ imageUploadForm.errors.image }}</FormError>
                    </InputContainer>
                    <!-- Apply max-height and overflow to the preview container -->
                    <div v-if="imagePreviewUrl" class="mt-4 max-h-64 overflow-y-auto">
                        <Label class="text-xs">Preview</Label>
                        <img :src="imagePreviewUrl" class="mt-2 max-w-full h-auto rounded-md border object-contain" />
                    </div>
                </div>
                <div class="flex justify-end items-center gap-3 mt-6">
                    <Button variant="ghost" @click="isImageUploadModalVisible = false">Cancel</Button>
                    <Button @click="submitImageUpload" :disabled="imageUploadForm.processing">
                        <span v-if="imageUploadForm.processing">Uploading...</span>
                        <span v-else>Upload</span>
                    </Button>
                </div>
            </div>
        </div>
    </Layout>
</template>