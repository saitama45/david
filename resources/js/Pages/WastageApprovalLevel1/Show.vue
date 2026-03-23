<script setup>
import { useBackButton } from "@/composables/useBackButton";
import { router, usePage } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/composables/useToast";
import { useForm } from "@inertiajs/vue3";
import { ref, watch, computed } from 'vue';
import { Edit, Save, X, Trash2, Paperclip, Loader2, AlertCircle, ImageIcon } from "lucide-vue-next";
import { useAuth } from "@/composables/useAuth";

const confirm = useConfirm();
const { toast } = useToast();
const { hasAccess } = useAuth();

const { backButton } = useBackButton(route("wastage-approval-lvl1.index"));

const props = defineProps({
    wastage: {
        type: Object,
        required: true,
    },
    permissions: {
        type: Object,
        required: true,
    },
    approval_error: {
        type: String,
        default: null,
    },
    approval_stock_errors: {
        type: Array,
        default: () => [],
    },
});


// Helper functions for consistent data display
const formatDate = (dateString) => {
    if (!dateString) return 'N/A'
    return new Date(dateString).toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    })
}

const storeName = (wastage) => {
    return wastage.store_branch_name ||
           wastage.storeBranch?.name ||
           wastage.storeBranch?.branch_name ||
           wastage.storeBranch?.brand_name ||
           'Unknown Store'
}

const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "APPROVED_LVL1":
            return "bg-blue-500 text-white";
        case "PENDING":
            return "bg-yellow-500 text-white";
        case "CANCELLED":
            return "bg-red-500 text-white";
        default:
            return "bg-gray-500 text-white";
    }
};

const isLoading = ref(false);

// Edit state variables for quantity editing
const editingItem = ref(null); // { id: number, originalValue: number }
const editValue = ref('');
const editInput = ref(null);

// Focus directive for auto-selecting text when editing
const vFocusSelect = {
    mounted: (el) => {
        const input = el.tagName === 'INPUT' ? el : el.querySelector('input');
        if (input) {
            input.focus();
            input.select();
        }
    }
}

const remarksForm = useForm({
    order_id: null,
    remarks: null,
});

const approveWastage = (id) => {
    confirm.require({
        message: "Are you sure you want to approve this wastage record?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "info",
        },
        accept: () => {
            isLoading.value = true;
            remarksForm.order_id = id;
            remarksForm.post(route("wastage-approval-lvl1.approve"), {
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Wastage record approved successfully.",
                        life: 3000,
                    });
                    router.get(route("wastage-approval-lvl1.index"), {}, { replace: true });
                },
                onError: () => {
                    isLoading.value = false;
                },
            });
        },
    });
};

const cancelWastage = (id) => {
    confirm.require({
        message: "Are you sure you want to cancel this wastage record?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "danger",
        },
        accept: () => {
            isLoading.value = true;
            remarksForm.order_id = id;
            remarksForm.post(route("wastage-approval-lvl1.cancel"), {
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Wastage record cancelled successfully.",
                        life: 3000,
                    });
                    router.get(route("wastage-approval-lvl1.index"), {}, { replace: true });
                },
                onError: () => {
                    isLoading.value = false;
                },
            });
        },
    });
};

// Quantity editing functionality
const itemsDetail = ref([]);

// Initialize and watch for changes in props.wastage
watch(() => props.wastage, (newWastage) => {
    if (newWastage && newWastage.items) {
        itemsDetail.value = newWastage.items.map(item => ({
            id: item.id,
            wastage_qty: item.wastage_qty,
            approverlvl1_qty: item.approverlvl1_qty ?? item.wastage_qty,
            item_code: item.sap_masterfile?.ItemCode,
            description: item.sap_masterfile?.ItemDescription,
            cost: item.cost,
            uom: item.sap_masterfile?.BaseUOM,
        }));
    }
}, { immediate: true, deep: true });


const startEdit = (itemId) => {
    const item = itemsDetail.value.find(item => item.id === itemId);
    if (item) {
        editingItem.value = { id: itemId, originalValue: item.approverlvl1_qty };
        editValue.value = item.approverlvl1_qty.toString();
    }
};

