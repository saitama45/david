<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";
import { X, Pencil, Lock, PackageX, Loader2 } from "lucide-vue-next";

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

import { useConfirm } from "primevue/useconfirm";
import dayjs from "dayjs";
import utc from "dayjs/plugin/utc"; // Import UTC plugin
import timezone from "dayjs/plugin/timezone"; // Import Timezone plugin

// Extend dayjs with the plugins
dayjs.extend(utc);
dayjs.extend(timezone);

// Set the default timezone for dayjs.tz() operations to Asia/Manila
dayjs.tz.setDefault("Asia/Manila");

const toast = useToast();
const confirm = useConfirm();

import { useBackButton } from "@/composables/useBackButton";

const { backButton } = useBackButton(route("interco-receiving.index"));

// Define remarks options for the dropdown
const remarksOptions = [
    { label: 'Damaged goods', value: 'Damaged goods' },
    { label: 'Missing goods', value: 'Missing goods' },
    { label: 'Expired goods', value: 'Expired goods' }
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
    // { at, by } once Final Receive All moved the stock and locked the transfer, otherwise null.
    receivingFinalized: {
        type: Object,
        default: null,
    },
});

const orderStatus = ref(props.order?.interco_status);

// Receiving works as on Inbound Orders. Zero All, saving a line and Confirm Receive All only
// record quantities; Final Receive All alone moves the stock - out of the sending store, into
// this one - and it locks the transfer for good. The server enforces the same rules.
const isFinalized = computed(() => !!props.receivingFinalized);

const finalizedHint = computed(() =>
    props.receivingFinalized
        ? `Finalized with Final Receive All on ${props.receivingFinalized.at}${
              props.receivingFinalized.by ? ` by ${props.receivingFinalized.by}` : ""
          }. Items can no longer be changed or received.`
        : ""
);

const rowStatus = (history) => String(history?.status ?? "").toLowerCase();

// Rows that are not in stock yet: only Final Receive All posts them ('approved').
const unpostedRows = computed(() =>
    props.receiveDatesHistory.filter((h) => ["pending", "received"].includes(rowStatus(h)))
);
const hasUnpostedRows = computed(() => unpostedRows.value.length > 0);

// Confirm Receive All has something to do while an item is still to receive.
const hasPendingRows = computed(() => unpostedRows.value.some((h) => rowStatus(h) === "pending"));

// Every receipt of the transfer is zero (Zero All, or each line saved with 0): nothing
// arrived, so there is no image to ask for. A row nobody touched still carries its
// committed quantity, so it does not count as zero.
const nothingReceived = computed(
    () => props.receiveDatesHistory.length > 0
        && props.receiveDatesHistory.every((h) => Number(h.quantity_received) === 0)
);

const canConfirmReceive = computed(() => {
    return (props.images && props.images.length > 0) || nothingReceived.value;
});

// Received quantities recorded on this transfer that stock on hand does not hold yet.
const quantityWaitingForFinal = computed(() =>
    unpostedRows.value.some((h) => rowStatus(h) === "received" && Number(h.quantity_received) !== 0)
);

// Unposted rows somebody already filled in with a quantity, which Zero All replaces.
const recordedQuantityCount = computed(
    () => unpostedRows.value.filter((h) => rowStatus(h) === "received" && Number(h.quantity_received) !== 0).length
);

const receivingRowStatus = (history) => {
    const status = rowStatus(history);

    if (status === "approved" || status === "received") return "RECEIVED";

    return status === "pending" ? "TO RECEIVE" : status.toUpperCase();
};

const isImageModalVisible = ref(false);
const openImageModal = () => {
    isImageModalVisible.value = true;
};

const closeImageModal = () => {
    isImageModalVisible.value = false;
    imageUploadForm.reset();
    imagePreviewUrl.value = null;
    imageUploadForm.clearErrors();
};

const handleEscapeKey = (event) => {
    if (event.key === 'Escape' && isImageModalVisible.value) {
        closeImageModal();
    }
};

const handleBackdropClick = (event) => {
    if (event.target === event.currentTarget) {
        closeImageModal();
    }
};

onMounted(() => {
    document.addEventListener('keydown', handleEscapeKey);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscapeKey);
});

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
    imageUploadForm.post(route('interco-receiving.attach-image', props.order.id), {
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: 'Success',
                detail: 'Image attached successfully.',
                life: 3000,
            });
            closeImageModal();
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

