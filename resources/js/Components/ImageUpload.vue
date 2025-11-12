<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Upload, X, Image as ImageIcon, AlertCircle, Loader2, ChevronLeft, ChevronRight } from 'lucide-vue-next'

const props = defineProps({
  modelValue: { // Array of new File objects
    type: Array,
    default: () => []
  },
  existingImageUrls: { // Array of existing image URL strings
    type: Array,
    default: () => []
  },
  multiple: {
    type: Boolean,
    default: false
  },
  label: {
    type: String,
    default: 'Upload Image'
  },
  helperText: {
    type: String,
    default: 'Upload JPG or PNG image (max 5MB)'
  },
  disabled: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue', 'update:existingImageUrls', 'error'])

const fileInput = ref(null)
const error = ref('')
const dragOver = ref(false)

// Holds data URLs for new file previews
const newFilePreviews = ref([])

// Track image loading states and errors
const imageLoadingStates = ref({})
const imageErrors = ref({})

// Track URL attempts for fallback mechanism
const urlAttempts = ref({})

// Enhanced Google Drive URL transformation with multiple format support and fallbacks
const transformGoogleDriveUrl = (url, attemptIndex = 0) => {
  console.log('🔗 Transforming URL:', url, 'Attempt:', attemptIndex);

  if (!url) {
    console.warn('⚠️ Empty URL provided to transformGoogleDriveUrl');
    return { url: '', isGoogleDrive: false, hasMoreFallbacks: false };
  }

  // Handle multiple Google Drive URL formats
  if (url.includes('drive.google.com')) {
    try {
      let fileId = null;

      // Format 1: https://drive.google.com/file/d/{FILE_ID}/view?usp=drivesdk
      if (url.includes('/file/d/')) {
        const match = url.match(/\/file\/d\/([a-zA-Z0-9_-]+)/);
        if (match) {
          fileId = match[1];
        }
      }
      // Format 2: https://drive.google.com/open?id={FILE_ID}
      else if (url.includes('open?id=')) {
        const match = url.match(/[?&]id=([a-zA-Z0-9_-]+)/);
        if (match) {
          fileId = match[1];
        }
      }

      if (fileId) {
        // Multiple URL formats to try in order of reliability
        // Server-side proxy first (most reliable), then direct URLs
        const urlFormats = [
          `/proxy/google-drive/${fileId}`, // Server-side proxy (most reliable)
          `https://drive.google.com/thumbnail?id=${fileId}&sz=s400`,
          `https://drive.google.com/uc?export=view&id=${fileId}`,
          `https://drive.google.com/uc?export=download&id=${fileId}`,
          `https://docs.google.com/uc?export=view&id=${fileId}`,
          `https://lh3.googleusercontent.com/d/${fileId}=s400`
        ];

        if (attemptIndex < urlFormats.length) {
          const transformedUrl = urlFormats[attemptIndex];
          console.log('✅ Google Drive URL transformed:', {
            original: url,
            transformed: transformedUrl,
            fileId,
            attemptIndex,
            totalFormats: urlFormats.length
          });
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
          console.warn('⚠️ All Google Drive URL formats exhausted for:', url);
          return { url: '', isGoogleDrive: true, hasMoreFallbacks: false, fileId };
        }
      } else {
        console.warn('⚠️ Could not extract file ID from Google Drive URL:', url);
      }
    } catch (error) {
      console.error('❌ Error transforming Google Drive URL:', error, 'URL:', url);
    }
  }

  console.log('ℹ️ URL unchanged (not a Google Drive URL):', url);
  return { url, isGoogleDrive: false, hasMoreFallbacks: false };
};

const allImages = computed(() => {
  console.log('🖼️ Computing allImages. Existing URLs:', props.existingImageUrls, 'New previews:', newFilePreviews.value);
  console.log('🔢 Current urlAttempts state:', JSON.parse(JSON.stringify(urlAttempts.value)));

  const existing = props.existingImageUrls.map(originalUrl => {
    // Initialize URL attempts tracking ONLY if not already set
    // This prevents resetting fallback attempts
    if (urlAttempts.value[originalUrl] === undefined) {
      console.log('🆕 Initializing urlAttempts for:', originalUrl.substring(0, 50) + '...');
      urlAttempts.value[originalUrl] = 0;
    } else {
      console.log('♻️ Preserving urlAttempts for:', originalUrl.substring(0, 50) + '...', 'attempts:', urlAttempts.value[originalUrl]);
    }

    const currentAttempt = urlAttempts.value[originalUrl];
    const urlInfo = transformGoogleDriveUrl(originalUrl, currentAttempt);

    const imageObject = {
      type: 'existing',
      url: urlInfo.url,
      id: originalUrl,
      originalUrl,
      urlInfo,
      attemptIndex: currentAttempt
    };

    console.log('📎 Created image object:', {
      originalUrl: originalUrl.substring(0, 50) + '...',
      attemptIndex: currentAttempt,
      finalUrl: urlInfo.url,
      hasMoreFallbacks: urlInfo.hasMoreFallbacks
    });

    return imageObject;
  });

  const newFiles = newFilePreviews.value.map(preview => ({ type: 'new', ...preview }));

  const allImagesResult = [...existing, ...newFiles];
  console.log('📊 Final allImages result:', allImagesResult.length, 'images');

  return allImagesResult;
})

const hasImages = computed(() => allImages.value.length > 0)

// Development mode detection (browser-safe)
const isDevelopment = computed(() => {
  try {
    return import.meta.env?.DEV || false
  } catch (error) {
    // Fallback for environments where import.meta.env is not available
    console.warn('Could not determine development mode:', error)
    return false
  }
})

// Image loading event handlers with fallback support
const handleImageLoad = (imageId) => {
  console.log('✅ Image loaded successfully:', imageId);
  imageLoadingStates.value[imageId] = false;
  imageErrors.value[imageId] = null;

  // Reset URL attempts on successful load
  console.log('🔄 Resetting urlAttempts for image:', imageId);
  urlAttempts.value[imageId] = 0;
};

const handleImageError = (imageId, image) => {
  console.error('❌ Image failed to load:', { imageId, image });

  // Try fallback URLs for Google Drive images
  if (image.urlInfo && image.urlInfo.isGoogleDrive && image.urlInfo.hasMoreFallbacks) {
    const nextAttempt = (urlAttempts.value[imageId] || 0) + 1;
    urlAttempts.value[imageId] = nextAttempt;

    console.log('🔄 Trying fallback URL for Google Drive image:', {
      imageId,
      nextAttempt,
      totalFormats: image.urlInfo.totalFormats
    });

    // Trigger reactivity update by forcing computed property to recompute
    // This will cause the template to use the new URL
    return; // Don't set error state yet, try fallback first
  }

  // No more fallbacks or not a Google Drive image
  imageLoadingStates.value[imageId] = false;

  if (image.urlInfo && image.urlInfo.isGoogleDrive) {
    imageErrors.value[imageId] = `Failed to load image after trying ${image.urlInfo.totalFormats} different URL formats. The Google Drive file may not be publicly accessible or may have been deleted.`;
  } else {
    imageErrors.value[imageId] = 'Failed to load image. The URL may be invalid or the image may not be accessible.';
  }

  console.error('❌ All URL attempts exhausted for image:', imageId);
};

const initializeImageLoading = (image) => {
  const imageId = image.id;
  imageLoadingStates.value[imageId] = true;
  imageErrors.value[imageId] = null;
  console.log('🔄 Starting image load:', imageId, 'URL:', image.url, 'Attempt:', image.attemptIndex);
};

const retryWithNextUrl = (image) => {
  console.log('🔄 Manual retry triggered for image:', image.id);

  if (image.urlInfo && image.urlInfo.isGoogleDrive && image.urlInfo.hasMoreFallbacks) {
    urlAttempts.value[image.id] = (urlAttempts.value[image.id] || 0) + 1;
    imageErrors.value[image.id] = null;
    imageLoadingStates.value[image.id] = true;
  } else {
    // Reset to first attempt and try again
    urlAttempts.value[image.id] = 0;
    imageErrors.value[image.id] = null;
    imageLoadingStates.value[image.id] = true;
  }
};


// Add URL validation function
const isValidImageUrl = (url) => {
  if (!url) return false;
  if (typeof url !== 'string') return false;

  // Check for valid URL patterns
  const validPatterns = [
    /^https?:\/\//,           // http/https URLs
    /^data:image\//,          // Data URLs for images
    /^\/proxy\/google-drive\// // Our proxy URLs
  ];

  return validPatterns.some(pattern => pattern.test(url));
};

// Handle image click to open in new tab
const handleImageClick = (image, index) => {
  // Skip if image is loading or has errors
  if (imageLoadingStates.value[image.id] || imageErrors.value[image.id]) {
    console.log('🚫 Image click ignored - image is loading or has errors:', image.id);
    return;
  }

  // Open image in new tab
  console.log('🔗 Opening image in new tab:', image.url);
  window.open(image.url, '_blank');
};

const openFileDialog = () => {
  if (props.disabled) return
  fileInput.value?.click()
}

const handleFileSelect = (event) => {
  const files = event.target.files
  if (files) {
    processFiles(Array.from(files))
  }
}

const handleDrop = (event) => {
  event.preventDefault()
  dragOver.value = false
  if (props.disabled) return
  const files = event.dataTransfer.files
  if (files) {
    processFiles(Array.from(files))
  }
}

const handleDragOver = (event) => {
  event.preventDefault()
  if (!props.disabled) {
    dragOver.value = true
  }
}

const handleDragLeave = () => {
  dragOver.value = false
}

const processFiles = (files) => {
  error.value = ''
  let newModelValue = props.multiple ? [...props.modelValue] : []

  const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png']
  const maxSize = 5 * 1024 * 1024 // 5MB

  for (const file of files) {
    if (!props.multiple && newModelValue.length > 0) {
        break; // Only one file if not multiple
    }

    // Validate file type
    if (!allowedTypes.includes(file.type)) {
      error.value = 'Invalid file type. Only JPEG and PNG images are allowed.'
      emit('error', error.value)
      continue;
    }

    // Validate file size
    if (file.size > maxSize) {
      error.value = `File ${file.name} is too large. Maximum size is 5MB.`
      emit('error', error.value)
      continue;
    }

    newModelValue.push(file)
  }

  emit('update:modelValue', newModelValue)
}


const removeImage = (image) => {
  if (image.type === 'existing') {
    const updatedUrls = props.existingImageUrls.filter(url => url !== image.id)
    emit('update:existingImageUrls', updatedUrls)
  } else if (image.type === 'new') {
    const updatedFiles = props.modelValue.filter(f => (f.name + f.size) !== image.id)
    // The watcher will automatically update the previews
    emit('update:modelValue', updatedFiles)
  }
}

// Watch for changes in existingImageUrls to handle prop updates
watch(() => props.existingImageUrls, (newUrls, oldUrls) => {
  console.log('🔄 existingImageUrls changed:', { newUrls, oldUrls });

  nextTick(() => {
    // Reset loading states for removed images
    const currentImageIds = new Set(allImages.value.map(img => img.id));
    Object.keys(imageLoadingStates.value).forEach(id => {
      if (!currentImageIds.has(id)) {
        delete imageLoadingStates.value[id];
        delete imageErrors.value[id];
      }
    });

    console.log('🔄 Updated loading states after existingImageUrls change');
  });
}, { deep: true, immediate: true });

// Watch to create initial previews for modelValue
watch(() => props.modelValue, (newFiles) => {
  if (!newFiles || newFiles.length === 0) {
    newFilePreviews.value = []
    return
  }

  // Always rebuild previews to ensure consistency
  newFilePreviews.value = []
  for (const file of newFiles) {
    const reader = new FileReader()
    reader.onload = (e) => {
      newFilePreviews.value.push({ url: e.target.result, file, id: file.name + file.size })
    }
    reader.readAsDataURL(file)
  }
}, { deep: true, immediate: true })


</script>

<template>
  <div class="space-y-3">
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">{{ label }}</label>
      <p v-if="helperText" class="text-xs text-gray-500">{{ helperText }}</p>
    </div>

    <!-- Previews for existing and new images -->
    <div v-if="hasImages" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
      <div
        v-for="(image, index) in allImages"
        :key="image.id"
        class="relative group cursor-pointer"
        @click="handleImageClick(image, index)"
        @keydown.enter="handleImageClick(image, index)"
        @keydown.space.prevent="handleImageClick(image, index)"
        tabindex="0"
        role="button"
        :aria-label="image.type === 'new' ? `View ${image.file.name} in modal viewer` : 'View image in modal viewer'"
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
                @click="retryWithNextUrl(image)"
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
            :alt="image.type === 'new' ? `${image.file.name} - Click to view in modal` : 'Existing image - Click to view in modal'"
            class="object-cover shadow-lg rounded-lg w-full h-full hover:opacity-90 transition-opacity"
            @load="() => handleImageLoad(image.id)"
            @error="() => handleImageError(image.id, image.url)"
            @loadstart="() => initializeImageLoading(image)"
          />
        </div>

        <!-- Remove Button -->
        <div class="absolute top-1 right-1 pointer-events-none">
          <Button
            variant="destructive"
            size="icon"
            class="h-7 w-7 opacity-50 group-hover:opacity-100 transition-opacity pointer-events-auto"
            @click.stop="removeImage(image)"
            :disabled="disabled"
            :aria-label="image.type === 'new' ? `Remove ${image.file.name}` : 'Remove image'"
          >
            <X class="w-4 h-4" />
          </Button>
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

    <!-- Upload Area -->
    <div
      v-if="multiple || !hasImages"
      class="relative"
      @click="openFileDialog"
      @drop="handleDrop"
      @dragover="handleDragOver"
      @dragleave="handleDragLeave"
    >
      <div
        :class="[
          'border-2 border-dashed rounded-lg p-8 text-center cursor-pointer transition-colors',
          dragOver ? 'border-blue-400 bg-blue-50' : 'border-gray-300 hover:border-gray-400',
          disabled ? 'cursor-not-allowed opacity-60' : ''
        ]"
      >
        <div class="space-y-3">
          <div class="mx-auto w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center">
            <Upload class="w-6 h-6 text-gray-400" />
          </div>
          <div>
            <p class="text-sm text-gray-600">
              <span class="font-medium text-blue-600 hover:text-blue-700">Click to upload</span>
            </p>
            <p class="text-xs text-gray-500 mt-1">or drag and drop</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Error Alert -->
    <Alert v-if="error" variant="destructive">
      <AlertCircle class="h-4 w-4" />
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <!-- Hidden File Input -->
    <input
      ref="fileInput"
      type="file"
      :accept="['image/jpeg', 'image/jpg', 'image/png'].join(',')"
      class="hidden"
      @change="handleFileSelect"
      :disabled="disabled"
      :multiple="multiple"
    />

      </div>
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