const saveEdit = () => {
    if (!editingItem.value) return;

    const quantity = parseFloat(editValue.value);
    if (isNaN(quantity) || quantity < 0) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Please enter a valid quantity.",
            life: 3000,
        });
        return;
    }

    const newQuantity = Number(quantity.toFixed(2));
    const editingItemId = editingItem.value.id;
    const originalQuantity = editingItem.value.originalValue;

    // Update the local itemsDetail array immediately for reactive display
    const itemInDetails = itemsDetail.value.find(item => item.id === editingItemId);
    if (itemInDetails) {
        itemInDetails.approverlvl1_qty = newQuantity;
    }

    updateItemQuantity(editingItemId, newQuantity, originalQuantity);
    editingItem.value = null;
    editValue.value = '';
};

const cancelEdit = () => {
    editingItem.value = null;
    editValue.value = '';
};

const updateItemQuantity = (itemId, quantity, originalQuantity) => {
    router.post(
        route("wastage-approval-lvl1.update-quantity", itemId),
        {
            approverlvl1_qty: quantity
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: "Quantity updated successfully.",
                    life: 2000,
                });
            },
            onError: (errors) => {
                // Revert the itemsDetail array to original value on API failure
                const itemInDetails = itemsDetail.value.find(item => item.id === itemId);
                if (itemInDetails) {
                    itemInDetails.approverlvl1_qty = originalQuantity;
                }

                // Show specific error message if available
                const errorMessage = errors.approverlvl1_qty ||
                                   errors.message ||
                                   "Failed to update quantity. Please refresh the page.";

                toast.add({
                    severity: "error",
                    summary: "Error",
                    detail: errorMessage,
                    life: 3000,
                });
            },
        }
    );
};

const deleteItem = (itemId) => {
    confirm.require({
        message: "Are you sure you want to delete this item?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Delete",
            severity: "danger",
        },
        accept: () => {
            router.delete(route("wastage-approval-lvl1.destroy-item", itemId), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Item deleted successfully.",
                        life: 3000,
                    });
                },
                onError: (errors) => {
                    const errorMessage = Object.values(errors).join(' ');
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: errorMessage || "Failed to delete item.",
                        life: 3000,
                    });
                },
            });
        },
    });
};

// --- Image Display Logic (mirrored from Wastage/Show.vue) ---
const imageLoadingStates = ref({});
const imageErrors = ref({});
const urlAttempts = ref({});

const transformGoogleDriveUrl = (url, attemptIndex = 0) => {
  if (!url) {
    return { url: '', isGoogleDrive: false, hasMoreFallbacks: false };
  }

  if (url.includes('drive.google.com')) {
    try {
      let fileId = null;

      if (url.includes('/file/d/')) {
        const match = url.match(/\/file\/d\/([a-zA-Z0-9_-]+)/);
        if (match) {
          fileId = match[1];
        }
      } else if (url.includes('open?id=')) {
        const match = url.match(/[?&]id=([a-zA-Z0-9_-]+)/);
        if (match) {
          fileId = match[1];
        }
      }

      if (fileId) {
        const urlFormats = [
          `/proxy/google-drive/${fileId}`,
          `https://drive.google.com/thumbnail?id=${fileId}&sz=s400`,
          `https://drive.google.com/uc?export=view&id=${fileId}`,
          `https://drive.google.com/uc?export=download&id=${fileId}`,
          `https://docs.google.com/uc?export=view&id=${fileId}`,
          `https://lh3.googleusercontent.com/d/${fileId}=s400`
        ];

        if (attemptIndex < urlFormats.length) {
          const transformedUrl = urlFormats[attemptIndex];
          return {
            url: transformedUrl,
            isGoogleDrive: true,
            hasMoreFallbacks: attemptIndex < urlFormats.length - 1,
            fileId,
            attemptIndex,
            totalFormats: urlFormats.length,
            allFormats: urlFormats
          };
        } else {
          return { url: '', isGoogleDrive: true, hasMoreFallbacks: false, fileId };
        }
      }
    } catch (error) {
      console.error('Error transforming Google Drive URL:', error, 'URL:', url);
    }
  }
  return { url, isGoogleDrive: false, hasMoreFallbacks: false };
};

