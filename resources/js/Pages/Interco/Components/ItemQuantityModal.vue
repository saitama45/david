<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { X, Plus, Minus, Package, Eye, AlertCircle } from 'lucide-vue-next'
import StockIndicator from './StockIndicator.vue'

const props = defineProps({
  item: {
    type: Object,
    required: true
  },
  currentQuantity: {
    type: Number,
    default: 1
  },
  isOpen: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['save', 'close', 'view-item'])

const quantity = ref(props.currentQuantity)
const localIsOpen = ref(props.isOpen)

// Watch for prop changes
watch(() => props.isOpen, (newValue) => {
  localIsOpen.value = newValue
  if (newValue) {
    quantity.value = props.currentQuantity
  }
})

watch(() => props.currentQuantity, (newValue) => {
  quantity.value = newValue
})

// Computed properties
const maxQuantity = computed(() => props.item?.stock || 0)
const totalValue = computed(() => {
  const value = quantity.value * (props.item?.cost_per_quantity || 0)
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP'
  }).format(value)
})

const quantityPresets = computed(() => {
  const presets = [1, 5, 10, 25, 50, 100]
  return presets.filter(preset => preset <= maxQuantity.value)
})

const validationMessage = computed(() => {
  if (quantity.value > maxQuantity.value) {
    return `Insufficient stock. Only ${maxQuantity.value} units available.`
  }
  if (quantity.value < 1) {
    return 'Quantity must be at least 1.'
  }
  if (!Number.isInteger(quantity.value)) {
    return 'Quantity must be a whole number.'
  }
  return null
})

const validationType = computed(() => {
  if (quantity.value > maxQuantity.value || quantity.value < 1) {
    return 'destructive'
  }
  return 'default'
})

const isValid = computed(() => {
  return quantity.value >= 1 &&
         quantity.value <= maxQuantity.value &&
         Number.isInteger(quantity.value)
})

// Methods
const incrementQuantity = () => {
  if (quantity.value < maxQuantity.value) {
    quantity.value++
  }
}

const decrementQuantity = () => {
  if (quantity.value > 1) {
    quantity.value--
  }
}

const setQuantity = (value) => {
  quantity.value = value
}

const validateQuantity = () => {
  // Ensure quantity is an integer
  if (!Number.isInteger(quantity.value)) {
    quantity.value = Math.floor(quantity.value)
  }

  // Ensure quantity is within bounds
  if (quantity.value < 1) {
    quantity.value = 1
  } else if (quantity.value > maxQuantity.value) {
    quantity.value = maxQuantity.value
  }
}

const saveChanges = () => {
  if (isValid.value) {
    emit('save', {
      item: props.item,
      quantity: quantity.value
    })
    closeModal()
  }
}

const closeModal = () => {
  localIsOpen.value = false
  emit('close')
}

const viewItemDetails = () => {
  emit('view-item', props.item)
}

// Handle keyboard events
const handleKeydown = (event) => {
  if (event.key === 'Enter' && isValid.value) {
    saveChanges()
  } else if (event.key === 'Escape') {
    closeModal()
  }
}

// Add keyboard event listener
watch(localIsOpen, (newValue) => {
  if (newValue) {
    nextTick(() => {
      document.addEventListener('keydown', handleKeydown)
    })
  } else {
    document.removeEventListener('keydown', handleKeydown)
  }
})
</script>

