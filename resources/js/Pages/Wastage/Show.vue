<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { ArrowLeft, Package, Calendar, User, CheckCircle, AlertTriangle, Paperclip, Loader2, AlertCircle, ImageIcon } from 'lucide-vue-next'
import WastageStatusBadge from './components/WastageStatusBadge.vue'

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
const canViewCost = computed(() => props.permissions?.can_view_cost)

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

// --- Start of Image logic ---

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

// --- End of Image logic ---

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
            <div class="flex justify-between items-center" v-if="canViewCost">
              <span class="text-gray-600">Total Cost</span>
              <span class="text-2xl font-bold text-green-600">{{ formatCurrency(totalCost) }}</span>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Items Table -->
      <Card>
        <CardHeader>
          <CardTitle>Wastage Items</CardTitle>
        </CardHeader>
        <CardContent class="p-0 sm:p-6">
          <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
              <thead class="text-xs text-gray-700 uppercase bg-gray-50 border-b border-gray-200">
                <tr>
                  <th scope="col" class="px-4 py-3 font-semibold">Item</th>
                  <th scope="col" class="px-4 py-3 font-semibold">Reason</th>
                  <th scope="col" class="px-4 py-3 font-semibold">Evidence</th>
                  <th scope="col" class="px-4 py-3 font-semibold text-center">UOM</th>
                  <th scope="col" class="px-4 py-3 font-semibold text-center">Wastage Qty</th>
                  <th scope="col" class="px-4 py-3 font-semibold text-center">Lvl1 Appr</th>
                  <th scope="col" class="px-4 py-3 font-semibold text-center">Lvl2 Appr</th>
                  <th v-if="canViewCost" scope="col" class="px-4 py-3 font-semibold text-right">Cost</th>
                  <th v-if="canViewCost" scope="col" class="px-4 py-3 font-semibold text-right">Total</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-200">
                <tr v-if="wastage.items && wastage.items.length > 0" v-for="item in wastage.items" :key="item.id" class="bg-white hover:bg-gray-50">
                  <td class="px-4 py-4">
                    <div class="font-medium text-gray-900">{{ item.sap_masterfile?.ItemCode || 'N/A' }}</div>
                    <div class="text-xs text-gray-500 line-clamp-1 max-w-[200px]">{{ item.sap_masterfile?.ItemDescription || 'No description' }}</div>
                  </td>
                  <td class="px-4 py-4 text-gray-600">{{ item.reason || 'N/A' }}</td>
                  <td class="px-4 py-4">
                    <div class="flex flex-wrap gap-2">
                      <template v-if="getItemImages(item).length > 0" v-for="image in getItemImages(item)" :key="image.id">
                        <div 
                          class="inline-block h-8 w-8 rounded cursor-pointer relative flex-shrink-0"
                          @click="handleImageClick(image)"
                        >
                          <div v-if="imageLoadingStates[image.id]" class="absolute inset-0 bg-gray-100 rounded-full flex items-center justify-center">
                            <Loader2 class="w-3 h-3 text-blue-600 animate-spin" />
                          </div>
                          <img
                            v-else
                            :src="image.url"
                            class="h-8 w-8 rounded-full object-cover border border-gray-100"
                            @load="() => handleImageLoad(image.id)"
                            @error="() => handleImageError(image.id, image)"
                            @loadstart="() => initializeImageLoading(image)"
                          />
                        </div>
                      </template>
                      <span v-else class="text-[10px] text-gray-400 italic">None</span>
                    </div>
                  </td>
                  <td class="px-4 py-4 text-center">{{ (item.sap_masterfile?.AltUOM || item.sap_masterfile?.BaseUOM) ?? "N/A" }}</td>
                  <td class="px-4 py-4 text-center font-semibold">{{ formatQty(item.wastage_qty) }}</td>
                  <td class="px-4 py-4 text-center text-gray-600">{{ formatQty(item.approverlvl1_qty) }}</td>
                  <td class="px-4 py-4 text-center text-gray-600">{{ formatQty(item.approverlvl2_qty) }}</td>
                  <td v-if="canViewCost" class="px-4 py-4 text-right text-xs text-gray-500">{{ formatCurrency(item.cost) }}</td>
                  <td v-if="canViewCost" class="px-4 py-4 text-right font-bold text-gray-900">{{ formatCurrency(item.wastage_qty * item.cost) }}</td>
                </tr>
                <tr v-else>
                  <td :colspan="canViewCost ? 9 : 7" class="px-4 py-8 text-center text-gray-500">
                    No items found
                  </td>
                </tr>
              </tbody>
              <tfoot v-if="wastage.items && wastage.items.length > 0 && canViewCost" class="bg-gray-50 font-bold border-t border-gray-200">
                <tr>
                  <td colspan="8" class="px-4 py-3 text-right">Total Cost:</td>
                  <td class="px-4 py-3 text-right text-green-600 text-base">{{ formatCurrency(totalCost) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
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
.object-cover {
  object-fit: cover;
}

.cursor-pointer {
  transition: transform 0.2s ease;
}

.cursor-pointer:hover {
  transform: scale(1.1);
  z-index: 10;
}

.line-clamp-1 {
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>