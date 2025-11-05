<script setup>
import { computed } from 'vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import TransferStatusBadge from './TransferStatusBadge.vue'
import { ArrowLeftRight, PackageOpen, Package } from 'lucide-vue-next'

const props = defineProps({
  transfer: {
    type: Object,
    required: true
  },
  compact: {
    type: Boolean,
    default: false
  }
})

const transferNumber = computed(() => props.transfer.interco_number || 'N/A')
const status = computed(() => props.transfer.interco_status || 'open')
const fromStoreName = computed(() => {
  // Debug: Log the available data
  console.log('TransferSummary transfer data:', {
    from_store_name: props.transfer.from_store_name,
    sendingStore: props.transfer.sendingStore,
    has_serialized_name: !!props.transfer.from_store_name
  })

  // Use the serialized attribute first, then fallback to relationship
  return props.transfer.from_store_name ||
         props.transfer.sendingStore?.name ||
         props.transfer.sendingStore?.branch_name ||
         props.transfer.sendingStore?.brand_name ||
         'N/A'
})
const toStoreName = computed(() => props.transfer.store_branch?.name || 'N/A')

const totalItems = computed(() => {
  if (!props.transfer.store_order_items) return 0
  return props.transfer.store_order_items.length
})

const totalQuantity = computed(() => {
  if (!props.transfer.store_order_items) return '0'
  const total = props.transfer.store_order_items.reduce((total, item) => {
    const qty = Number(item.quantity_ordered) || 0
    return total + qty
  }, 0)
  return new Intl.NumberFormat('en-PH').format(total)
})

const receivedItems = computed(() => {
  if (!props.transfer.store_order_items) return '0'
  const total = props.transfer.store_order_items.reduce((total, item) => {
    const qty = Number(item.quantity_received) || 0
    return total + qty
  }, 0)
  return new Intl.NumberFormat('en-PH').format(total)
})

const progressPercentage = computed(() => {
  if (!props.transfer.store_order_items) return 0

  const totalQty = props.transfer.store_order_items.reduce((total, item) => {
    return total + (Number(item.quantity_ordered) || 0)
  }, 0)

  const receivedQty = props.transfer.store_order_items.reduce((total, item) => {
    return total + (Number(item.quantity_received) || 0)
  }, 0)

  if (totalQty === 0) return 0
  return Math.round((receivedQty / totalQty) * 100)
})

const borderColor = computed(() => {
  const colors = {
    open: 'border-gray-300',
    approved: 'border-blue-300',
    committed: 'border-yellow-300',
    in_transit: 'border-purple-300',
    received: 'border-green-300',
    disapproved: 'border-red-300'
  }
  return colors[status.value] || colors.open
})

const iconBg = computed(() => {
  const colors = {
    open: 'bg-gray-100',
    approved: 'bg-blue-100',
    committed: 'bg-yellow-100',
    in_transit: 'bg-purple-100',
    received: 'bg-green-100',
    disapproved: 'bg-red-100'
  }
  return colors[status.value] || colors.open
})

const iconColor = computed(() => {
  const colors = {
    open: 'text-gray-600',
    approved: 'text-blue-600',
    committed: 'text-yellow-600',
    in_transit: 'text-purple-600',
    received: 'text-green-600',
    disapproved: 'text-red-600'
  }
  return colors[status.value] || colors.open
})

const progressColor = computed(() => {
  if (progressPercentage.value === 100) return 'bg-green-500'
  if (progressPercentage.value >= 75) return 'bg-blue-500'
  if (progressPercentage.value >= 50) return 'bg-yellow-500'
  if (progressPercentage.value >= 25) return 'bg-orange-500'
  return 'bg-red-500'
})

const formattedDate = computed(() => {
  if (!props.transfer.order_date) return 'N/A'
  return new Date(props.transfer.order_date).toLocaleDateString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
})
</script>

<template>
  <Card class="border-l-4" :class="borderColor">
    <CardContent :class="compact ? 'p-4' : 'p-6'">
      <!-- Header Section -->
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
          <div class="p-2 rounded-lg" :class="iconBg">
            <ArrowLeftRight class="w-5 h-5" :class="iconColor" />
          </div>
          <div>
            <h3 :class="compact ? 'font-semibold' : 'font-semibold text-lg'">Transfer Summary</h3>
            <p class="text-sm text-muted-foreground">{{ transferNumber }}</p>
          </div>
        </div>
        <TransferStatusBadge :status="status" />
      </div>

      <!-- Store Information -->
      <div :class="compact ? 'grid grid-cols-2 gap-4 mb-4' : 'grid grid-cols-2 gap-6 mb-6'">
        <div class="flex items-center gap-3">
          <div :class="compact ? 'w-8 h-8' : 'w-10 h-10'" class="rounded-full bg-red-100 flex items-center justify-center">
            <PackageOpen :class="compact ? 'w-4 h-4' : 'w-5 h-5'" class="text-red-600" />
          </div>
          <div>
            <p class="text-xs text-muted-foreground">From</p>
            <p class="font-medium text-sm">{{ fromStoreName }}</p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <div :class="compact ? 'w-8 h-8' : 'w-10 h-10'" class="rounded-full bg-green-100 flex items-center justify-center">
            <Package :class="compact ? 'w-4 h-4' : 'w-5 h-5'" class="text-green-600" />
          </div>
          <div>
            <p class="text-xs text-muted-foreground">To</p>
            <p class="font-medium text-sm">{{ toStoreName }}</p>
          </div>
        </div>
      </div>

      <!-- Statistics Grid -->
      <div class="grid grid-cols-3 gap-2 md:gap-4 mb-4">
        <div class="text-center p-2 md:p-3 rounded-lg bg-muted/50">
          <p :class="compact ? 'text-lg' : 'text-2xl'" class="font-bold text-primary">{{ totalItems }}</p>
          <p class="text-xs text-muted-foreground">Total Items</p>
        </div>
        <div class="text-center p-2 md:p-3 rounded-lg bg-muted/50">
          <p :class="compact ? 'text-lg' : 'text-2xl'" class="font-bold text-green-600">{{ totalQuantity }}</p>
          <p class="text-xs text-muted-foreground">Total Quantity</p>
        </div>
        <div class="text-center p-2 md:p-3 rounded-lg bg-muted/50">
          <p :class="compact ? 'text-lg' : 'text-2xl'" class="font-bold text-blue-600">{{ receivedItems }}</p>
          <p class="text-xs text-muted-foreground">Received</p>
        </div>
      </div>

      <!-- Progress Bar -->
      <div v-if="!compact" class="mt-4">
        <div class="flex items-center justify-between mb-2">
          <span class="text-sm font-medium">Transfer Progress</span>
          <span class="text-sm text-muted-foreground">{{ progressPercentage }}%</span>
        </div>
        <div class="w-full bg-secondary rounded-full h-2">
          <div
            class="h-2 rounded-full transition-all duration-500"
            :class="progressColor"
            :style="{ width: `${progressPercentage}%` }"
          ></div>
        </div>
      </div>

      <!-- Additional Info -->
      <div v-if="compact" class="mt-4 pt-4 border-t">
        <div class="flex justify-between items-center text-xs text-muted-foreground">
          <span>Transfer Date: {{ formattedDate }}</span>
          <Badge variant="secondary" class="text-xs">{{ status }}</Badge>
        </div>
      </div>
    </CardContent>
  </Card>
</template>