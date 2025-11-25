<script setup>
import { ref, computed } from 'vue' // Added ref
import { Link, router } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Separator } from '@/components/ui/separator'
import { ArrowLeft, Package, Calendar, User, CheckCircle, AlertTriangle, Paperclip, Loader2, AlertCircle } from 'lucide-vue-next' // Added Loader2, AlertCircle
import WastageStatusBadge from './Components/WastageStatusBadge.vue'

const props = defineProps({
  wastage: Object,
  permissions: Object,
  statusTransitions: Array
})


// Computed properties
const wastageNumber = computed(() => props.wastage.wastage_no || 'N/A')
const status = computed(() => props.wastage.wastage_status || 'pending')
const storeName = computed(() => {
  return props.wastage.storeBranch?.name ||
         props.wastage.storeBranch?.branch_name ||
         'Unknown Store'
})
const createdDate = computed(() => new Date(props.wastage.created_at).toLocaleDateString())
const reason = computed(() => props.wastage.remarks || 'No remarks provided')

// Format date and time
const formatDateTime = (dateString) => {
  if (!dateString) return 'N/A'
  const date = new Date(dateString)

  // Format date: MM/DD/YYYY
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const year = date.getFullYear()

  // Format time: HH:MM A.M./P.M.
  let hours = date.getHours()
  const minutes = String(date.getMinutes()).padStart(2, '0')
  const ampm = hours >= 12 ? 'P.M.' : 'A.M.'
  hours = hours % 12
  hours = hours ? hours : 12 // 0 should be 12

  return `${month}/${day}/${year} ${String(hours).padStart(2, '0')}:${minutes} ${ampm}`
}

// Format user name to show "Firstname Lastname" instead of email
const formatUserName = (user) => {
  if (!user) return 'Unknown User'

  // If user has a proper name field, use it
  if (user.name && user.name !== user.email) {
    return user.name
  }

  // If we have first_name and last_name, combine them
  if (user.first_name || user.last_name) {
    return `${user.first_name || ''} ${user.last_name || ''}`.trim()
  }

  // Try to extract name from email (remove domain and capitalize)
  if (user.email) {
    const emailName = user.email.split('@')[0]
    return emailName.replace(/[._]/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
  }

  return 'Unknown User'
}

// Wastage statistics
const totalItems = computed(() => {
  if (!props.wastage?.items || !Array.isArray(props.wastage.items)) return 0
  const total = props.wastage.items.reduce((total, item) => total + (Number(item.wastage_qty) || 0), 0)
  return Number(total) || 0
})

const totalCost = computed(() => {
  if (!props.wastage?.items || !Array.isArray(props.wastage.items)) return 0
  const total = props.wastage.items.reduce((total, item) => {
    const qty = Number(item.wastage_qty) || 0
    const cost = Number(item.cost) || 0
    return total + (qty * cost)
  }, 0)
  return Number(total) || 0
})

// --- Start of ImageUpload.vue extracted logic ---

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

// --- End of ImageUpload.vue extracted logic ---

// Format currency
const formatCurrency = (amount) => {
  if (!amount) return '₱0.00'
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP'
  }).format(amount)
}

const formatQty = (qty) => {
    if (qty === null || qty === undefined) return '0.000';
    return Number(qty).toFixed(3);
};

</script>

