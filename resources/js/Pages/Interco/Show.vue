<script setup>
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
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
import { ArrowLeft, Edit, Package, CheckCircle, Clock, Calendar, User, AlertCircle, Truck } from 'lucide-vue-next'
import TransferStatusBadge from './components/TransferStatusBadge.vue'
import TransferSummary from './components/TransferSummary.vue'
import TransferTimeline from './components/TransferTimeline.vue'
import StockIndicator from './components/StockIndicator.vue'

const props = defineProps({
  order: Object,
  permissions: Object,
  // Items the receiving store received short or over: { lines: [...], can_answer }
  senderConfirmation: {
    type: Object,
    default: () => ({ lines: [], can_answer: false })
  }
})

const toast = useToast()
const confirm = useConfirm()

// The receiving store received a different quantity than was sent. Nothing has moved for these
// items: Confirm moves the received quantity out of this store and into theirs; Re-Send gives
// the item back to them to recount. Until then neither store can do its Month End Count.
const linesToConfirm = computed(() => props.senderConfirmation?.lines || [])
const canAnswer = computed(() => !!props.senderConfirmation?.can_answer)
const formatQty = (value) => Number(value ?? 0).toFixed(4)
const formatVariance = (value) => `${Number(value) > 0 ? '+' : ''}${formatQty(value)}`

const answerForm = useForm({ store_order_item_id: null, remarks: '' })

const flashToast = (page, summary) => {
  const flash = page.props.flash || {}
  toast.add({ severity: flash.info ? 'info' : 'success', summary, detail: flash.info || flash.success || 'Done.', life: 6000 })
}
const errorToast = (errors, summary) => {
  toast.add({ severity: 'error', summary, detail: errors.error || Object.values(errors)[0] || 'Please try again.', life: 7000 })
}

const promptConfirm = (line = null) => {
  const what = line
    ? `${formatQty(line.received)} ${line.uom} of ${line.description}`
    : `the received quantity of all ${linesToConfirm.value.length} item(s)`
  confirm.require({
    header: 'Confirm Received Quantity?',
    message: `Confirm ${what}? That quantity is taken out of ${fromStoreName.value} and added to ${toStoreName.value} now. This cannot be undone.`,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: 'Cancel', severity: 'secondary', outlined: true },
    acceptProps: { label: 'Confirm', severity: 'success' },
    accept: () => {
      answerForm.store_order_item_id = line?.item_id ?? null
      answerForm.remarks = ''
      answerForm.post(route('interco.received-quantity.confirm', props.order.id), {
        preserveScroll: true,
        onSuccess: (page) => flashToast(page, 'Received Quantity Confirmed'),
        onError: (errors) => errorToast(errors, 'Unable to Confirm'),
      })
    },
  })
}

const resendLine = ref(null)
const isResendOpen = ref(false)
const openResend = (line = null) => {
  resendLine.value = line
  answerForm.store_order_item_id = line?.item_id ?? null
  answerForm.remarks = ''
  isResendOpen.value = true
}
const submitResend = () => {
  answerForm.post(route('interco.received-quantity.resend', props.order.id), {
    preserveScroll: true,
    onSuccess: (page) => {
      isResendOpen.value = false
      flashToast(page, 'Re-Sent to Recount')
    },
    onError: (errors) => errorToast(errors, 'Unable to Re-Send'),
  })
}

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


const displayApprovedQty = (item) => {
  const currentStatus = props.order.interco_status;
  if (['approved', 'committed', 'in_transit', 'received'].includes(currentStatus)) {
    return item.quantity_approved || 0;
  }
  return 0;
};

