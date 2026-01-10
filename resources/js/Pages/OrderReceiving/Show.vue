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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";

// Extend dayjs with the plugins
dayjs.extend(utc);
dayjs.extend(timezone);

// Set the default timezone for dayjs.tz() operations to Asia/Manila
dayjs.tz.setDefault("Asia/Manila");

const toast = useToast();
const confirm = useConfirm();

const { backButton } = useBackButton(route("orders-receiving.index"));

// Helper to format quantities for display
const formatQuantity = (value) => {
    const num = parseFloat(value);
    if (isNaN(num)) return value;
    // Fix floating point artifacts (e.g. 2.546 becoming 2.5459999999999998)
    // toFixed(10) is sufficient precision for this context to round off the artifact,
    // and parseFloat strips the trailing zeros to show the value "as is".
    return parseFloat(num.toFixed(10));
};

const getStatusClass = (status) => {
    switch (status?.toLowerCase()) {
        case "approved":
        case "received":
            return "bg-green-100 text-green-800 border-green-200";
        case "pending":
            return "bg-yellow-100 text-yellow-800 border-yellow-200";
        case "rejected":
        case "cancelled":
            return "bg-red-100 text-red-800 border-red-200";
        default:
            return "bg-gray-100 text-gray-800 border-gray-200";
    }
};

// Define remarks options for the dropdown
const remarksOptions = [
    { label: 'Damaged goods', value: 'Damaged goods' },
    { label: 'Over Issuance', value: 'Over Issuance' },
    { label: 'Under Issuance', value: 'Under Issuance' },
    { label: 'Expired goods', value: 'Expired goods' },
    { label: 'Others', value: 'Others' }
];

const remarksSummary = computed(() => {
    const summary = {
        received: 0,
        under_issuance: 0,
        over_issuance: 0,
        unserved: 0,
        damaged_goods: 0,
        expired_goods: 0,
        others: 0,
        total: 0,
    };

    props.receiveDatesHistory.forEach((history) => {
        const remark = (history.remarks || '').toLowerCase().trim();

        if (!remark) return;
        
        summary.total++;
        
        if (remark === 'received') {
            summary.received++;
        } else if (remark === 'under issuance') {
            summary.under_issuance++;
        } else if (remark === 'over issuance') {
            summary.over_issuance++;
        } else if (remark === 'unserved') {
            summary.unserved++;
        } else if (remark === 'damaged goods') {
            summary.damaged_goods++;
        } else if (remark === 'expired goods') {
            summary.expired_goods++;
        } else {
            summary.others++;
        }
    });

    return summary;
});

const receivedCount = computed(() => {
    return props.receiveDatesHistory.filter(h => h.received_date).length;
});

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