const getItemImages = (item) => {
  let rawUrls = [];
  try {
    if (item.image_url) {
      rawUrls = typeof item.image_url === 'string' ? JSON.parse(item.image_url) : item.image_url;
    }
  } catch (e) {
    console.error('Error parsing item images:', e);
  }

  if (!Array.isArray(rawUrls)) {
    rawUrls = rawUrls ? [rawUrls] : [];
  }

  return rawUrls.map(originalUrl => {
    if (urlAttempts.value[originalUrl] === undefined) {
      urlAttempts.value[originalUrl] = 0;
    }

    const currentAttempt = urlAttempts.value[originalUrl];
    const urlInfo = transformGoogleDriveUrl(originalUrl, currentAttempt);

    return {
      type: 'existing',
      url: urlInfo.url,
      id: originalUrl,
      originalUrl,
      urlInfo,
      attemptIndex: currentAttempt
    };
  });
};

const handleImageLoad = (imageId) => {
  imageLoadingStates.value[imageId] = false;
  imageErrors.value[imageId] = null;
  urlAttempts.value[imageId] = 0;
};

const handleImageError = (imageId, image) => {
  if (image.urlInfo && image.urlInfo.isGoogleDrive && image.urlInfo.hasMoreFallbacks) {
    const nextAttempt = (urlAttempts.value[imageId] || 0) + 1;
    const newAttempts = { ...urlAttempts.value };
    newAttempts[imageId] = nextAttempt;
    urlAttempts.value = newAttempts;
    return;
  }

  imageLoadingStates.value[imageId] = false;
  imageErrors.value[imageId] = 'Error';
};

const initializeImageLoading = (image) => {
  const imageId = image.id;
  imageLoadingStates.value[imageId] = true;
  imageErrors.value[imageId] = null;
};

const handleImageClick = (image) => {
  if (imageLoadingStates.value[image.id] || imageErrors.value[image.id]) {
    return;
  }
  window.open(image.url, '_blank');
};
</script>