const showItemDetails = ref(false);
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
    form.post(route("interco-receiving.receive", targetId.value), {
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

const isLoading = ref(false);

const isEditModalVisible = ref(false);
const currentEditingItem = ref(null);

watch(isEditModalVisible, (value) => {
    if (!value) {
        editReceiveDetailsForm.reset();
        editReceiveDetailsForm.clearErrors();
        isLoading.value = false;
        currentEditingItem.value = null;
    }
});

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
    const committed = currentEditingItem.value.store_order_item?.quantity_commited || 0;
    const received = parseFloat(editReceiveDetailsForm.quantity_received) || 0;
    return received - committed;
});

const openEditModalForm = (id) => {
    const data = props.receiveDatesHistory;
    const existingItemIndex = data.findIndex((history) => history.id === id);
    const history = data[existingItemIndex];

    currentEditingItem.value = history;
    editReceiveDetailsForm.id = history.id;
    editReceiveDetailsForm.quantity_received = history.quantity_received;
    editReceiveDetailsForm.remarks = history.remarks;
    isEditModalVisible.value = true;
};

const updateReceiveDetails = () => {
    isLoading.value = true;
    editReceiveDetailsForm.post(
        route("interco-receiving.update-receiving-history"),
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

const confirmReceive = () => {
    router.post(route('interco-receiving.confirm-receive', props.order.interco_number), {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            if (page.props.flash?.info) {
                toast.add({ severity: 'info', summary: 'Confirm Receive All', detail: page.props.flash.info, life: 5000 });
                return;
            }
            toast.add({
                severity: 'success',
                summary: 'Received Quantities Confirmed',
                detail: 'No stock is moved yet. Click Final Receive All to move it and lock this transfer.',
                life: 7000,
            });
        },
        onError: (err) => {
            toast.add({
                severity: "error",
                summary: "Unable to Confirm",
                detail: err.error || err.message || "An error occurred.",
                life: 7000,
            });
        }
    });
};

const promptConfirmReceive = () => {
    if (!canConfirmReceive.value) {
        toast.add({
            severity: 'error',
            summary: 'Image Required',
            detail: 'Please attach at least one image before confirming receipt.',
            life: 5000,
        });
        return;
    }
    confirm.require({
        message: 'Confirm the received quantity of every item? An item not changed is received at its committed quantity. '
            + 'No stock is moved yet: that happens only on Final Receive All. Until then the quantities can still be changed.',
        header: 'Confirm Receive All?',
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

// Zero All: for a transfer that did not arrive. Sets every item not in stock yet to 0 with
// the remark "Unserved", and is the one receiving action that needs no image. It moves no
// stock: Final Receive All still follows.
const zeroAllForm = useForm({});

const zeroAll = () => {
    zeroAllForm.post(route('interco-receiving.zero-all', props.order.interco_number), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: 'Zero All',
                detail: 'Every item not yet in stock is now 0 and marked "Unserved".',
                life: 5000,
            });
        },
        onError: (errors) => {
            toast.add({
                severity: 'error',
                summary: 'Unable to Zero All',
                detail: errors.error || 'Please try again.',
                life: 7000,
            });
        },
    });
};

const promptZeroAll = () => {
    const pending = unpostedRows.value.length;
    const recorded = recordedQuantityCount.value;

    confirm.require({
        header: 'Zero All?',
        message:
            `${pending} item(s) will be set to 0 received and marked "Unserved". `
            + (recorded > 0 ? `${recorded} of them already have a received quantity, which will be replaced by 0. ` : '')
            + 'Use this when the transfer did not arrive. No image is needed. '
            + 'Afterwards click Final Receive All to finish.',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Cancel',
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: {
            label: 'Zero All',
            severity: 'danger',
        },
        accept: () => {
            zeroAll();
        },
    });
};

// Final Receive All: moves the stock of every item not in stock yet, then locks the transfer.
const finalReceiveForm = useForm({});

const finalReceive = () => {
    finalReceiveForm.post(route('interco-receiving.final-receive', props.order.interco_number), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: 'Receiving Finalized',
                detail: 'The stock is moved and this transfer is now locked. Its items can no longer be changed.',
                life: 5000,
            });
        },
        onError: (errors) => {
            toast.add({
                severity: 'error',
                summary: 'Unable to Finalize',
                detail: errors.error || 'Please try again.',
                life: 7000,
            });
        },
    });
};

