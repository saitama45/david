<script setup>
import { useBackButton } from "@/Composables/useBackButton";
import { router, usePage } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { useForm } from "@inertiajs/vue3";
import { ref, watch, computed } from 'vue';
import { Edit, Save, X, Trash2, Paperclip, Loader2, AlertCircle } from "lucide-vue-next";
import { useAuth } from "@/Composables/useAuth";

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

const allImages = computed(() => {
  let rawUrls = [];
  if (props.wastage?.image_urls && Array.isArray(props.wastage.image_urls) && props.wastage.image_urls.length > 0) {
    rawUrls = props.wastage.image_urls;
  } else if (props.wastage?.image_url) {
    rawUrls = [props.wastage.image_url];
  }

  const images = rawUrls.map(originalUrl => {
    if (urlAttempts.value[originalUrl] === undefined) {
      urlAttempts.value[originalUrl] = 0;
    }

    const currentAttempt = urlAttempts.value[originalUrl];
    const urlInfo = transformGoogleDriveUrl(originalUrl, currentAttempt);

    return {
      type: 'existing',
      url: urlInfo.url,
      id: originalUrl, // Use originalUrl as ID for existing images
      originalUrl,
      urlInfo,
      attemptIndex: currentAttempt
    };
  });

  return images;
});

const hasImages = computed(() => allImages.value.length > 0);

const isDevelopment = computed(() => {
  try {
    return import.meta.env?.DEV || false
  } catch (error) {
    console.warn('Could not determine development mode:', error)
    return false
  }
});

const handleImageLoad = (imageId) => {
  imageLoadingStates.value[imageId] = false;
  imageErrors.value[imageId] = null;
  urlAttempts.value[imageId] = 0; // Reset attempts on successful load
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
  if (image.urlInfo && image.urlInfo.isGoogleDrive) {
    imageErrors.value[imageId] = `Failed to load image after trying ${image.urlInfo.totalFormats} different URL formats. The Google Drive file may not be publicly accessible or may have been deleted.`;
  } else {
    imageErrors.value[imageId] = 'Failed to load image. The URL may be invalid or the image may not be accessible.';
  }
};

const initializeImageLoading = (image) => {
  const imageId = image.id;
  imageLoadingStates.value[imageId] = true;
  imageErrors.value[imageId] = null;
};

const retryWithNextUrl = (image) => {
  if (image.urlInfo && image.urlInfo.isGoogleDrive && image.urlInfo.hasMoreFallbacks) {
    urlAttempts.value[image.id] = (urlAttempts.value[image.id] || 0) + 1;
    imageErrors.value[image.id] = null;
    imageLoadingStates.value[image.id] = true;
  } else {
    urlAttempts.value[image.id] = 0; // Reset to first attempt
    imageErrors.value[image.id] = null;
    imageLoadingStates.value[image.id] = true;
  }
};

const handleImageClick = (image, index) => {
  if (imageLoadingStates.value[image.id] || imageErrors.value[image.id]) {
    return;
  }
  window.open(image.url, '_blank');
};
</script>

