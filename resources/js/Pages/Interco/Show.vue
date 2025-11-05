<script setup>
import { computed } from 'vue'
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
import { ArrowLeft, Edit, Package, Truck, CheckCircle, Clock, Calendar, User, AlertCircle } from 'lucide-vue-next'
import TransferStatusBadge from './Components/TransferStatusBadge.vue'
import TransferSummary from './Components/TransferSummary.vue'
import TransferTimeline from './Components/TransferTimeline.vue'
import StockIndicator from './Components/StockIndicator.vue'

const props = defineProps({
  order: Object,
  permissions: Object,
  statusTransitions: Array
})

// Computed properties
const intercoNumber = computed(() => props.order.interco_number || 'N/A')
const status = computed(() => props.order.interco_status || 'open')
const fromStoreName = computed(() => {
  // Debug: Log the available data
  console.log('Show.vue order data:', {
    from_store_name: props.order.from_store_name,
    sendingStore: props.order.sendingStore,
    has_serialized_name: !!props.order.from_store_name
  })

  // Use the serialized attribute first, then fallback to relationship
  return props.order.from_store_name ||
         props.order.sendingStore?.name ||
         props.order.sendingStore?.branch_name ||
         props.order.sendingStore?.brand_name ||
         'Unknown Sending Store'
})

const toStoreName = computed(() => {
  // Use the serialized attribute first, then fallback to relationship
  return props.order.to_store_name ||
         props.order.store_branch?.name ||
         props.order.store_branch?.branch_name ||
         'Unknown Receiving Store'
})
const createdDate = computed(() => props.order.created_at)
const reason = computed(() => props.order.interco_reason || 'No reason provided')
const remarks = computed(() => props.order.remarks || 'No remarks')

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

// Transfer statistics
const totalItems = computed(() => {
  if (!props.order?.store_order_items || !Array.isArray(props.order.store_order_items)) return 0
  const total = props.order.store_order_items.reduce((total, item) => total + (Number(item.quantity_ordered) || 0), 0)
  return Number(total) || 0
})

const totalValue = computed(() => {
  if (!props.order?.store_order_items || !Array.isArray(props.order.store_order_items)) return 0
  const value = props.order.store_order_items.reduce((total, item) => {
    return total + ((Number(item.quantity_ordered) || 0) * (Number(item.cost_per_quantity) || 0))
  }, 0)
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP'
  }).format(value)
})

const receivedItems = computed(() => {
  if (!props.order?.store_order_items || !Array.isArray(props.order.store_order_items)) return 0
  const total = props.order.store_order_items.reduce((total, item) => total + (Number(item.quantity_received) || 0), 0)
  return Number(total) || 0
})

const pendingItems = computed(() => {
  const total = totalItems.value || 0
  const received = receivedItems.value || 0
  return Math.max(0, total - received)
})

const progressPercentage = computed(() => {
  const total = totalItems.value || 0
  const received = receivedItems.value || 0
  if (total === 0) return 0
  return (received / total) * 100
})

// Status and actions
const isEditable = computed(() => props.permissions.can_edit && status.value === 'open')
const canApprove = computed(() => props.permissions.can_approve && status.value === 'open')
const canCommit = computed(() => props.permissions.can_commit && status.value === 'approved')
const canReceive = computed(() => props.permissions.can_receive)

// Utility methods
const formatDate = (dateString) => {
  if (!dateString) return 'N/A'
  return new Date(dateString).toLocaleDateString('en-PH', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const formatCurrency = (amount) => {
  if (!amount) return '₱0.00'
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP'
  }).format(amount)
}

const formatNumber = (value, decimals = 2) => {
  if (value === null || value === undefined || isNaN(value)) return '0.00'
  return Number(value).toFixed(decimals)
}

// Helper functions for item display
const getItemDescription = (item) => {
  console.log('Getting description for item:', item.item_code, 'UOM:', item.uom)
  console.log('Available description data:', {
    item_description: item.item_description,
    sapMasterfile: item.sapMasterfile
  })

  // Use the serialized attribute first, then fallback to relationship
  return item.item_description ||
         item.sapMasterfile?.ItemDescription ||
         item.sapMasterfile?.ItemName ||
         'Description not available'
}

const getItemUOM = (item) => {
  // Use the actual UOM field from store_order_items table
  // The model's item_uom accessor now returns item.uom directly
  return item.item_uom || item.uom || ''
}

const getStatusIcon = (status) => {
  const icons = {
    open: Clock,
    approved: CheckCircle,
    committed: Package,
    in_transit: Truck,
    received: CheckCircle,
    disapproved: AlertCircle
  }
  return icons[status] || Clock
}

const executeAction = (action) => {
  const transition = props.statusTransitions.find(t => t.action === action)
  if (transition) {
    router.post(route(`interco.${action}`, props.order.id), {
      _method: 'PATCH',
      next_status: transition.nextStatus
    })
  }
}

const goToReceive = () => {
  // Redirect to the existing OrderReceiving show page for this order
  router.get(route('orders-receiving.show', props.order.id))
}