// The quantity to send: approved, or restated by the sending store's optional commit.
const displayCommittedQty = (item) => {
  const currentStatus = props.order.interco_status;
  if (['approved', 'committed', 'in_transit', 'received'].includes(currentStatus)) {
    return item.quantity_commited || 0;
  }
  return 0;
};
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

      <!-- Received short or over: the sending store confirms or re-sends -->
      <Card v-if="linesToConfirm.length" class="border-amber-300">
        <CardHeader>
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <CardTitle class="flex items-center gap-2">
                <AlertCircle class="w-5 h-5 text-amber-600" />
                Received Quantity Confirmation
              </CardTitle>
              <p class="mt-1 text-sm text-muted-foreground">
                {{ toStoreName }} received a different quantity than {{ fromStoreName }} sent for these items, and nothing has moved for them yet.
                Confirm to move the received quantity out of {{ fromStoreName }} and into {{ toStoreName }}, or Re-Send for {{ toStoreName }} to recount.
                Neither store can do its Month End Count until every item is answered.
              </p>
            </div>
            <div v-if="canAnswer && linesToConfirm.length > 1" class="flex gap-2">
              <Button variant="outline" :disabled="answerForm.processing" @click="openResend()">Re-Send All</Button>
              <Button :disabled="answerForm.processing" @click="promptConfirm()">Confirm All</Button>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Item</TableHead>
                <TableHead>UOM</TableHead>
                <TableHead>Sent</TableHead>
                <TableHead>Received</TableHead>
                <TableHead>Variance</TableHead>
                <TableHead>Receiver's Remarks</TableHead>
                <TableHead>Sent for Confirmation</TableHead>
                <TableHead v-if="canAnswer">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="line in linesToConfirm" :key="line.item_id">
                <TableCell>
                  <p class="font-medium">{{ line.description }}</p>
                  <p class="text-xs text-muted-foreground">{{ line.item_code }}</p>
                </TableCell>
                <TableCell>{{ line.uom }}</TableCell>
                <TableCell>{{ formatQty(line.sent) }}</TableCell>
                <TableCell class="font-semibold">{{ formatQty(line.received) }}</TableCell>
                <TableCell class="font-semibold text-amber-700">{{ formatVariance(line.variance) }}</TableCell>
                <TableCell>{{ line.remarks || '-' }}</TableCell>
                <TableCell>
                  <p>{{ line.requested_at || '-' }}</p>
                  <p v-if="line.received_by" class="text-xs text-muted-foreground">by {{ line.received_by }}</p>
                </TableCell>
                <TableCell v-if="canAnswer">
                  <div class="flex gap-2">
                    <Button size="sm" :disabled="answerForm.processing" @click="promptConfirm(line)">Confirm</Button>
                    <Button size="sm" variant="outline" :disabled="answerForm.processing" @click="openResend(line)">Re-Send</Button>
                  </div>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
          <p v-if="!canAnswer" class="mt-3 text-xs text-muted-foreground">
            Only the people of {{ fromStoreName }} can confirm or re-send these items.
          </p>
        </CardContent>
      </Card>

      <!-- Main Content Grid -->
      <div class="grid lg:grid-cols-1 gap-6">
        <!-- Full Width Column - Items, Reason/Remarks, and Timeline -->
        <div class="lg:col-span-3 space-y-6">
  
          <!-- Items Card -->
          <Card>
            <CardHeader>
              <CardTitle>Transfer Items</CardTitle>
            </CardHeader>
            <CardContent>
              <!-- Items Table -->
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Item Code</TableHead>
                    <TableHead>Description</TableHead>
                    <TableHead>Ordered Qty</TableHead>
                    <TableHead>Approved Qty</TableHead>
                    <TableHead>Committed Qty</TableHead>
                    <TableHead>Received Qty</TableHead>
                    <TableHead>Variance</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow v-for="item in order.store_order_items" :key="item.id">
                    <TableCell class="font-medium">
                      {{ item.sapMasterfile?.ItemCode || item.sapMasterfile?.item_code || item.item_code || 'N/A' }}
                    </TableCell>
                    <TableCell>
                      <div>
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
                      <span class="font-medium">{{ displayApprovedQty(item) }}</span>
                    </TableCell>
                    <TableCell>
                      <span class="font-medium">{{ displayCommittedQty(item) }}</span>
                    </TableCell>
                    <TableCell>
                      <span class="font-medium">{{ item.quantity_received || 0 }}</span>
                    </TableCell>
                    <TableCell>
                      <span class="font-medium">{{ (displayCommittedQty(item) || 0) - (item.quantity_received || 0) }}</span>
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>

              <!-- Summary Row -->
              <div class="mt-4 pt-4 border-t">
                <div class="flex justify-between items-center">
                  <span class="text-sm font-medium">Total Quantity: {{ formatNumber(totalItems) }}</span>
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

          <!-- Transfer Timeline -->
          <TransferTimeline :transfer="order" />
        </div>
      </div>
    </div>

    <!-- Re-Send: back to the receiving store to recount -->
    <Dialog v-model:open="isResendOpen">
      <DialogContent class="sm:max-w-[520px]">
        <DialogHeader>
          <DialogTitle>Re-Send to {{ toStoreName }}?</DialogTitle>
          <DialogDescription>
            {{ resendLine ? resendLine.description : `All ${linesToConfirm.length} item(s)` }} go back to {{ toStoreName }} to recount.
            Nothing moves until the quantities match or you confirm them.
          </DialogDescription>
        </DialogHeader>
        <div class="space-y-2">
          <label class="text-xs font-medium" for="resend-remarks">Reason (optional, shown to {{ toStoreName }})</label>
          <Textarea id="resend-remarks" v-model="answerForm.remarks" maxlength="500" placeholder="e.g. We sent the full 1 kg, please recount." />
        </div>
        <DialogFooter>
          <Button variant="ghost" @click="isResendOpen = false">Cancel</Button>
          <Button :disabled="answerForm.processing" @click="submitResend">Re-Send</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
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