<template>
    <Layout heading="Wastage Record Details">
        <TableContainer>
            <!-- Error Display Section -->
            <div v-if="approval_error" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                <div class="flex items-start">
                    <AlertCircle class="w-5 h-5 text-red-600 mt-0.5 mr-3 flex-shrink-0" />
                    <div>
                        <h3 class="text-red-800 font-medium">{{ approval_error }}</h3>
                        <div v-if="approval_stock_errors && approval_stock_errors.length > 0" class="mt-3">
                            <table class="min-w-full divide-y divide-red-200 bg-white shadow-sm rounded-md overflow-hidden">
                                <thead class="bg-red-100">
                                    <tr>
                                        <th scope="col" class="px-3 py-2 text-left text-xs font-medium text-red-800 uppercase tracking-wider">Item Code</th>
                                        <th scope="col" class="px-3 py-2 text-left text-xs font-medium text-red-800 uppercase tracking-wider">Description</th>
                                        <th scope="col" class="px-3 py-2 text-right text-xs font-medium text-red-800 uppercase tracking-wider">Available</th>
                                        <th scope="col" class="px-3 py-2 text-right text-xs font-medium text-red-800 uppercase tracking-wider">Required</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-red-100">
                                    <tr v-for="(error, index) in approval_stock_errors" :key="index">
                                        <td class="px-3 py-2 whitespace-nowrap text-sm font-medium text-gray-900">{{ error.item_code }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500">{{ error.item_description }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500 text-right">{{ error.available }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-red-600 font-bold text-right">{{ error.required }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <section class="flex flex-col gap-5">
                <section class="sm:flex-row flex flex-col gap-5">
                    <span class="text-gray-700 text-sm">
                        Wastage Number:
                        <span class="font-bold"> {{ wastage.wastage_no }}</span>
                    </span>
                    <span class="text-gray-700 text-sm">
                        Store:
                        <span class="font-bold"> {{ storeName(wastage) }}</span>
                    </span>
                </section>

                <section class="sm:flex-row flex flex-col gap-5">
                    <span class="text-gray-700 text-sm">
                        Status:
                        <Badge
                            :class="statusBadgeColor(wastage.wastage_status)"
                            class="font-bold"
                        >
                            {{ wastage.wastage_status?.toUpperCase().replace('_', ' ') ?? "N/A" }}
                        </Badge>
                    </span>
                    <span class="text-gray-700 text-sm">
                        Date:
                        <span class="font-bold"> {{ formatDate(wastage.created_at) }}</span>
                    </span>
                </section>

                <section class="sm:flex-row flex flex-col gap-5">
                    <span class="text-gray-700 text-sm">
                        Remarks:
                        <span class="font-bold"> {{ wastage.remarks ?? "No remarks provided" }}</span>
                    </span>
                </section>

                <DivFlexCenter class="gap-5">
                    <Button
                        v-if="wastage.wastage_status === 'pending' && hasAccess('cancel wastage approval level 1')"
                        variant="destructive"
                        @click="cancelWastage(wastage.id)"
                        :disabled="isLoading"
                    >
                        Cancel Wastage
                    </Button>
                    <Button
                        v-if="wastage.wastage_status === 'pending' && hasAccess('approve wastage level 1')"
                        class="bg-green-500 hover:bg-green-300"
                        @click="approveWastage(wastage.id)"
                        :disabled="isLoading"
                    >
                        Approve Wastage
                    </Button>
                </DivFlexCenter>
            </section>

            <TableHeader>
            </TableHeader>

            <div class="border rounded-lg overflow-hidden">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Item</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Reason</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Evidence</th>
                            <th scope="col" class="px-4 py-3 font-semibold text-center">UOM</th>
                            <th scope="col" class="px-4 py-3 font-semibold text-center">Wastage Qty</th>
                            <th scope="col" class="px-4 py-3 font-semibold text-center">Approved Qty</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr v-for="item in wastage.items" :key="item.id" class="bg-white hover:bg-gray-50">
                            <td class="px-4 py-4">
                                <div class="font-medium text-gray-900">{{ item.sap_masterfile?.ItemCode || 'N/A' }}</div>
                                <div class="text-xs text-gray-500 line-clamp-1 max-w-[200px]">{{ item.sap_masterfile?.ItemDescription || 'N/A' }}</div>
                            </td>
                            <td class="px-4 py-4 text-gray-600">{{ item.reason || 'N/A' }}</td>
                            <td class="px-4 py-4">
                                <div class="flex flex-wrap gap-2">
                                    <template v-if="getItemImages(item).length > 0" v-for="image in getItemImages(item)" :key="image.id">
                                        <div 
                                            class="relative w-8 h-8 group cursor-pointer flex-shrink-0"
                                            @click="handleImageClick(image)"
                                        >
                                            <div class="w-full h-full">
                                                <div v-if="imageLoadingStates[image.id]" class="absolute inset-0 bg-gray-100 rounded flex items-center justify-center">
                                                    <Loader2 class="w-3 h-3 text-blue-600 animate-spin" />
                                                </div>
                                                <div v-else-if="imageErrors[image.id]" class="absolute inset-0 bg-red-50 rounded flex items-center justify-center border border-red-100">
                                                    <AlertCircle class="w-3 h-3 text-red-500" />
                                                </div>
                                                <img
                                                    v-else
                                                    :src="image.url"
                                                    class="object-cover rounded w-full h-full border border-gray-200 hover:ring-2 hover:ring-blue-400 transition-all"
                                                    @load="() => handleImageLoad(image.id)"
                                                    @error="() => handleImageError(image.id, image)"
                                                    @loadstart="() => initializeImageLoading(image)"
                                                />
                                            </div>
                                        </div>
                                    </template>
                                    <span v-else class="text-[10px] text-gray-400 italic">None</span>
                                </div>
                            </td>
                            <td class="px-4 py-4 text-center">{{ (item.sap_masterfile?.AltUOM || item.sap_masterfile?.BaseUOM) ?? "N/A" }}</td>
                            <td class="px-4 py-4 text-center font-semibold">{{ item.wastage_qty }}</td>
                            <td class="px-4 py-4">
                                <div v-if="wastage.wastage_status === 'pending'" class="flex items-center justify-center gap-2">
                                    <div v-if="editingItem && editingItem.id === item.id" class="flex items-center gap-2">
                                        <Input
                                            v-focus-select
                                            type="number"
                                            v-model="editValue"
                                            class="w-20 text-right h-8 text-xs"
                                            @keyup.enter="saveEdit"
                                            @keyup.esc="cancelEdit"
                                        />
                                        <div class="flex gap-1">
                                            <Save class="size-4 text-green-500 cursor-pointer" @click="saveEdit" />
                                            <X class="size-4 text-red-500 cursor-pointer" @click="cancelEdit" />
                                        </div>
                                    </div>
                                    <div v-else class="flex items-center gap-3">
                                        <span class="font-bold">
                                            {{ itemsDetail.find((data) => data.id === item.id)?.approverlvl1_qty ?? 0 }}
                                        </span>
                                        <Edit
                                            v-if="hasAccess('edit wastage approval level 1')"
                                            class="size-3.5 text-blue-500 cursor-pointer"
                                            @click="startEdit(item.id)"
                                        />
                                        <Trash2
                                            v-if="hasAccess('delete wastage approval level 1')"
                                            class="size-3.5 text-red-500 cursor-pointer"
                                            @click="deleteItem(item.id)"
                                        />
                                    </div>
                                </div>
                                <div v-else class="text-center font-bold">{{ item.approverlvl1_qty }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <MobileTableContainer>
                <MobileTableRow v-for="item in wastage.items" :key="item.id">
                    <MobileTableHeading
                        :title="`${item.sap_masterfile?.ItemDescription || 'N/A'} (${item.sap_masterfile?.ItemCode || 'N/A'})`"
                    >
                        <div v-if="wastage.wastage_status === 'pending' && hasAccess('edit wastage approval level 1')">
                            <div v-if="editingItem && editingItem.id === item.id" class="flex items-center gap-2">
                                <Input
                                    v-focus-select
                                    type="number"
                                    v-model="editValue"
                                    class="w-20 text-right h-8"
                                    @keyup.enter="saveEdit"
                                    @keyup.esc="cancelEdit"
                                />
                                <Save class="size-4 text-green-500" @click="saveEdit" />
                                <X class="size-4 text-red-500" @click="cancelEdit" />
                            </div>
                            <Edit v-else class="size-4 text-blue-500" @click="startEdit(item.id)" />
                        </div>
                    </MobileTableHeading>
                    <LabelXS>Reason: {{ item.reason || 'N/A' }}</LabelXS>
                    <LabelXS>UOM: {{ (item.sap_masterfile?.AltUOM || item.sap_masterfile?.BaseUOM) ?? "N/A" }}</LabelXS>
                    <LabelXS>Wastage: {{ item.wastage_qty }}</LabelXS>
                    <LabelXS>Approved: {{ itemsDetail.find((data) => data.id === item.id)?.approverlvl1_qty ?? 0 }}</LabelXS>
                    
                    <div class="mt-3 flex flex-wrap gap-2">
                        <template v-if="getItemImages(item).length > 0" v-for="image in getItemImages(item)" :key="image.id">
                            <img :src="image.url" class="w-12 h-12 object-cover rounded border" @click="handleImageClick(image)" />
                        </template>
                    </div>

                    <div v-if="wastage.wastage_status === 'pending' && hasAccess('delete wastage approval level 1')" class="flex justify-end mt-2">
                        <Trash2 class="size-4 text-red-500" @click="deleteItem(item.id)" />
                    </div>
                </MobileTableRow>
            </MobileTableContainer>
        </TableContainer>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>

<style scoped>
.object-cover {
  object-fit: cover;
}

.cursor-pointer {
  transition: transform 0.2s ease;
}

.cursor-pointer:hover {
  transform: scale(1.05);
}

.transition-colors {
  transition-property: color, background-color, border-color;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 150ms;
}
</style>