const goToEdit = () => {
  router.get(route('interco.edit', props.order.id))
}
</script>

<template>
  <Layout heading="Transfer Details">
    <template #actions>
      <Button variant="outline" @click="router.get(route('interco.index'))">
        <ArrowLeft class="w-4 h-4 mr-2" />
        Back to Transfers
      </Button>
      <Button v-if="isEditable" @click="goToEdit">
        <Edit class="w-4 h-4 mr-2" />
        Edit Transfer
      </Button>
    </template>

    <div class="grid gap-6">
      <!-- Transfer Summary -->
      <TransferSummary :transfer="order" />

      <!-- Main Content Grid -->
      <div class="grid lg:grid-cols-3 gap-6">
        <!-- Left Column - Transfer Information -->
        <div class="space-y-6">
          <!-- Transfer Details Card -->
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2">
                <component :is="getStatusIcon(status)" class="w-5 h-5" />
                Transfer Information
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
              <!-- Basic Information -->
              <div class="space-y-3">
                <div>
                  <p class="text-sm font-medium text-muted-foreground">Transfer Number</p>
                  <p class="font-mono text-lg">{{ intercoNumber }}</p>
                </div>

                <div>
                  <p class="text-sm font-medium text-muted-foreground">Status</p>
                  <TransferStatusBadge :status="status" />
                </div>

                <Separator />

                <!-- Store Information -->
                <div>
                  <p class="text-sm font-medium text-muted-foreground mb-2">Stores</p>
                  <div class="space-y-2">
                    <div class="flex items-start gap-3">
                      <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <Package class="w-4 h-4 text-red-600" />
                      </div>
                      <div>
                        <p class="text-xs text-muted-foreground">From</p>
                        <p class="font-medium">{{ fromStoreName }}</p>
                        <p class="text-xs text-muted-foreground">{{ order.sendingStore?.branch_code || order.sendingStore?.code || '' }}</p>
                      </div>
                    </div>
                    <div class="flex items-start gap-3">
                      <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0 mt-0.5">
                        <Package class="w-4 h-4 text-green-600" />
                      </div>
                      <div>
                        <p class="text-xs text-muted-foreground">To</p>
                        <p class="font-medium">{{ toStoreName }}</p>
                        <p class="text-xs text-muted-foreground">{{ order.store_branch?.branch_code || order.store_branch?.code || '' }}</p>
                      </div>
                    </div>
                  </div>
                </div>

                <Separator />

                <!-- Dates -->
                <div>
                  <p class="text-sm font-medium text-muted-foreground mb-2">Timeline</p>
                  <div class="space-y-2">
                    <div class="flex items-center gap-2">
                      <Calendar class="w-4 h-4 text-muted-foreground" />
                      <div>
                        <p class="text-xs text-muted-foreground">Created</p>
                        <p class="text-sm">{{ formatDate(createdDate) }}</p>
                      </div>
                    </div>
                    <div v-if="order.approval_action_date" class="flex items-center gap-2">
                      <CheckCircle class="w-4 h-4 text-blue-600" />
                      <div>
                        <p class="text-xs text-muted-foreground">Approved</p>
                        <p class="text-sm">{{ formatDate(order.approval_action_date) }}</p>
                      </div>
                    </div>
                    <div v-if="order.commited_action_date" class="flex items-center gap-2">
                      <Package class="w-4 h-4 text-yellow-600" />
                      <div>
                        <p class="text-xs text-muted-foreground">Committed</p>
                        <p class="text-sm">{{ formatDate(order.commited_action_date) }}</p>
                      </div>
                    </div>
                  </div>
                </div>

                <Separator />

                <!-- People -->
                <div>
                  <p class="text-sm font-medium text-muted-foreground mb-2">People</p>
                  <div class="space-y-2">
                    <div v-if="order.encoder" class="flex items-center gap-2">
                      <User class="w-4 h-4 text-muted-foreground" />
                      <div>
                        <p class="text-xs text-muted-foreground">Created by</p>
                        <p class="text-sm">{{ formatUserName(order.encoder) }}</p>
                      </div>
                    </div>
                    <div v-else class="flex items-center gap-2">
                      <User class="w-4 h-4 text-muted-foreground" />
                      <div>
                        <p class="text-xs text-muted-foreground">Created by</p>
                        <p class="text-sm text-muted-foreground">Not specified</p>
                      </div>
                    </div>
                    <div v-if="order.approver" class="flex items-center gap-2">
                      <CheckCircle class="w-4 h-4 text-blue-600" />
                      <div>
                        <p class="text-xs text-muted-foreground">Approved by</p>
                        <p class="text-sm">{{ formatUserName(order.approver) }}</p>
                      </div>
                    </div>
                    <div v-if="order.commiter" class="flex items-center gap-2">
                      <Package class="w-4 h-4 text-yellow-600" />
                      <div>
                        <p class="text-xs text-muted-foreground">Committed by</p>
                        <p class="text-sm">{{ formatUserName(order.commiter) }}</p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>

          <!-- Transfer Timeline -->
          <TransferTimeline :transfer="order" />
        </div>

        <!-- Right Column - Items and Actions -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Actions Card -->
          <Card v-if="statusTransitions.length > 0">
            <CardHeader>
              <CardTitle>Available Actions</CardTitle>
            </CardHeader>
            <CardContent>
              <div class="flex flex-wrap gap-3">
                <Button
                  v-for="action in statusTransitions"
                  :key="action.action"
                  :variant="action.color === 'red' ? 'destructive' : 'default'"
                  @click="executeAction(action.action)"
                >
                  <component :is="action.icon" class="w-4 h-4 mr-2" />
                  {{ action.label }}
                </Button>
                <Button
                  v-if="canReceive && ['committed', 'in_transit'].includes(status)"
                  @click="goToReceive"
                >
                  <Truck class="w-4 h-4 mr-2" />
                  Receive Items
                </Button>
              </div>
            </CardContent>
          </Card>

          <!-- Items Card -->
          <Card>
            <CardHeader>
              <CardTitle>Transfer Items</CardTitle>
            </CardHeader>
            <CardContent>
              <!-- Progress Bar -->
              <div v-if="status !== 'open'" class="mb-6">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm font-medium">Transfer Progress</span>
                  <span class="text-sm text-muted-foreground">{{ formatNumber(progressPercentage) }}%</span>
                </div>
                <div class="w-full bg-secondary rounded-full h-2">
                  <div
                    class="bg-primary h-2 rounded-full transition-all duration-500"
                    :style="{ width: `${formatNumber(progressPercentage)}%` }"
                  ></div>
                </div>
              </div>

              <!-- Items Table -->
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Item Code</TableHead>
                    <TableHead>Description</TableHead>
                    <TableHead>Quantity</TableHead>
                    <TableHead>Received</TableHead>
                    <TableHead>Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow v-for="item in order.store_order_items" :key="item.id">
                    <TableCell class="font-medium">
                      {{ item.sapMasterfile?.ItemCode || item.sapMasterfile?.item_code || item.item_code || 'N/A' }}
                    </TableCell>
                    <TableCell>
                      <div>
                        <!-- Debug: Log the item data in console -->
                        <div v-if="false" style="font-size: 10px; color: gray;">
                          Item Code: {{ item.item_code }}, UOM: {{ item.uom }},
                          SAP Data: {{ JSON.stringify(item.sapMasterfile) }}
                        </div>
                        <p class="font-medium">{{ getItemDescription(item) }}</p>
                        <Badge v-if="getItemUOM(item)" variant="outline" class="text-xs mt-1">
                          {{ getItemUOM(item) }}
                        </Badge>
                      </div>
                    </TableCell>
                    <TableCell>
                      <span class="font-medium">{{ item.quantity_ordered || 0 }}</span>
                    </TableCell>
                    <TableCell>
                      <div class="flex items-center gap-2">
                        <span class="font-medium">{{ item.quantity_received || 0 }}</span>
                        <span v-if="item.quantity_received > 0 && item.quantity_received < item.quantity_ordered"
                              class="text-xs text-orange-600">
                          ({{ item.quantity_ordered - item.quantity_received }} pending)
                        </span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <div class="flex items-center gap-2">
                        <div v-if="item.quantity_received === item.quantity_ordered && item.quantity_received > 0"
                             class="w-2 h-2 rounded-full bg-green-500"></div>
                        <div v-else-if="item.quantity_received > 0"
                             class="w-2 h-2 rounded-full bg-yellow-500"></div>
                        <div v-else class="w-2 h-2 rounded-full bg-gray-300"></div>
                        <span class="text-sm">
                          {{ item.quantity_received === item.quantity_ordered && item.quantity_received > 0 ? 'Complete' :
                             item.quantity_received > 0 ? 'Partial' : 'Pending' }}
                        </span>
                      </div>
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>

              <!-- Summary Row -->
              <div class="mt-4 pt-4 border-t">
                <div class="flex justify-between items-center">
                  <span class="text-sm font-medium">Total Items: {{ formatNumber(totalItems) }}</span>
                  <!-- Removed totalValue currency display as requested -->
                </div>
              </div>
            </CardContent>
          </Card>

          <!-- Reason and Remarks -->
          <Card>
            <CardHeader>
              <CardTitle>Additional Information</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
              <div>
                <p class="text-sm font-medium text-muted-foreground mb-2">Reason for Transfer</p>
                <p class="text-sm">{{ reason }}</p>
              </div>
              <div v-if="remarks && remarks !== 'No remarks'">
                <p class="text-sm font-medium text-muted-foreground mb-2">Remarks</p>
                <p class="text-sm">{{ remarks }}</p>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  </Layout>
</template>

<style scoped>
/* Custom styles for better visual hierarchy */
.transfer-summary {
  border-left-width: 4px;
}

.progress-bar {
  transition: width 0.5s ease-in-out;
}

.status-indicator {
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

@keyframes pulse {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: .5;
  }
}
</style>