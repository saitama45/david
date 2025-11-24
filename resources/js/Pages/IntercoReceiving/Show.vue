<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { router } from "@inertiajs/vue3";
import { X, Pencil } from "lucide-vue-next";

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

import { useBackButton } from "@/Composables/useBackButton";

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
});

const orderStatus = ref(props.order?.interco_status);

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
    const committed = currentEditingItem.value.store_order_item.quantity_commited || 0;
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
        onSuccess: () => {
            toast.add({ severity: 'success', summary: 'Success', detail: 'Receive Confirmed.', life: 3000 });
        },
        onError: (err) => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: err.message || "An error occurred.",
                life: 5000,
            });
        }
    });
};

const promptConfirmReceive = () => {
    if (!props.images || props.images.length === 0) {
        toast.add({
            severity: 'error',
            summary: 'Image Required',
            detail: 'Please attach at least one image before confirming receipt.',
            life: 5000,
        });
        return;
    }
    confirm.require({
        message: 'Are you sure you want to confirm all pending received items?',
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
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ orderItem.sap_masterfile?.ItemCode }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ orderItem.sap_masterfile?.ItemDescription }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ orderItem.sap_masterfile?.BaseUOM }}</td>
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
                    <div class="flex justify-between items-center">
                        <h2 class="text-lg font-semibold">Receiving History</h2>
                        <button
                            v-if="order?.interco_status !== 'received'"
                            @click="promptConfirmReceive"
                            :disabled="!images || images.length === 0"
                            class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition-colors"
                            :class="{ 'opacity-50 cursor-not-allowed': !images || images.length === 0 }"
                        >
                            Confirm Receive
                        </button>
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
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="history in receiveDatesHistory" :key="history.id">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ history.id }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ history.store_order_item?.sap_masterfile?.ItemDescription }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ history.store_order_item?.sap_masterfile?.ItemCode }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ history.store_order_item?.uom }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ history.quantity_received }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ dayjs(history.received_date).tz("Asia/Manila").format("MMMM D, YYYY h:mm A") }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ history.received_by_user?.first_name }} {{ history.received_by_user?.last_name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                        {{ history.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <button
                                            v-if="history.status === 'pending'"
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