<template>
  <Layout heading="Wastage Record Details">
    <template #header-actions>
      <div class="flex items-center gap-2">
        <Button variant="outline" @click="router.get(route('wastage.index'))">
          <ArrowLeft class="w-4 h-4 mr-2" />
          Back to Wastage Records
        </Button>
      </div>
    </template>

    <!-- Main Content -->
    <div class="space-y-6">
      <!-- Header Card -->
      <Card>
        <CardContent class="p-6">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center gap-4">
              <div class="p-3 bg-blue-100 rounded-lg">
                <Package class="w-6 h-6 text-blue-600" />
              </div>
              <div>
                <h1 class="text-2xl font-bold text-gray-900">Wastage Record #{{ wastageNumber }}</h1>
                <div class="flex items-center gap-2 mt-1">
                  <Calendar class="w-4 h-4 text-gray-500" />
                  <span class="text-sm text-gray-600">Created on {{ createdDate }}</span>
                </div>
              </div>
            </div>
            <WastageStatusBadge :status="status" />
          </div>
        </CardContent>
      </Card>

      <!-- Details Grid -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Store Information -->
        <Card>
          <CardHeader>
            <CardTitle class="flex items-center gap-2">
              <Package class="w-5 h-5" />
              Store Information
            </CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div>
              <label class="text-sm font-medium text-gray-500">Store Branch</label>
              <p class="text-lg font-semibold">{{ storeName }}</p>
            </div>
            <div>
              <label class="text-sm font-medium text-gray-500">Remarks</label>
              <p class="text-gray-900">{{ reason }}</p>
            </div>
          </CardContent>
        </Card>

        <!-- Statistics -->
        <Card>
          <CardHeader>
            <CardTitle class="flex items-center gap-2">
              <Package class="w-5 h-5" />
              Wastage Summary
            </CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="flex justify-between items-center">
              <span class="text-gray-600">Total Items</span>
              <span class="text-2xl font-bold">{{ formatQty(totalItems) }}</span>
            </div>
            <Separator />
            <div class="flex justify-between items-center">
              <span class="text-gray-600">Total Cost</span>
              <span class="text-2xl font-bold text-green-600">{{ formatCurrency(totalCost) }}</span>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Attached Images -->
      <Card v-if="hasImages">
        <CardHeader>
          <CardTitle class="flex items-center gap-2">
            <Paperclip class="w-5 h-5" />
            Attached Images
          </CardTitle>
        </CardHeader>
        <CardContent>
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
        </CardContent>
      </Card>

      <!-- Items Table -->
      <Card>
        <CardHeader>
          <CardTitle>Wastage Items</CardTitle>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Item</TableHead>
                <TableHead>Reason</TableHead>
                <TableHead class="text-center">Quantity</TableHead>
                <TableHead class="text-center">Approved Lvl1 Qty</TableHead>
                <TableHead class="text-center">Approved Lvl2 Qty</TableHead>
                <TableHead class="text-right">Cost</TableHead>
                <TableHead class="text-right">Total</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="wastage.items && wastage.items.length > 0" v-for="item in wastage.items" :key="item.id">
                <TableCell>
                  <div>
                    <div class="font-medium">{{ item.sap_masterfile?.ItemCode || 'N/A' }}</div>
                    <div class="text-sm text-gray-500">{{ item.sap_masterfile?.ItemDescription || 'No description' }}</div>
                  </div>
                </TableCell>
                <TableCell class="text-sm">{{ item.reason || 'No reason specified' }}</TableCell>
                <TableCell class="text-center">{{ formatQty(item.wastage_qty) }} {{ (item.sap_masterfile?.AltUOM || item.sap_masterfile?.BaseUOM) ?? 'PCS' }}</TableCell>
                <TableCell class="text-center">{{ formatQty(item.approverlvl1_qty) }} {{ (item.sap_masterfile?.AltUOM || item.sap_masterfile?.BaseUOM) ?? 'PCS' }}</TableCell>
                <TableCell class="text-center">{{ formatQty(item.approverlvl2_qty) }} {{ (item.sap_masterfile?.AltUOM || item.sap_masterfile?.BaseUOM) ?? 'PCS' }}</TableCell>
                <TableCell class="text-right">{{ formatCurrency(item.cost) }}</TableCell>
                <TableCell class="text-right font-semibold">{{ formatCurrency(item.wastage_qty * item.cost) }}</TableCell>
              </TableRow>
              <TableRow v-else>
                <TableCell colspan="7" class="text-center py-8 text-gray-500">
                  No items found
                </TableCell>
              </TableRow>
            </TableBody>
            <tfoot v-if="wastage.items && wastage.items.length > 0">
              <TableRow>
                <TableCell colspan="6" class="text-right font-bold">Total:</TableCell>
                <TableCell class="text-right font-bold text-green-600">{{ formatCurrency(totalCost) }}</TableCell>
              </TableRow>
            </tfoot>
          </Table>
        </CardContent>
      </Card>

      <!-- Approval Workflow -->
      <Card>
        <CardHeader>
          <CardTitle class="flex items-center gap-2">
            <User class="w-5 h-5" />
            Approval Workflow
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div class="space-y-4">
            <!-- Created By -->
            <div class="flex items-center gap-3">
              <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                <User class="w-4 h-4 text-blue-600" />
              </div>
              <div>
                <div class="font-medium">{{ formatUserName(wastage.encoder) }}</div>
                <div class="text-sm text-gray-500">Created on {{ formatDateTime(wastage.created_at) }}</div>
              </div>
            </div>

            <!-- Level 1 Approval -->
            <div v-if="wastage.approver1" class="flex items-center gap-3">
              <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                <CheckCircle class="w-4 h-4 text-green-600" />
              </div>
              <div>
                <div class="font-medium">{{ formatUserName(wastage.approver1) }}</div>
                <div class="text-sm text-gray-500">Approved Level 1 on {{ formatDateTime(wastage.approved_level1_date) }}</div>
              </div>
            </div>

            <!-- Level 2 Approval -->
            <div v-if="wastage.approver2" class="flex items-center gap-3">
              <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                <CheckCircle class="w-4 h-4 text-green-600" />
              </div>
              <div>
                <div class="font-medium">{{ formatUserName(wastage.approver2) }}</div>
                <div class="text-sm text-gray-500">Approved Level 2 on {{ formatDateTime(wastage.approved_level2_date) }}</div>
              </div>
            </div>

            <!-- Cancelled By -->
            <div v-if="wastage.canceller" class="flex items-center gap-3">
              <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center">
                <AlertTriangle class="w-4 h-4 text-red-600" />
              </div>
              <div>
                <div class="font-medium">{{ formatUserName(wastage.canceller) }}</div>
                <div class="text-sm text-gray-500">Cancelled on {{ formatDateTime(wastage.cancelled_date) }}</div>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
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
