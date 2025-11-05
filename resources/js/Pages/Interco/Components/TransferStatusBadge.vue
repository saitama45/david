<script setup>
import { computed } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Info, Clock, CheckCircle, Package, Truck, XCircle } from 'lucide-vue-next'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'

const props = defineProps({
  status: {
    type: String,
    required: true
  },
  showTooltip: {
    type: Boolean,
    default: true
  }
})

const statusConfig = {
  open: {
    label: 'Open',
    color: 'gray',
    icon: 'Clock',
    description: 'Awaiting approval',
    pulse: true
  },
  approved: {
    label: 'Approved',
    color: 'blue',
    icon: 'CheckCircle',
    description: 'Approved and ready for commitment'
  },
  committed: {
    label: 'Committed',
    color: 'yellow',
    icon: 'Package',
    description: 'Items prepared for transfer'
  },
  in_transit: {
    label: 'In Transit',
    color: 'purple',
    icon: 'Truck',
    description: 'Items are being transported',
    pulse: true
  },
  received: {
    label: 'Received',
    color: 'green',
    icon: 'CheckCircle',
    description: 'Transfer completed successfully'
  },
  disapproved: {
    label: 'Disapproved',
    color: 'red',
    icon: 'XCircle',
    description: 'Transfer was disapproved'
  }
}

const currentConfig = computed(() => statusConfig[props.status] || statusConfig.open)

const badgeClasses = computed(() => {
  const baseClasses = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium transition-all duration-200'

  const colorClasses = {
    gray: 'bg-gray-100 text-gray-800 hover:bg-gray-200',
    blue: 'bg-blue-100 text-blue-800 hover:bg-blue-200',
    yellow: 'bg-yellow-100 text-yellow-800 hover:bg-yellow-200',
    purple: 'bg-purple-100 text-purple-800 hover:bg-purple-200',
    green: 'bg-green-100 text-green-800 hover:bg-green-200',
    red: 'bg-red-100 text-red-800 hover:bg-red-200'
  }

  return `${baseClasses} ${colorClasses[currentConfig.value.color]}`
})

const dotClasses = computed(() => {
  const pulseClass = currentConfig.value.pulse ? 'animate-pulse' : ''
  const colorClasses = {
    gray: 'bg-gray-500',
    blue: 'bg-blue-500',
    yellow: 'bg-yellow-500',
    purple: 'bg-purple-500',
    green: 'bg-green-500',
    red: 'bg-red-500'
  }

  return `${colorClasses[currentConfig.value.color]} ${pulseClass}`
})

const statusIcon = computed(() => {
  const icons = {
    Clock,
    CheckCircle,
    Package,
    Truck,
    XCircle
  }
  return icons[currentConfig.value.icon]
})

const statusLabel = computed(() => currentConfig.value.label)
const statusDescription = computed(() => currentConfig.value.description)
</script>

<template>
  <div class="flex items-center gap-2">
    <div :class="badgeClasses">
      <div class="w-2 h-2 rounded-full" :class="dotClasses"></div>
      <component :is="statusIcon" class="w-4 h-4" />
      <span>{{ statusLabel }}</span>
    </div>

    <!-- Hover Tooltip -->
    <TooltipProvider v-if="showTooltip">
      <Tooltip>
        <TooltipTrigger>
          <Info class="w-4 h-4 text-muted-foreground cursor-help" />
        </TooltipTrigger>
        <TooltipContent>
          <p class="text-sm">{{ statusDescription }}</p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  </div>
</template>