<template>
  <Dialog v-model:open="localIsOpen">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2">
          <Package class="w-5 h-5" />
          Edit Item Quantity
        </DialogTitle>
        <DialogDescription>
          Update the transfer quantity for {{ item?.description }}
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-6">
        <!-- Item Information -->
        <div class="flex items-center gap-4 p-4 bg-muted/50 rounded-lg border">
          <div class="w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center flex-shrink-0">
            <Package class="w-6 h-6 text-primary" />
          </div>
          <div class="flex-1 min-w-0">
            <h4 class="font-medium truncate">{{ item?.description }}</h4>
            <p class="text-sm text-muted-foreground">{{ item?.item_code }}</p>
            <div class="flex items-center gap-4 mt-2">
              <StockIndicator
                :available="item?.stock || 0"
                :requested="currentQuantity"
                size="small"
              />
              <div class="flex items-center gap-2">
                <Badge variant="secondary">₱{{ (item?.cost_per_quantity || 0).toFixed(2) }}</Badge>
                <Badge v-if="item?.uom" variant="outline">{{ item?.uom }}</Badge>
              </div>
            </div>
          </div>
          <Button size="sm" variant="outline" @click="viewItemDetails" title="View Details">
            <Eye class="w-4 h-4" />
          </Button>
        </div>

        <!-- Quantity Input Section -->
        <div class="space-y-4">
          <Label for="quantity" class="text-base font-medium">Transfer Quantity</Label>

          <!-- Main Quantity Controls -->
          <div class="flex items-center gap-3">
            <Button
              variant="outline"
              size="lg"
              @click="decrementQuantity"
              :disabled="quantity <= 1"
              class="h-12 w-12"
            >
              <Minus class="w-4 h-4" />
            </Button>

            <Input
              id="quantity"
              v-model.number="quantity"
              type="number"
              min="1"
              :max="maxQuantity"
              class="text-center text-lg font-semibold h-12"
              @input="validateQuantity"
            />

            <Button
              variant="outline"
              size="lg"
              @click="incrementQuantity"
              :disabled="quantity >= maxQuantity"
              class="h-12 w-12"
            >
              <Plus class="w-4 h-4" />
            </Button>
          </div>

          <!-- Quick Quantity Buttons -->
          <div class="space-y-2">
            <Label class="text-sm text-muted-foreground">Quick Select</Label>
            <div class="flex gap-2 flex-wrap">
              <Button
                v-for="preset in quantityPresets"
                :key="preset"
                size="sm"
                :variant="quantity === preset ? 'default' : 'outline'"
                @click="setQuantity(preset)"
                :disabled="preset > maxQuantity"
              >
                {{ preset }}
              </Button>
              <Button
                v-if="maxQuantity > 100"
                size="sm"
                :variant="quantity === maxQuantity ? 'default' : 'outline'"
                @click="setQuantity(maxQuantity)"
                class="text-xs"
              >
                Max ({{ maxQuantity }})
              </Button>
            </div>
          </div>
        </div>

        <!-- Validation Alert -->
        <Alert v-if="validationMessage" :variant="validationType" class="border-l-4">
          <AlertCircle class="w-4 h-4" />
          <AlertDescription>{{ validationMessage }}</AlertDescription>
        </Alert>

        <!-- Stock Information -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
          <div class="flex items-center justify-between">
            <div>
              <h4 class="font-medium text-blue-900">Stock Information</h4>
              <p class="text-sm text-blue-700 mt-1">
                Available: {{ maxQuantity }} units
              </p>
            </div>
            <div class="text-right">
              <p class="text-sm text-blue-600">Requested</p>
              <p class="text-lg font-bold text-blue-900">{{ quantity }}</p>
            </div>
          </div>
          <div v-if="quantity > 0" class="mt-3 pt-3 border-t border-blue-200">
            <div class="flex justify-between items-center">
              <span class="text-sm text-blue-700">Total Value:</span>
              <span class="text-lg font-bold text-blue-900">{{ totalValue }}</span>
            </div>
          </div>
        </div>

        <!-- Additional Information -->
        <div v-if="item?.category || item?.remarks" class="space-y-2">
          <div v-if="item?.category" class="flex items-center gap-2 text-sm">
            <span class="text-muted-foreground">Category:</span>
            <Badge variant="outline">{{ item.category }}</Badge>
          </div>
          <div v-if="item?.remarks" class="text-sm">
            <span class="text-muted-foreground">Notes:</span>
            <p class="mt-1">{{ item.remarks }}</p>
          </div>
        </div>
      </div>

      <DialogFooter>
        <Button variant="outline" @click="closeModal">
          Cancel
        </Button>
        <Button @click="saveChanges" :disabled="!isValid">
          Save Changes
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>

<style scoped>
/* Custom styles for better visual hierarchy */
.quantity-input {
  font-size: 1.125rem;
  font-weight: 600;
}

.quick-presets {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.stock-info {
  background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
}
</style>