watch(() => editReceiveDetailsForm.quantity_received, (newVal) => {
    const qty = parseFloat(newVal);
    if (!isNaN(qty) && qty === 0) {
        editReceiveDetailsForm.remarks = "Unserved";
        isTypingCustomRemark.value = true;
    }
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
    if (!editReceiveDetailsForm.remarks) {
        editReceiveDetailsForm.remarks = 'Received';
    }
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
                isDeliveryReceiptModalVisible.value = false;
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
        <div class="space-y-6">
            <!-- Order Information Card -->
            <Card class="overflow-hidden bg-white shadow-sm border border-gray-200 rounded-xl">
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <!-- Order Info -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Order Info</h3>
                            <div class="space-y-3">
                                <div>
                                    <span class="text-xs text-gray-400 block">Order Number</span>
                                    <span class="font-medium text-gray-900">{{ order.order_number }}</span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-400 block">Order Date</span>
                                    <span class="font-medium text-gray-900">{{ order.order_date }}</span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-400 block">Variant</span>
                                    <span class="font-medium text-gray-900">{{ order.variant.toUpperCase() }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Status Info -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Status</h3>
                            <div class="space-y-3">
                                <div>
                                    <span class="text-xs text-gray-400 block mb-1">Current Status</span>
                                    <span :class="['px-2.5 py-0.5 rounded-full text-xs font-medium border', getStatusClass(order.order_status)]">
                                        {{ (order.order_status.toUpperCase() === 'RECEIVED' || order.order_status.toUpperCase() === 'INCOMPLETE') ? 'RECEIVED' : order.order_status.toUpperCase() }}
                                    </span>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-400 block">Approval Date</span>
                                    <span class="font-medium text-gray-900">{{ order.approval_action_date || 'N/A' }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Personnel -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Personnel</h3>
                            <div class="space-y-3">
                                <div>
                                    <span class="text-xs text-gray-400 block">Encoder</span>
                                    <div class="flex items-center gap-2">
                                        <div class="size-6 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 text-xs font-bold">
                                            {{ order.encoder.first_name[0] }}
                                        </div>
                                        <span class="font-medium text-gray-900">{{ order.encoder.first_name }} {{ order.encoder.last_name }}</span>
                                    </div>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-400 block">Approver</span>
                                    <div class="flex items-center gap-2">
                                        <div v-if="order.approver" class="size-6 rounded-full bg-purple-100 flex items-center justify-center text-purple-600 text-xs font-bold">
                                            {{ order.approver.first_name[0] }}
                                        </div>
                                        <span class="font-medium text-gray-900">
                                            {{ order.approver ? `${order.approver.first_name} ${order.approver.last_name}` : 'N/A' }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Actions/Summary -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider">Summary</h3>
                             <div class="space-y-3">
                                <!-- Items Status Overview -->
                                <div class="p-3 bg-blue-50 rounded-lg border border-blue-200">
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-xs font-medium text-blue-700">ITEMS OVERVIEW</span>
                                        <span class="text-xs text-blue-600">{{ orderedItems.length }} total</span>
                                    </div>
                                    <div class="space-y-1">
                                        <div class="flex justify-between items-center">
                                            <span class="text-xs text-blue-600">✓ Received</span>
                                            <span class="font-bold text-green-700">{{ receivedCount }}</span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-xs text-orange-600">⏳ To Receive</span>
                                            <span class="font-bold text-orange-700">{{ orderedItems.length - receivedCount }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Remarks Overview -->
                                <div class="p-3 bg-purple-50 rounded-lg border border-purple-200">
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-xs font-medium text-purple-700">REMARKS OVERVIEW</span>
                                        <span class="text-xs text-purple-600">{{ remarksSummary.total }} / {{ orderedItems.length }} updated</span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                                        <div class="flex justify-between">
                                            <span class="text-purple-600">Received:</span>
                                            <span class="font-bold text-purple-800">{{ remarksSummary.received }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-purple-600">Unserved:</span>
                                            <span class="font-bold text-purple-800">{{ remarksSummary.unserved }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-purple-600">Under Issuance:</span>
                                            <span class="font-bold text-purple-800">{{ remarksSummary.under_issuance }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-purple-600">Over Issuance:</span>
                                            <span class="font-bold text-purple-800">{{ remarksSummary.over_issuance }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-purple-600">Damaged Goods:</span>
                                            <span class="font-bold text-purple-800">{{ remarksSummary.damaged_goods }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-purple-600">Expired Goods:</span>
                                            <span class="font-bold text-purple-800">{{ remarksSummary.expired_goods }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-purple-600">Others:</span>
                                            <span class="font-bold text-purple-800">{{ remarksSummary.others }}</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Progress Bar -->
                                <div class="space-y-2">
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-500">Receiving Progress</span>
                                        <span class="font-medium text-gray-700">{{ Math.round((receivedCount / orderedItems.length) * 100) }}%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-green-500 h-2 rounded-full transition-all duration-300" :style="{ width: (receivedCount / orderedItems.length) * 100 + '%' }"></div>
                                    </div>
                                </div>
                                
                                <!-- Delivery Receipts -->
                                <div class="p-2 bg-gray-50 rounded border">
                                    <div class="flex justify-between items-center">
                                        <span class="text-xs text-gray-500">Delivery Receipts</span>
                                        <span class="font-bold text-gray-900">{{ order.delivery_receipts.length }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Card>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Delivery Receipts Table -->
                <TableContainer>
                    <TableHeader>
                        <CardTitle class="text-lg font-semibold text-gray-800">Delivery Receipts <span class="text-red-500 text-sm">*</span></CardTitle>
                        <Button size="sm" @click="isDeliveryReceiptModalVisible = true">
                            <span class="mr-1">+</span> Add Receipt
                        </Button>
                    </TableHeader>
                    <Table>
                        <TableHead>
                            <TH>Number</TH>
                            <TH>
                                <span v-if="order.variant.toLowerCase() === 'mass dts'">PO #</span>
                                <span v-else>SAP SO #</span>
                            </TH>
                            <TH>Remarks</TH>
                            <TH>Created</TH>
                            <TH class="text-right">Actions</TH>
                        </TableHead>
                        <TableBody>
                            <tr v-if="order.delivery_receipts.length === 0">
                                <td colspan="5" class="text-center py-6 text-gray-500 italic text-sm">No delivery receipts added yet.</td>
                            </tr>
                            <tr v-for="receipt in order.delivery_receipts" :key="receipt.id" class="hover:bg-gray-50 transition-colors">
                                <TD class="font-medium">{{ receipt.delivery_receipt_number }}</TD>
                                <TD class="font-mono text-xs">{{ receipt.sap_so_number }}</TD>
                                <TD class="text-gray-600 truncate max-w-[150px]">{{ receipt.remarks || '-' }}</TD>
                                <TD class="text-xs text-gray-500">
                                    {{ dayjs.utc(receipt.created_at).tz("Asia/Manila").format("MMM D, YYYY") }}
                                </TD>
                                <TD>
                                    <div class="flex justify-end gap-2">
                                        <EditButton @click="editDeliveryReceiptNumber(receipt.id, receipt.delivery_receipt_number, receipt.sap_so_number, receipt.remarks)" />
                                        <DeleteButton @click="deleteDeliveryReceiptNumber(receipt.id)" />
                                    </div>
                                </TD>
                            </tr>
                        </TableBody>
                    </Table>
                    
                     <!-- Mobile View for Delivery Receipts -->
                     <MobileTableContainer>
                        <MobileTableRow v-for="receipt in order.delivery_receipts" :key="receipt.id">
                            <MobileTableHeading :title="receipt.delivery_receipt_number">
                                <div class="flex gap-2">
                                    <EditButton @click="editDeliveryReceiptNumber(receipt.id, receipt.delivery_receipt_number, receipt.sap_so_number, receipt.remarks)" />
                                    <DeleteButton @click="deleteDeliveryReceiptNumber(receipt.id)" />
                                </div>
                            </MobileTableHeading>
                             <div class="grid grid-cols-2 gap-2 text-sm mt-2">
                                <div class="flex flex-col">
                                    <span class="text-xs text-gray-500">
                                         <span v-if="order.variant.toLowerCase() === 'mass dts'">PO #</span>
                                         <span v-else>SAP SO #</span>
                                    </span>
                                    <span class="font-medium">{{ receipt.sap_so_number ?? "N/A" }}</span>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-xs text-gray-500">Created</span>
                                    <span>{{ dayjs.utc(receipt.created_at).tz("Asia/Manila").format("MMM D, YYYY") }}</span>
                                </div>
                                <div class="col-span-2 flex flex-col">
                                    <span class="text-xs text-gray-500">Remarks</span>
                                    <span>{{ receipt.remarks ?? "N/A" }}</span>
                                </div>
                            </div>
                        </MobileTableRow>
                        <div v-if="order.delivery_receipts.length === 0" class="p-4 text-center text-gray-500 italic">None</div>
                    </MobileTableContainer>
                </TableContainer>

                <!-- Image Attachments -->
                <Card class="bg-white shadow-sm border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                        <CardTitle class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                            Image Attachments <span class="text-red-500 text-sm">*</span>
                            <span class="px-2 py-0.5 rounded-full bg-gray-200 text-gray-600 text-xs">{{ images.length }}</span>
                        </CardTitle>
                        <Button size="sm" variant="outline" @click="openImageUploadModal">
                             Upload Image
                        </Button>
                    </div>
                    <div class="p-6">
                        <div v-if="images.length > 0" class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                            <div v-for="image in images" :key="image.id" class="group relative aspect-square bg-gray-100 rounded-lg border border-gray-200 overflow-hidden">
                                 <a :href="image.image_url" target="_blank" rel="noopener noreferrer" class="block w-full h-full">
                                    <img :src="image.image_url" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105" />
                                </a>
                                <button
                                    @click="deleteImageForm.id = image.id; deleteImage();"
                                    class="absolute top-2 right-2 p-1.5 bg-red-500/90 text-white rounded-full opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-600 shadow-sm"
                                    title="Delete Image"
                                >
                                    <X class="size-3" />
                                </button>
                            </div>
                        </div>
                        <div v-else class="text-center py-8 bg-gray-50 rounded-lg border border-dashed border-gray-300">
                            <div class="flex flex-col items-center justify-center text-gray-400">
                                 <svg class="size-10 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                <span class="text-sm">No images attached</span>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>



            <!-- Receiving History Table -->
            <div class="bg-white shadow-sm border border-gray-200 rounded-xl overflow-hidden">
                <div class="p-4 border-b border-gray-200 bg-gray-50">
                    <div class="flex justify-between items-start">
                        <div class="flex flex-col gap-1">
                            <h3 class="text-lg font-semibold text-gray-800">
                                Receiving History 
                                <span class="text-sm font-normal text-gray-500">({{ receivedCount }} received of {{ orderedItems.length }} items)</span> 
                                <span class="text-red-500 text-sm">*</span>
                            </h3>
                            <p v-if="orderedItems.length - receivedCount > 0" class="text-xs text-orange-600 font-medium">
                                ⚠️ {{ orderedItems.length - receivedCount }} item(s) still to receive
                            </p>
                            <p v-else class="text-xs text-green-600 font-medium">
                                ✓ All items have been received
                            </p>
                        </div>
                        <Button
                            v-if="order.order_status != 'received'"
                            @click="promptConfirmReceive"
                            :disabled="!canConfirmReceive"
                            :variant="!canConfirmReceive ? 'secondary' : 'default'"
                            :title="!canConfirmReceive ? 'A delivery receipt and image are required before confirming.' : 'Confirm all pending received items'"
                        >
                            Confirm Receive
                        </Button>
                    </div>
                </div>
                <div class="overflow-auto max-h-[600px]">
                    <table class="w-full">
                        <thead class="sticky top-0 bg-white border-b border-gray-200 z-10">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider bg-white">#</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider bg-white">Item Code</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider bg-white">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider bg-white">UOM Details</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider bg-white">Ordered</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider bg-white">Approved</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider bg-white">Committed</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider bg-white">Received</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider bg-white">Received At</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider bg-white">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider bg-white">Remarks</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider bg-white">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-if="receiveDatesHistory.length === 0">
                                <td colspan="12" class="px-4 py-8 text-center text-gray-500 italic bg-gray-50/50">
                                    No receiving history available.
                                </td>
                            </tr>
                            <tr
                                v-for="(history, index) in receiveDatesHistory"
                                :key="history.id"
                                class="hover:bg-gray-50 transition-colors duration-150"
                            >
                                <td class="px-4 py-4 text-center font-mono text-gray-500">{{ index + 1 }}</td>
                                <td class="px-4 py-4 font-mono text-xs text-gray-600">{{
                                    history.store_order_item.supplier_item
                                        .ItemCode
                                }}</td>
                                <td class="px-4 py-4 font-medium text-gray-800">{{
                                    history.store_order_item.supplier_item
                                        .item_name
                                }}</td>
                                <td class="px-4 py-4">
                                    <div class="flex flex-col text-xs">
                                        <span class="text-gray-500">Base: <span class="text-gray-900 font-medium">{{ history.store_order_item.supplier_item.sap_master_file?.BaseUOM || '-' }}</span></span>
                                        <span class="text-gray-500">Order: <span class="text-gray-900 font-medium">{{ history.store_order_item.uom }}</span></span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-center font-mono">{{ formatQuantity(history.store_order_item.quantity_ordered) }}</td>
                                <td class="px-4 py-4 text-center font-mono">{{ formatQuantity(history.store_order_item.quantity_approved) }}</td>
                                <td class="px-4 py-4 text-center font-mono">{{ formatQuantity(history.store_order_item.quantity_commited) }}</td>
                                <td :class="['px-4 py-4 text-center font-mono font-bold', Number(history.quantity_received) !== Number(history.store_order_item.quantity_commited) ? 'text-red-600' : 'text-blue-600']">{{ history.received_date ? formatQuantity(history.quantity_received) : 0 }}</td>
                                <td class="px-4 py-4 text-sm text-gray-600">
                                    {{ dayjs(history.received_date).isValid() ? dayjs(history.received_date).tz("Asia/Manila").format("MMM D, YYYY h:mm A") : '' }}
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span :class="[
                                        'px-2.5 py-0.5 text-xs font-semibold rounded-full border',
                                        getStatusClass(history.status)
                                    ]">
                                        {{ history.status.toLowerCase() === 'approved' ? 'RECEIVED' : history.status.toLowerCase() === 'received' ? 'RECEIVED' : history.status.toLowerCase() === 'pending' ? 'PENDING' : history.status.toUpperCase() }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 max-w-[200px] truncate text-sm text-gray-600" :title="history.remarks">{{ history.remarks || '-' }}</td>
                                <td class="px-4 py-4">
                                    <div class="flex justify-end gap-2">
                                        <ShowButton
                                            @click="openViewModalForm(history.id)"
                                            title="View Details"
                                        />
                                        <EditButton
                                            v-if="history.status === 'pending' || history.status === 'received'"
                                            @click="openEditModalForm(history.id)"
                                            title="Edit Item"
                                        />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile View -->
                <div class="block md:hidden p-4 space-y-4">
                    <div v-for="history in receiveDatesHistory" :key="history.id" class="bg-white border border-gray-200 rounded-lg p-4">
                        <div class="flex justify-between items-start mb-3">
                            <h4 class="font-medium text-gray-900 text-sm">{{ history.store_order_item.supplier_item.item_name }}</h4>
                            <div class="flex gap-1">
                                <ShowButton
                                    class="size-8"
                                    @click="openViewModalForm(history.id)"
                                />
                                <EditButton
                                    class="size-8"
                                    v-if="history.status === 'pending'"
                                    @click="openEditModalForm(history.id)"
                                />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-sm">
                            <div class="flex flex-col">
                                <span class="text-xs text-gray-500">Received</span>
                                <span class="font-bold">{{ formatQuantity(history.quantity_received) }}</span>
                            </div>
                            <div class="flex flex-col items-end">
                                <span class="text-xs text-gray-500 mb-1">Status</span>
                                <span :class="['px-2 py-0.5 rounded text-xs font-bold border', getStatusClass(history.status)]">{{ history.status.toUpperCase() }}</span>
                            </div>
                            <div class="col-span-2 flex flex-col" v-if="history.remarks">
                                <span class="text-xs text-gray-500">Remarks</span>
                                <span class="italic text-gray-600">{{ history.remarks }}</span>
                            </div>
                        </div>
                    </div>
                    <div v-if="receiveDatesHistory.length < 1" class="p-4 text-center text-gray-500 italic">None</div>
                </div>
            </div>
        </div>

        <!-- Modals -->
        <div
            v-if="isDeliveryReceiptModalVisible"
            class="fixed inset-0 z-50 flex items-center justify-center"
        >
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="isDeliveryReceiptModalVisible = false"></div>
            <div class="relative z-10 w-full sm:max-w-[500px] mx-4 bg-white rounded-xl shadow-2xl border border-gray-200 p-6 transform transition-all">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Delivery Receipt</h2>
                        <p class="text-sm text-gray-500">Enter receipt details below</p>
                    </div>
                    <button @click="isDeliveryReceiptModalVisible = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <X class="size-6" />
                    </button>
                </div>

                <div class="space-y-4">
                    <InputContainer>
                        <Label class="text-xs font-medium uppercase text-gray-500">DR Number <span class="text-red-500">*</span></Label>
                        <Input v-model="deliveryReceiptForm.delivery_receipt_number" class="font-medium" />
                        <FormError>{{ deliveryReceiptForm.errors.delivery_receipt_number }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs font-medium uppercase text-gray-500">
                            <span v-if="order.variant.toLowerCase() === 'mass dts'">PO Number</span>
                            <span v-else>SAP SO Number</span>
                            <span class="text-red-500">*</span>
                        </Label>
                        <Input v-model="deliveryReceiptForm.sap_so_number" class="font-medium" />
                        <FormError>{{ deliveryReceiptForm.errors.sap_so_number }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs font-medium uppercase text-gray-500">Remarks</Label>
                        <Input v-model="deliveryReceiptForm.remarks" />
                        <FormError>{{ deliveryReceiptForm.errors.remarks }}</FormError>
                    </InputContainer>
                </div>
                <div class="flex justify-end items-center gap-3 mt-8">
                    <Button variant="ghost" @click="isDeliveryReceiptModalVisible = false">Cancel</Button>
                    <Button @click="submitDeliveryReceiptForm">Save Receipt</Button>
                </div>
            </div>
        </div>

        <Dialog v-model:open="showReceiveForm">
            <DialogContent class="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Receive Item</DialogTitle>
                    <DialogDescription>Enter the quantity and details of the item received.</DialogDescription>
                </DialogHeader>
                <div class="space-y-4 py-4">
                    <InputContainer>
                        <Label class="text-xs font-medium uppercase text-gray-500">Quantity Received <span class="text-red-500">*</span></Label>
                        <Input v-model="form.quantity_received" type="number" step="any" class="font-bold text-lg" />
                        <FormError>{{ form.errors.quantity_received }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs font-medium uppercase text-gray-500">Received Date <span class="text-red-500">*</span></Label>
                        <Input v-model="form.received_date" type="datetime-local" />
                        <FormError>{{ form.errors.received_date }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs font-medium uppercase text-gray-500">Expiry Date</Label>
                        <Input v-model="form.expiry_date" type="date" />
                        <FormError>{{ form.errors.expiry_date }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label class="text-xs font-medium uppercase text-gray-500">Remarks</Label>
                        <Input v-model="form.remarks" placeholder="Optional remarks" />
                        <FormError>{{ form.errors.remarks }}</FormError>
                    </InputContainer>
                </div>
                <DialogFooter>
                    <Button variant="ghost" @click="showReceiveForm = false">Cancel</Button>
                    <Button @click="submitReceivingForm">Submit Receive</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Edit Modal (Custom styled to match) -->
        <div
            v-if="isEditModalVisible"
            class="fixed inset-0 z-50 flex items-center justify-center"
        >
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="closeEditModal"></div>
            <div class="relative z-10 w-full sm:max-w-[500px] mx-4 bg-white rounded-xl shadow-2xl border border-gray-200 p-6 transform transition-all">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Edit Receive Details</h2>
                        <p class="text-sm text-gray-500">Modify the received quantity or remarks.</p>
                    </div>
                    <button @click="closeEditModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <X class="size-6" />
                    </button>
                </div>

                <div class="space-y-4">
                    <InputContainer>
                        <Label class="text-xs font-medium uppercase text-gray-500">Quantity Received <span class="text-red-500">*</span></Label>
                        <Input v-model="editReceiveDetailsForm.quantity_received" type="number" step="any" class="font-bold text-lg" />
                        <FormError>{{ editReceiveDetailsForm.errors.quantity_received }}</FormError>
                        
                        <div v-if="currentEditingItem && editReceiveDetailsForm.quantity_received" class="mt-2 text-sm flex items-center gap-2 p-2 bg-gray-50 rounded border border-gray-100">
                            <span class="text-gray-500">Variance:</span>
                            <span :class="variance >= 0 ? 'text-green-600 font-bold' : 'text-red-600 font-bold'">
                                {{ variance >= 0 ? "+" : "" }}{{ formatQuantity(variance) }}
                            </span>
                            <span class="text-gray-400 text-xs ml-auto">(Committed: {{ formatQuantity(currentEditingItem.store_order_item.quantity_commited) }})</span>
                        </div>
                    </InputContainer>

                    <InputContainer>
                        <Label class="text-xs font-medium uppercase text-gray-500">Remarks <span class="text-red-500">*</span></Label>
                        <div v-if="!isTypingCustomRemark">
                            <select
                                v-model="editReceiveDetailsForm.remarks"
                                @change="onRemarksSelectChange"
                                :disabled="parseFloat(editReceiveDetailsForm.quantity_received) === 0"
                                class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm transition-colors focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none disabled:bg-gray-100 disabled:cursor-not-allowed"
                            >
                                <option value="" disabled>Select a remark</option>
                                <option v-for="option in remarksOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </div>
                        <div v-else>
                            <textarea
                                v-model="editReceiveDetailsForm.remarks"
                                placeholder="Enter specific remarks..."
                                :disabled="parseFloat(editReceiveDetailsForm.quantity_received) === 0"
                                class="flex min-h-[80px] w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm transition-colors focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none resize-none disabled:bg-gray-100 disabled:cursor-not-allowed"
                            ></textarea>
                            <button 
                                v-if="parseFloat(editReceiveDetailsForm.quantity_received) !== 0"
                                type="button" 
                                class="mt-2 text-xs text-blue-600 hover:text-blue-800 hover:underline" 
                                @click="goBackToPresetRemarks"
                            >
                                Back to Presets
                            </button>
                        </div>
                        <FormError>{{ editReceiveDetailsForm.errors.remarks }}</FormError>
                    </InputContainer>
                </div>

                <div class="flex justify-end items-center gap-3 mt-8">
                    <Button variant="ghost" @click="closeEditModal">Cancel</Button>
                    <Button @click="updateReceiveDetails">Update Details</Button>
                </div>
            </div>
        </div>

        <!-- View Details Modal -->
         <div
            v-if="isViewModalVisible"
            class="fixed inset-0 z-50 flex items-center justify-center"
        >
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="isViewModalVisible = false"></div>
            <div class="relative z-10 w-full sm:max-w-[500px] mx-4 bg-white rounded-xl shadow-2xl border border-gray-200 p-6 transform transition-all">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Transaction Details</h2>
                        <p class="text-sm text-gray-500">ID: #{{ selectedItem?.id }}</p>
                    </div>
                    <button @click="isViewModalVisible = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <X class="size-6" />
                    </button>
                </div>

                <div class="space-y-4">
                     <div class="p-4 bg-gray-50 rounded-lg border border-gray-100">
                        <div class="grid grid-cols-2 gap-4">
                             <div>
                                <span class="text-xs text-gray-500 uppercase tracking-wide">Item Name</span>
                                <p class="font-medium text-gray-900 mt-0.5">{{ selectedItem?.store_order_item.supplier_item.item_name }}</p>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500 uppercase tracking-wide">Item Code</span>
                                <p class="font-mono text-gray-700 mt-0.5">{{ selectedItem?.store_order_item.supplier_item.ItemCode }}</p>
                            </div>
                        </div>
                     </div>

                    <div class="grid grid-cols-2 gap-y-4 gap-x-6 text-sm">
                        <div>
                            <span class="text-xs text-gray-500 block">Quantity Received</span>
                            <span class="font-bold text-lg text-gray-900">{{ formatQuantity(selectedItem?.quantity_received) }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 block">Status</span>
                             <span :class="['inline-block px-2 py-0.5 rounded text-xs font-bold border mt-1', getStatusClass(selectedItem?.status)]">
                                {{ selectedItem?.status.toUpperCase() }}
                            </span>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 block">Received Date</span>
                            <span class="font-medium text-gray-900">{{ dayjs(selectedItem?.received_date).tz("Asia/Manila").format("MMM D, YYYY h:mm A") }}</span>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 block">Received By</span>
                            <span class="font-medium text-gray-900">{{ selectedItem?.received_by_user?.first_name }} {{ selectedItem?.received_by_user?.last_name }}</span>
                        </div>
                        <div class="col-span-2">
                             <span class="text-xs text-gray-500 block">Remarks</span>
                            <p class="text-gray-700 italic mt-0.5 bg-gray-50 p-2 rounded border border-gray-100">{{ selectedItem?.remarks || 'No remarks provided.' }}</p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end items-center mt-8">
                    <Button variant="outline" @click="isViewModalVisible = false">Close</Button>
                </div>
            </div>
        </div>

        <!-- Image Upload Modal -->
        <div
            v-if="isImageUploadModalVisible"
            class="fixed inset-0 z-50 flex items-center justify-center"
        >
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="isImageUploadModalVisible = false"></div>
            <div class="relative z-10 w-full sm:max-w-[500px] mx-4 bg-white rounded-xl shadow-2xl border border-gray-200 p-6 transform transition-all">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Upload Attachment</h2>
                        <p class="text-sm text-gray-500">Supported formats: PNG, JPG, JPEG</p>
                    </div>
                    <button @click="isImageUploadModalVisible = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <X class="size-6" />
                    </button>
                </div>

                <div class="space-y-4">
                     <div 
                        class="border-2 border-dashed border-gray-300 rounded-lg p-6 flex flex-col items-center justify-center text-center cursor-pointer hover:bg-gray-50 hover:border-blue-400 transition-colors"
                        @click="$refs.fileInput.click()"
                    >
                        <input
                            type="file"
                            ref="fileInput"
                            @change="onFileChange"
                            accept="image/png, image/jpeg, image/jpg"
                            class="hidden"
                        />
                        <div v-if="!imagePreviewUrl" class="flex flex-col items-center">
                            <svg class="size-10 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                            <span class="text-sm font-medium text-gray-600">Click to select image</span>
                            <span class="text-xs text-gray-400 mt-1">or drag and drop here</span>
                        </div>
                        <div v-else class="relative w-full max-h-64 overflow-hidden rounded">
                             <img :src="imagePreviewUrl" class="w-full h-auto object-contain" />
                             <button @click.stop="imagePreviewUrl = null; imageUploadForm.image = null;" class="absolute top-2 right-2 bg-black/50 text-white rounded-full p-1 hover:bg-black/70">
                                 <X class="size-4" />
                             </button>
                        </div>
                    </div>
                    <FormError>{{ imageUploadForm.errors.image }}</FormError>
                </div>
                <div class="flex justify-end items-center gap-3 mt-8">
                    <Button variant="ghost" @click="isImageUploadModalVisible = false">Cancel</Button>
                    <Button @click="submitImageUpload" :disabled="!imageUploadForm.image || imageUploadForm.processing">
                        <span v-if="imageUploadForm.processing">Uploading...</span>
                        <span v-else>Upload Image</span>
                    </Button>
                </div>
            </div>
        </div>
    </Layout>
</template>