const promptFinalReceive = () => {
    if (!canConfirmReceive.value) {
        toast.add({
            severity: 'error',
            summary: 'Image Required',
            detail: 'Please attach at least one image before finalizing.',
            life: 5000,
        });
        return;
    }

    const pending = unpostedRows.value.length;

    confirm.require({
        header: 'Final Receive All?',
        message:
            `${pending} item(s) will be added to the stock on hand (SOH) of ${props.order?.store_branch?.name || 'the receiving store'} `
            + `and taken out of ${props.order?.from_store_name || 'the sending store'} now, each at the quantity on its line. `
            + 'This is the final step for this transfer: afterwards no quantity can be changed. This cannot be undone.',
        icon: 'pi pi-lock',
        rejectProps: {
            label: 'Cancel',
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: {
            label: 'Final Receive All',
            severity: 'danger',
        },
        accept: () => {
            finalReceive();
        },
    });
};
</script>

<template>
    <Layout heading="Interco Transfer Details">

        <div class="space-y-6">
            <!-- Order Information Header -->
            <div class="bg-white rounded-lg shadow p-6">
                <h1 class="text-xl font-bold mb-4">Interco Transfer Details</h1>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label class="text-xs text-gray-500">Sending Store:</label>
                        <p class="font-semibold">
                            {{ order.from_store_name }}
                        </p>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">Receiving Store:</label>
                        <p class="font-semibold">
                            {{ order?.store_branch?.name || 'N/A' }}
                        </p>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">Interco Number:</label>
                        <p class="font-semibold">{{ order?.interco_number }}</p>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">Transfer Date:</label>
                        <p class="font-semibold">{{ order?.order_date }}</p>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">Created By:</label>
                        <p class="font-semibold">
                            {{ order?.encoder?.first_name }} {{ order?.encoder?.last_name }}
                        </p>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">Status:</label>
                        <p class="font-semibold">{{ order?.interco_status?.toUpperCase() }}</p>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">Reason:</label>
                        <p class="font-semibold">{{ order?.interco_reason || 'N/A' }}</p>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">Remarks:</label>
                        <p class="font-semibold">{{ order?.interco_remarks || 'N/A' }}</p>
                    </div>
                </div>
            </div>

  
            <!-- Image Attachments -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-lg font-semibold">Image Attachments</h2>
                    <button
                        @click="openImageModal"
                        class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors"
                    >
                        Attach Image
                    </button>
                </div>
                <div class="flex flex-wrap gap-4">
                    <div v-for="image in images" :key="image.id" class="relative">
                        <button
                            @click="deleteImageForm.id = image.id; deleteImage();"
                            class="absolute -right-2 -top-2 text-white w-5 h-5 rounded-full bg-red-500 hover:bg-red-600"
                        >
                            <X class="w-5 h-5" />
                        </button>
                        <a :href="image.image_url" target="_blank" rel="noopener noreferrer">
                            <img
                                :src="image.image_url"
                                class="w-24 h-24 cursor-pointer hover:opacity-80 transition-opacity rounded-md object-cover"
                            />
                        </a>
                    </div>
                </div>
                <div v-if="!images?.length" class="text-center py-8 text-gray-500">
                    No images attached.
                </div>
            </div>

            <!-- Interco Items -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold">Interco Items</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">BaseUOM</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">UOM</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SOH Stock</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ordered</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Approved</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Commited</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Received</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="orderItem in orderedItems" :key="orderItem.id">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ orderItem.ItemCode }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ orderItem.item_name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ orderItem.BaseUOM }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ orderItem.uom }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ orderItem.soh_stock }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ orderItem.quantity_ordered }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ orderItem.quantity_approved }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ orderItem.quantity_commited }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ orderItem.quantity_received }}</td>

                            </tr>
                        </tbody>
                    </table>
                    <div v-if="!orderedItems?.length" class="text-center py-8 text-gray-500">
                        No items found.
                    </div>
                </div>
            </div>

            <!-- Receiving History -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <div class="flex justify-between items-start gap-4">
                        <div class="flex flex-col gap-1">
                            <h2 class="text-lg font-semibold">Receiving History</h2>
                            <p
                                v-if="isFinalized"
                                class="inline-flex items-center gap-1.5 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800"
                            >
                                <Lock class="size-3.5" />
                                {{ finalizedHint }}
                            </p>
                            <!-- Recorded or confirmed is not in stock: only Final Receive All moves it -->
                            <p
                                v-else-if="quantityWaitingForFinal"
                                class="inline-flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800"
                            >
                                <Lock class="size-3.5" />
                                Not in stock on hand yet. The stock is moved between the two stores only when Final Receive All is clicked.
                            </p>
                        </div>
                        <!-- Everything here goes away once the transfer is in stock and locked -->
                        <div v-if="!isFinalized && hasUnpostedRows" class="flex items-center gap-3">
                            <!-- First, and never waiting for an image: it is for the transfer
                                 that did not arrive. -->
                            <Button
                                @click="promptZeroAll"
                                :disabled="zeroAllForm.processing"
                                variant="outline"
                                class="gap-2 border-2 border-rose-300 bg-rose-50 text-rose-700 font-semibold shadow-sm hover:bg-rose-100 hover:border-rose-400 hover:text-rose-800 transition-all"
                                title="The transfer did not arrive: set every item not yet in stock to 0 and mark it Unserved. No image is needed."
                            >
                                <Loader2 v-if="zeroAllForm.processing" class="size-4 animate-spin" />
                                <PackageX v-else class="size-4" />
                                Zero All
                            </Button>
                            <Button
                                v-if="hasPendingRows"
                                @click="promptConfirmReceive"
                                :disabled="!canConfirmReceive"
                                :variant="!canConfirmReceive ? 'secondary' : 'default'"
                                :title="!canConfirmReceive ? 'An image is required before confirming.' : 'Confirm the received quantity of every item. No stock is moved yet, and the quantities stay open for changes.'"
                            >
                                Confirm Receive All
                            </Button>
                            <Button
                                @click="promptFinalReceive"
                                :disabled="!canConfirmReceive || finalReceiveForm.processing"
                                :class="[
                                    'gap-2 font-bold shadow-md ring-2 ring-offset-1 transition-all',
                                    canConfirmReceive
                                        ? 'bg-emerald-600 text-white ring-emerald-300 hover:bg-emerald-700'
                                        : 'bg-gray-200 text-gray-500 ring-gray-200',
                                ]"
                                :title="!canConfirmReceive ? 'An image is required before finalizing.' : 'Final step: move the received quantities into stock on hand (SOH) and lock this transfer so its items can no longer be changed'"
                            >
                                <Loader2 v-if="finalReceiveForm.processing" class="size-4 animate-spin" />
                                <Lock v-else class="size-4" />
                                Final Receive All
                            </Button>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Id</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">UOM</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity Received</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Received At</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Received By</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remarks</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="history in receiveDatesHistory" :key="history.id">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ history.id }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ history.store_order_item?.item_name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ history.store_order_item?.ItemCode }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ history.store_order_item?.uom }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ history.quantity_received }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ history.received_date ? dayjs(history.received_date).tz("Asia/Manila").format("MMMM D, YYYY h:mm A") : '-' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <!-- A row nobody received yet still names the sending store's committer -->
                                    <template v-if="rowStatus(history) !== 'pending'">{{ history.received_by_user?.first_name }} {{ history.received_by_user?.last_name }}</template>
                                    <template v-else>-</template>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span
                                        class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full"
                                        :class="rowStatus(history) === 'pending' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'"
                                    >
                                        {{ receivingRowStatus(history) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ history.remarks || '-' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <button
                                            v-if="!isFinalized && (history.status === 'pending' || history.status === 'received')"
                                            @click="openEditModalForm(history.id)"
                                            class="text-yellow-600 hover:text-yellow-900"
                                        >
                                            <Pencil class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="!receiveDatesHistory?.length" class="text-center py-8 text-gray-500">
                        No receiving history found.
                    </div>
                </div>
            </div>
        </div>

        <!-- Custom Modal Dialog for Image Upload -->
        <div
            v-if="isImageModalVisible"
            class="fixed inset-0 z-50 flex items-center justify-center"
            @click="handleBackdropClick"
        >
            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

            <!-- Modal Content -->
            <div class="relative z-10 w-full max-w-md mx-4 bg-white rounded-lg shadow-xl border border-gray-200 p-6 transform transition-all">
                <!-- Header -->
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Attach Image</h2>
                        <p class="text-sm text-gray-600 mt-1">Select an image file to upload for this order.</p>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="closeImageModal"
                        class="h-8 w-8 p-0 hover:bg-gray-100"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
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
                    <!-- Image preview container -->
                    <div v-if="imagePreviewUrl" class="mt-4 max-h-64 overflow-y-auto">
                        <Label class="text-xs">Preview</Label>
                        <img :src="imagePreviewUrl" class="mt-2 max-w-full h-auto rounded-md border object-contain" />
                    </div>
                </div>

                <!-- Footer -->
                <div class="flex justify-end items-center mt-6 space-x-2">
                    <Button variant="ghost" @click="closeImageModal">Cancel</Button>
                    <Button @click="submitImageUpload" :disabled="imageUploadForm.processing">
                        <span v-if="imageUploadForm.processing">Uploading...</span>
                        <span v-else>Upload</span>
                    </Button>
                </div>
            </div>
        </div>

        <!-- Receive Form Modal -->
        <!-- Edit Receive Details Modal -->
        <!-- View Receive History Modal -->
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
                        <FormError>{{ editReceiveDetailsForm.errors.quantity_received }}</FormError>
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
    </Layout>
</template>
