<script setup>
import { computed } from 'vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Check, Clock, CheckCircle, Package, Truck, XCircle, Calendar, User } from 'lucide-vue-next'

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

// Debug logging to help identify data issues
console.log('TransferTimeline Debug - Transfer Data:', {
  transfer: props.transfer,
  approval_action_date: props.transfer.approval_action_date,
  approver: props.transfer.approver,
  commited_action_date: props.transfer.commited_action_date,
  commiter: props.transfer.commiter,
  interco_status: props.transfer.interco_status
})

const totalCommittedQuantity = computed(() => {
  if (!props.transfer.store_order_items) return 0
  return props.transfer.store_order_items.reduce((total, item) => {
    return total + (Number(item.quantity_commited) || 0)
  }, 0)
})

const totalCommittedItems = computed(() => {
  if (!props.transfer.store_order_items) return 0
  return props.transfer.store_order_items.length
})

const timeline = computed(() => {
  const events = []

  // Created event
  events.push({
    status: 'created',
    title: 'Transfer Created',
    description: 'Transfer request was created and submitted',
    date: props.transfer.created_at,
    user: formatUserName(props.transfer.encoder),
    completed: true,
    icon: Clock,
    color: 'gray'
  })

  // Approved event
  if (props.transfer.approval_action_date) {
    events.push({
      status: 'approved',
      title: 'Transfer Approved',
      description: 'Transfer was approved by management',
      date: props.transfer.approval_action_date,
      user: formatUserName(props.transfer.approver),
      completed: ['approved', 'committed', 'in_transit', 'received'].includes(props.transfer.interco_status),
      icon: CheckCircle,
      color: 'blue'
    })
  }

  // Committed event
  if (props.transfer.commited_action_date) {
    events.push({
      status: 'committed',
      title: 'Transfer Committed',
      description: 'Items were committed for transfer',
      date: props.transfer.commited_action_date,
      user: formatUserName(props.transfer.commiter),
      completed: ['committed', 'in_transit', 'received'].includes(props.transfer.interco_status),
      icon: Package,
      color: 'yellow',
      details: props.transfer.store_order_items ? [
        { label: 'Total Items', value: totalCommittedItems.value },
        { label: 'Total Quantity', value: totalCommittedQuantity.value }
      ] : []
    })
  }

  // In Transit event
  if (props.transfer.interco_status === 'in_transit' || props.transfer.interco_status === 'received') {
    events.push({
      status: 'in_transit',
      title: 'In Transit',
      description: 'Items are being transported between stores',
      date: getInTransitDate(),
      user: 'System',
      completed: props.transfer.interco_status === 'received',
      icon: Truck,
      color: 'purple'
    })
  }

  // Received event
  if (props.transfer.interco_status === 'received') {
    events.push({
      status: 'received',
      title: 'Transfer Completed',
      description: 'All items have been received successfully',
      date: getReceivedDate(),
      user: 'Receiving Store',
      completed: true,
      icon: CheckCircle,
      color: 'green',
      badge: {
        text: 'Completed',
        variant: 'default'
      }
    })
  }

  // Disapproved event
  if (props.transfer.interco_status === 'disapproved') {
    events.push({
      status: 'disapproved',
      title: 'Transfer Disapproved',
      description: 'Transfer was disapproved',
      date: props.transfer.updated_at,
      user: formatUserName(props.transfer.approver),
      completed: true,
      icon: XCircle,
      color: 'red',
      badge: {
        text: 'Disapproved',
        variant: 'destructive'
      }
    })
  }

  return events
})

const getInTransitDate = () => {
  // This would typically come from a dedicated in_transit_date field
  // For now, estimate based on committed date
  if (props.transfer.commited_action_date) {
    const committedDate = new Date(props.transfer.commited_action_date)
    return new Date(committedDate.getTime() + 24 * 60 * 60 * 1000) // Add 1 day
  }
  return new Date()
}

const getReceivedDate = () => {
  // This would typically come from a received_date field
  // For now, use updated_at as fallback
  return props.transfer.updated_at || new Date()
}

const formatDate = (dateString) => {
  if (!dateString) return 'N/A'
  const date = new Date(dateString)
  return date.toLocaleDateString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const getEventClass = (status) => {
  const classes = {
    created: 'bg-gray-100 text-gray-600 border-gray-300',
    approved: 'bg-blue-100 text-blue-600 border-blue-300',
    committed: 'bg-yellow-100 text-yellow-600 border-yellow-300',
    in_transit: 'bg-purple-100 text-purple-600 border-purple-300',
    received: 'bg-green-100 text-green-600 border-green-300',
    disapproved: 'bg-red-100 text-red-600 border-red-300'
  }
  return classes[status] || classes.created
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="flex items-center gap-2">
        <Clock class="w-5 h-5" />
        Transfer Timeline
      </CardTitle>
    </CardHeader>
    <CardContent>
      <div class="relative">
        <!-- Timeline Line -->
        <div class="absolute left-6 top-0 bottom-0 w-0.5 bg-border"></div>

        <!-- Timeline Events -->
        <div class="space-y-6">
          <div
            v-for="(event, index) in timeline"
            :key="index"
            class="flex items-start gap-4"
          >
            <!-- Status Icon -->
            <div
              class="relative z-10 w-12 h-12 rounded-full flex items-center justify-center border-2 border-background transition-all duration-300"
              :class="getEventClass(event.status)"
            >
              <component :is="event.icon" class="w-5 h-5" />
              <div
                v-if="event.completed"
                class="absolute -bottom-1 -right-1 w-4 h-4 bg-green-500 rounded-full flex items-center justify-center"
              >
                <Check class="w-3 h-3 text-white" />
              </div>
            </div>

            <!-- Event Details -->
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 mb-1">
                <h4 class="font-medium">{{ event.title }}</h4>
                <Badge v-if="event.badge" :variant="event.badge.variant">
                  {{ event.badge.text }}
                </Badge>
              </div>
              <p class="text-sm text-muted-foreground mb-2">{{ event.description }}</p>

              <div class="flex items-center gap-4 mb-2">
                <span class="text-xs text-muted-foreground flex items-center gap-1">
                  <Calendar class="w-3 h-3" />
                  {{ formatDate(event.date) }}
                </span>
                <span v-if="event.user" class="text-xs text-muted-foreground flex items-center gap-1">
                  <User class="w-3 h-3" />
                  {{ event.user }}
                </span>
              </div>

              <!-- Additional Details -->
              <div v-if="event.details && event.details.length > 0" class="mt-3 p-3 bg-muted/50 rounded-lg">
                <div v-for="detail in event.details" :key="detail.label" class="flex items-baseline gap-2 text-sm mb-1 last:mb-0">
                  <span class="text-muted-foreground">{{ detail.label }}:</span>
                  <span class="font-medium">{{ detail.value }}</span>
                </div>
              </div>

              <!-- Status Indicator -->
              <div v-if="!event.completed" class="mt-3">
                <div class="flex items-center gap-2 text-xs text-muted-foreground">
                  <div class="w-2 h-2 bg-yellow-500 rounded-full animate-pulse"></div>
                  <span>In Progress</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Empty State -->
        <div v-if="timeline.length === 0" class="text-center py-8">
          <Clock class="w-8 h-8 mx-auto mb-2 text-muted-foreground opacity-50" />
          <p class="text-sm text-muted-foreground">No timeline events available</p>
        </div>
      </div>
    </CardContent>
  </Card>
</template>

<style scoped>
.timeline-connector {
  background: linear-gradient(to bottom, hsl(var(--border)), hsl(var(--border)));
}
</style>
