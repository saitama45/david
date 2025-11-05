<script setup>
import { computed } from 'vue'
import { Button } from '@/components/ui/button'
import { Eye, History, CheckCircle, TrendingUp, MinusCircle, AlertTriangle, XCircle } from 'lucide-vue-next'

const props = defineProps({
  available: {
    type: Number,
    required: true,
    default: 0
  },
  requested: {
    type: Number,
    default: 0
  },
  showActions: {
    type: Boolean,
    default: false
  },
  size: {
    type: String,
    default: 'medium',
    validator: (value) => ['small', 'medium', 'large'].includes(value)
  }
})

const emit = defineEmits(['view-stock', 'stock-history'])

const stockPercentage = computed(() => {
  if (!props.requested) return 100
  return Math.min((props.available / props.requested) * 100, 100)
})

const stockLevel = computed(() => {
  if (stockPercentage.value >= 100) return 'excellent'
  if (stockPercentage.value >= 75) return 'good'
  if (stockPercentage.value >= 50) return 'moderate'
  if (stockPercentage.value >= 25) return 'low'
  return 'critical'
})

const stockIcon = computed(() => {
  const icons = {
    excellent: CheckCircle,
    good: TrendingUp,
    moderate: MinusCircle,
    low: AlertTriangle,
    critical: XCircle
  }
  return icons[stockLevel.value]
})

const circumference = computed(() => 2 * Math.PI * 14) // radius = 14
const offset = computed(() => circumference.value - (stockPercentage.value / 100) * circumference.value)

const ringColor = computed(() => {
  const colors = {
    excellent: 'text-gray-200',
    good: 'text-gray-200',
    moderate: 'text-gray-200',
    low: 'text-gray-200',
    critical: 'text-gray-200'
  }
  return colors[stockLevel.value]
})

const progressColor = computed(() => {
  const colors = {
    excellent: 'text-green-500',
    good: 'text-blue-500',
    moderate: 'text-yellow-500',
    low: 'text-orange-500',
    critical: 'text-red-500'
  }
  return colors[stockLevel.value]
})

const iconColor = computed(() => {
  const colors = {
    excellent: 'text-green-600',
    good: 'text-blue-600',
    moderate: 'text-yellow-600',
    low: 'text-orange-600',
    critical: 'text-red-600'
  }
  return colors[stockLevel.value]
})

const textColor = computed(() => {
  const colors = {
    excellent: 'text-green-700',
    good: 'text-blue-700',
    moderate: 'text-yellow-700',
    low: 'text-orange-700',
    critical: 'text-red-700'
  }
  return colors[stockLevel.value]
})

const labelColor = computed(() => {
  const colors = {
    excellent: 'text-green-600',
    good: 'text-blue-600',
    moderate: 'text-yellow-600',
    low: 'text-orange-600',
    critical: 'text-red-600'
  }
  return colors[stockLevel.value]
})

const availabilityDot = computed(() => {
  const colors = {
    excellent: 'bg-green-500',
    good: 'bg-blue-500',
    moderate: 'bg-yellow-500',
    low: 'bg-orange-500',
    critical: 'bg-red-500'
  }
  return colors[stockLevel.value]
})

const availabilityText = computed(() => {
  const texts = {
    excellent: 'Excellent Stock',
    good: 'Good Stock',
    moderate: 'Moderate Stock',
    low: 'Low Stock',
    critical: 'Critical Stock'
  }
  return texts[stockLevel.value]
})

const sizeClasses = computed(() => {
  const sizes = {
    small: {
      container: 'w-6 h-6',
      text: 'text-xs'
    },
    medium: {
      container: 'w-8 h-8',
      text: 'text-sm'
    },
    large: {
      container: 'w-10 h-10',
      text: 'text-base'
    }
  }
  return sizes[props.size]
})
</script>

<template>
  <div class="flex items-center gap-3">
    <!-- Stock Level Visual -->
    <div class="flex items-center gap-2">
      <div class="relative" :class="sizeClasses.container">
        <svg
          class="w-full h-full transform -rotate-90"
          viewBox="0 0 32 32"
        >
          <circle
            cx="16"
            cy="16"
            r="14"
            stroke="currentColor"
            stroke-width="2"
            fill="none"
            :class="ringColor"
          />
          <circle
            cx="16"
            cy="16"
            r="14"
            stroke="currentColor"
            stroke-width="2"
            fill="none"
            :stroke-dasharray="circumference"
            :stroke-dashoffset="offset"
            class="transition-all duration-500"
            :class="progressColor"
          />
        </svg>
        <div class="absolute inset-0 flex items-center justify-center">
          <component :is="stockIcon" class="w-3 h-3 md:w-4 md:h-4" :class="iconColor" />
        </div>
      </div>

      <!-- Stock Information -->
      <div class="flex flex-col">
        <div class="flex items-center gap-1">
          <span class="font-semibold" :class="[textColor, sizeClasses.text]">{{ available }}</span>
          <span class="text-xs text-muted-foreground">units</span>
        </div>
        <div class="flex items-center gap-1">
          <div class="w-2 h-2 rounded-full" :class="availabilityDot"></div>
          <span class="text-xs" :class="labelColor">{{ availabilityText }}</span>
        </div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div v-if="showActions" class="flex gap-1">
      <Button size="sm" variant="outline" @click="emit('view-stock')">
        <Eye class="w-3 h-3" />
      </Button>
      <Button size="sm" variant="outline" @click="emit('stock-history')">
        <History class="w-3 h-3" />
      </Button>
    </div>

    </div>
</template>