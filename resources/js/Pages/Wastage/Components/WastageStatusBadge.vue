<script setup>
import { computed } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Info, Clock, CheckCircle, XCircle, AlertCircle } from 'lucide-vue-next'
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
  'PENDING': {
    label: 'Pending',
    color: 'yellow',
    icon: 'Clock',
    description: 'Awaiting level 1 approval',
    pulse: true
  },
  'APPROVED_LVL1': {
    label: 'Approved Level 1',
    color: 'blue',
    icon: 'CheckCircle',
    description: 'Approved at level 1, awaiting final approval'
  },
  'APPROVED_LVL2': {
    label: 'Approved Level 2',
    color: 'green',
    icon: 'CheckCircle',
    description: 'Fully approved and processed'
  },
  'CANCELLED': {
    label: 'Cancelled',
    color: 'red',
    icon: 'XCircle',
    description: 'Wastage record was cancelled'
  }
}

const currentConfig = computed(() => statusConfig[props.status] || statusConfig['PENDING'])

const badgeClasses = computed(() => {
  const baseClasses = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium transition-all duration-200'

  const colorClasses = {
    yellow: 'bg-yellow-100 text-yellow-800 hover:bg-yellow-200',
    blue: 'bg-blue-100 text-blue-800 hover:bg-blue-200',
    green: 'bg-green-100 text-green-800 hover:bg-green-200',
    red: 'bg-red-100 text-red-800 hover:bg-red-200'
  }

  return `${baseClasses} ${colorClasses[currentConfig.value.color]}`
})

const dotClasses = computed(() => {
  const pulseClass = currentConfig.value.pulse ? 'animate-pulse' : ''
  const colorClasses = {
    yellow: 'bg-yellow-500',
    blue: 'bg-blue-500',
    green: 'bg-green-500',
    red: 'bg-red-500'
  }

  return `${colorClasses[currentConfig.value.color]} ${pulseClass}`
})

const statusIcon = computed(() => {
  const icons = {
    Clock,
    CheckCircle,
    XCircle,
    AlertCircle
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