<template>
    <Layout heading="Wastage Record Details">
        <TableContainer>
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

            <Table>
                <TableHead>
                    <TH> Item Code </TH>
                    <TH> Description </TH>
                    <TH> Reason </TH>
                    <TH> UOM </TH>
                    <TH> Wastage Qty </TH>
                    <TH v-if="wastage.wastage_status === 'pending'">Approved Qty</TH>
                    <TH v-else>Approved Qty</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in wastage.items" :key="item.id">
                        <TD>{{ item.sap_masterfile?.ItemCode || 'N/A' }}</TD>
                        <TD>{{ item.sap_masterfile?.ItemDescription || 'N/A' }}</TD>
                        <TD class="text-sm">{{ item.reason || 'No reason specified' }}</TD>
                        <TD>{{ (item.sap_masterfile?.AltUOM || item.sap_masterfile?.BaseUOM) ?? "N/A" }}</TD>
                        <TD>{{ item.wastage_qty }}</TD>
                        <TD class="flex items-center gap-3" v-if="wastage.wastage_status === 'pending'">
                        <div v-if="editingItem && editingItem.id === item.id">
                            <Input
                                v-focus-select
                                type="number"
                                v-model="editValue"
                                class="w-20 text-right"
                                @keyup.enter="saveEdit"
                                @keyup.esc="cancelEdit"
                            />
                            <DivFlexCenter class="gap-1 ml-2">
                                <Save class="size-4 text-green-500 cursor-pointer hover:text-green-600" @click="saveEdit" />
                                <X class="size-4 text-red-500 cursor-pointer hover:text-red-600" @click="cancelEdit" />
                            </DivFlexCenter>
                        </div>
                        <div v-else class="flex items-center gap-4">
                            {{
                                itemsDetail.find((data) => data.id === item.id)
                                    ?.approverlvl1_qty ?? 0
                            }}
                            <Edit
                                v-if="hasAccess('edit wastage approval level 1')"
                                class="size-4 text-blue-500 cursor-pointer hover:text-blue-600"
                                @click="startEdit(item.id)"
                            />
                            <Trash2
                                v-if="hasAccess('delete wastage approval level 1')"
                                class="size-4 text-red-500 cursor-pointer hover:text-red-600"
                                @click="deleteItem(item.id)"
                            />
                        </div>
                    </TD>
                        <TD v-else>
                            {{ item.approverlvl1_qty }}
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="item in wastage.items" :key="item.id">
                    <MobileTableHeading
                        :title="`${item.sap_masterfile?.ItemDescription || 'N/A'} (${item.sap_masterfile?.ItemCode || 'N/A'})`"
                    >
                        <div v-if="wastage.wastage_status === 'pending' && hasAccess('edit wastage approval level 1')">
                            <div v-if="editingItem && editingItem.id === item.id">
                                <Input
                                    v-focus-select
                                    type="number"
                                    v-model="editValue"
                                    class="w-20 text-right"
                                    @keyup.enter="saveEdit"
                                    @keyup.esc="cancelEdit"
                                />
                                <DivFlexCenter class="gap-1 ml-2">
                                    <Save class="size-4 text-green-500 cursor-pointer hover:text-green-600" @click="saveEdit" />
                                    <X class="size-4 text-red-500 cursor-pointer hover:text-red-600" @click="cancelEdit" />
                                </DivFlexCenter>
                            </div>
                            <div v-else class="flex items-center gap-2">
                                <Edit
                                    class="size-4 text-blue-500 cursor-pointer hover:text-blue-600"
                                    @click="startEdit(item.id)"
                                />
                            </div>
                        </div>
                    </MobileTableHeading>
                    <LabelXS>Reason: {{ item.reason || 'No reason specified' }}</LabelXS>
                    <LabelXS>UOM: {{ (item.sap_masterfile?.AltUOM || item.sap_masterfile?.BaseUOM) ?? "N/A" }}</LabelXS>
                    <LabelXS>Wastage: {{ item.wastage_qty }}</LabelXS>
                    <LabelXS>
                        Approved: {{
                            itemsDetail.find((data) => data.id === item.id)
                                ?.approverlvl1_qty ?? 0
                        }}
                    </LabelXS>
                    <div v-if="wastage.wastage_status === 'pending'" class="flex justify-end mt-2">
                        <Trash2
                            v-if="hasAccess('delete wastage approval level 1')"
                            class="size-4 text-red-500 cursor-pointer hover:text-red-600"
                            @click="deleteItem(item.id)"
                        />
                    </div>
                </MobileTableRow>
            </MobileTableContainer>
        </TableContainer>

        <!-- Attached Images -->
        <div v-if="hasImages" class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-4 sm:px-6 py-4 bg-gradient-to-r from-blue-50 to-blue-100 border-b border-blue-200">
                <div class="flex items-center gap-3">
                    <Paperclip class="w-5 h-5 text-blue-600" />
                    <h3 class="text-lg font-semibold text-gray-900">Attached Images</h3>
                </div>
            </div>
            <div class="p-4 sm:p-6">
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                    <div
                      v-for="(image, index) in allImages"
                      :key="image.id"
                      class="relative group cursor-pointer"
                      @click="handleImageClick(image, index)"
                      @keydown.enter="handleImageClick(image, index)"
                      @keydown.space.prevent="handleImageClick(image, index)"
                      tabindex="0"
                      role="button"
                      :aria-label="`View image ${index + 1} in new tab`"
                      :class="{'cursor-zoom-in': !imageLoadingStates[image.id] && !imageErrors[image.id]}"
                    >
                      <div class="aspect-w-1 aspect-h-1">
                        <!-- Loading State -->
                        <div v-if="imageLoadingStates[image.id]" class="absolute inset-0 bg-gray-100 rounded-lg flex items-center justify-center">
                          <Loader2 class="w-8 h-8 text-blue-600 animate-spin" />
                        </div>

                        <!-- Error State -->
                        <div v-else-if="imageErrors[image.id]" class="absolute inset-0 bg-red-50 rounded-lg flex flex-col items-center justify-center p-4">
                          <AlertCircle class="w-8 h-8 text-red-500 mb-2" />
                          <p class="text-xs text-red-700 text-center mb-2">{{ imageErrors[image.id] }}</p>

                          <!-- Show attempt info for Google Drive images -->
                          <p v-if="image.urlInfo && image.urlInfo.isGoogleDrive" class="text-xs text-gray-600 mb-2">
                            Tried {{ image.urlInfo.totalFormats }} URL formats
                          </p>

                          <div class="flex gap-2">
                            <button
                              @click.stop="retryWithNextUrl(image)"
                              class="text-xs text-blue-600 hover:text-blue-800 underline"
                            >
                              {{ image.urlInfo && image.urlInfo.isGoogleDrive && image.urlInfo.hasMoreFallbacks ? 'Try Next URL' : 'Retry' }}
                            </button>
                            <span v-if="image.urlInfo && image.urlInfo.isGoogleDrive && image.urlInfo.hasMoreFallbacks" class="text-xs text-gray-500">
                              ({{ image.urlInfo.totalFormats - (image.attemptIndex + 1) }} left)
                            </span>
                          </div>
                        </div>

                        <!-- Image -->
                        <img
                          v-else
                          :src="image.url"
                          :alt="`Wastage Image ${index + 1}`"
                          class="object-cover shadow-lg rounded-lg w-full h-full hover:opacity-90 transition-opacity"
                          @load="() => handleImageLoad(image.id)"
                          @error="() => handleImageError(image.id, image)"
                          @loadstart="() => initializeImageLoading(image)"
                        />
                      </div>

                      <!-- Debug Info (only in development) -->
                      <div v-if="isDevelopment" class="absolute bottom-1 left-1 bg-black bg-opacity-75 text-white text-xs p-1 rounded max-w-full truncate pointer-events-none">
                        {{ image.type === 'existing' ? 'Existing' : 'New' }}
                        <span v-if="image.type === 'existing'" class="block text-yellow-300">ID: {{ image.id.substring(0, 10) }}...</span>
                      </div>

                      <!-- Click to zoom indicator (only shown when image is not loading or in error state) -->
                      <div
                        v-if="!imageLoadingStates[image.id] && !imageErrors[image.id]"
                        class="absolute top-1 left-1 bg-black bg-opacity-50 text-white text-xs px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none"
                      >
                        Click to view
                      </div>
                    </div>
                </div>
            </div>
        </div>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>

<style scoped>
.aspect-w-1 {
  position: relative;
  width: 100%;
  padding-bottom: 100%;
}
.aspect-h-1 > * {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
}
.object-cover {
  object-fit: cover;
}

/* Enhanced cursor and hover effects for clickable images */
.cursor-pointer {
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.cursor-pointer:hover {
  transform: scale(1.02);
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
}

.cursor-zoom-in {
  cursor: zoom-in;
}

/* Loading state overlay with click prevention */
.pointer-events-none {
  pointer-events: none;
}

.pointer-events-auto {
  pointer-events: auto;
}

/* Image hover effect */
.transition-opacity {
  transition: opacity 0.2s ease;
}

/* Accessibility: focus styles for keyboard navigation */
.cursor-pointer:focus {
  outline: 2px solid #3B82F6;
  outline-offset: 2px;
  border-radius: 0.375rem;
}

/* Ensure the zoom indicator doesn't interfere with interactions */
.group:hover .group-hover\:opacity-100 {
  opacity: 1;
}

/* Smooth color transitions */
.transition-colors {
  transition-property: color, background-color, border-color;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
  transition-duration: 150ms;
}
</style>