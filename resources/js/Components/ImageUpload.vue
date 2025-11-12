<script setup>
import { ref, computed, watch } from 'vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Upload, X, Image as ImageIcon, AlertCircle, Loader2 } from 'lucide-vue-next'

const props = defineProps({
  modelValue: {
    type: [String, File],
    default: null
  },
  existingImageUrl: {
    type: String,
    default: null
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
  },
  showPreview: {
    type: Boolean,
    default: true
  }
})

const emit = defineEmits(['update:modelValue', 'update:existingImageUrl', 'error'])

const fileInput = ref(null)
const isUploading = ref(false)
const uploadProgress = ref(0)
const error = ref('')
const previewUrl = ref('')
const dragOver = ref(false)

const isModelValueFile = computed(() => props.modelValue instanceof File)

// Computed properties
const currentImage = computed(() => {
  if (props.modelValue instanceof File) {
    return previewUrl.value
  }
  return props.modelValue || props.existingImageUrl
})

const hasImage = computed(() => {
  return !!currentImage.value
})

const uploadButtonText = computed(() => {
  if (isUploading.value) return 'Uploading...'
  if (hasImage.value) return 'Change Image'
  return props.label
})

// Methods
const openFileDialog = () => {
  if (props.disabled || isUploading.value) return
  fileInput.value?.click()
}

const handleFileSelect = (event) => {
  const file = event.target.files?.[0]
  if (file) {
    processFile(file)
  }
}

const handleDrop = (event) => {
  event.preventDefault()
  dragOver.value = false

  if (props.disabled || isUploading.value) return

  const file = event.dataTransfer.files?.[0]
  if (file) {
    processFile(file)
  }
}

const handleDragOver = (event) => {
  event.preventDefault()
  if (!props.disabled && !isUploading.value) {
    dragOver.value = true
  }
}

const handleDragLeave = () => {
  dragOver.value = false
}

const processFile = (file) => {
  error.value = ''

  // Validate file type
  const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png']
  if (!allowedTypes.includes(file.type)) {
    error.value = 'Invalid file type. Only JPEG and PNG images are allowed.'
    emit('error', error.value)
    return
  }

  // Validate file size (5MB)
  const maxSize = 5 * 1024 * 1024
  if (file.size > maxSize) {
    error.value = 'File size too large. Maximum size is 5MB.'
    emit('error', error.value)
    return
  }

  // Create preview
  const reader = new FileReader()
  reader.onload = (e) => {
    previewUrl.value = e.target.result
    emit('update:modelValue', file)
  }
  reader.readAsDataURL(file)
}

const removeImage = () => {
  previewUrl.value = ''
  emit('update:modelValue', null)
  emit('update:existingImageUrl', null)
  error.value = ''

  // Reset file input
  if (fileInput.value) {
    fileInput.value.value = ''
  }
}

const triggerFileInput = () => {
  openFileDialog()
}

// Watch for external changes
watch(() => props.modelValue, (newValue) => {
  if (!newValue) {
    previewUrl.value = ''
  }
})

watch(() => props.existingImageUrl, (newValue) => {
  if (newValue) {
    previewUrl.value = ''
  }
})
</script>

<template>
  <div class="space-y-3">
    <!-- Label and Helper Text -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">
        {{ label }}
      </label>
      <p v-if="helperText" class="text-xs text-gray-500">
        {{ helperText }}
      </p>
    </div>

    <!-- Upload Area -->
    <div
      v-if="!hasImage"
      class="relative"
      @click="triggerFileInput"
      @drop="handleDrop"
      @dragover="handleDragOver"
      @dragleave="handleDragLeave"
    >
      <div
        :class="[
          'border-2 border-dashed rounded-lg p-8 text-center cursor-pointer transition-colors',
          dragOver ? 'border-blue-400 bg-blue-50' : 'border-gray-300 hover:border-gray-400',
          (disabled || isUploading) ? 'cursor-not-allowed opacity-60' : ''
        ]"
      >
        <div class="space-y-3">
          <!-- Icon -->
          <div class="mx-auto w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center">
            <Upload v-if="!isUploading" class="w-6 h-6 text-gray-400" />
            <Loader2 v-else class="w-6 h-6 text-blue-600 animate-spin" />
          </div>

          <!-- Text -->
          <div>
            <p class="text-sm text-gray-600">
              <span v-if="!isUploading" class="font-medium text-blue-600 hover:text-blue-700">
                Click to upload
              </span>
              <span v-else>Uploading image...</span>
            </p>
            <p class="text-xs text-gray-500 mt-1">
              or drag and drop
            </p>
          </div>

          <!-- Progress Bar -->
          <div v-if="isUploading" class="w-full bg-gray-200 rounded-full h-2">
            <div
              class="bg-blue-600 h-2 rounded-full transition-all duration-300"
              :style="{ width: uploadProgress + '%' }"
            ></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Image Preview -->
    <div v-if="hasImage && showPreview" class="relative">
      <div class="relative rounded-lg overflow-hidden bg-gray-50 border border-gray-200">
        <img
          :src="currentImage"
          alt="Preview"
          class="w-full h-64 object-contain"
        />

        <!-- Overlay with actions -->
        <div class="absolute inset-0 bg-black bg-opacity-40 flex items-center justify-center opacity-0 hover:opacity-100 transition-opacity">
          <div class="flex space-x-2">
            <Button
              variant="secondary"
              size="sm"
              @click="triggerFileInput"
              :disabled="disabled || isUploading"
            >
              <Upload class="w-4 h-4 mr-2" />
              Change
            </Button>
            <Button
              variant="destructive"
              size="sm"
              @click="removeImage"
              :disabled="disabled || isUploading"
            >
              <X class="w-4 h-4 mr-2" />
              Remove
            </Button>
          </div>
        </div>
      </div>

      <!-- Image info -->
      <div class="mt-2 text-sm text-gray-600">
        <div class="flex items-center">
          <ImageIcon class="w-4 h-4 mr-1" />
          <span v-if="isModelValueFile">
            {{ modelValue.name }} ({{ (modelValue.size / 1024 / 1024).toFixed(2) }} MB)
          </span>
          <span v-else>
            Current image
          </span>
        </div>
      </div>
    </div>

    <!-- Error Alert -->
    <Alert v-if="error" class="border-red-200 bg-red-50">
      <AlertCircle class="h-4 w-4 text-red-600" />
      <AlertDescription class="text-red-800">
        {{ error }}
      </AlertDescription>
    </Alert>

    <!-- Hidden File Input -->
    <input
      ref="fileInput"
      type="file"
      accept="image/jpeg,image/jpg,image/png"
      class="hidden"
      @change="handleFileSelect"
      :disabled="disabled || isUploading"
    />
  </div>
</template>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}

.cursor-not-allowed {
  cursor: not-allowed;
}

@keyframes spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

.animate-spin {
  animation: spin 1s linear infinite;
}
</style>