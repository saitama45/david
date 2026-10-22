<script setup>
import { ref, computed, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Search, Filter, RotateCcw, Download, Plus, Eye, Edit, ArrowLeftRight, AlertCircle } from 'lucide-vue-next'
import TransferStatusBadge from './components/TransferStatusBadge.vue'
import TransferSummary from './components/TransferSummary.vue'
import MobileTableContainer from '@/components/table/MobileTableContainer.vue'
import MobileTableRow from '@/components/table/MobileTableRow.vue'

const props = defineProps({
  orders: Object,
  statistics: Object,
  filters: Object,
  statusOptions: Array,
  permissions: Object
})

// Local state
const search = ref(props.filters.search || '')
const status = ref(props.filters.status || 'open')
const dateRange = ref('')

// Computed properties
const hasActiveFilters = computed(() => {
  return search.value ||
         (status.value && status.value !== 'all') ||
         dateRange.value
})

// Each card lists its transfers when clicked
const filteredStats = computed(() => {
  const stats = props.statistics ? props.statistics : {}
  return [
    { label: 'Total Transfers', status: 'all', value: stats.total ? stats.total : 0, color: 'text-blue-600' },
    { label: 'Open', status: 'open', value: stats.open ? stats.open : 0, color: 'text-gray-600' },
    { label: 'In Transit', status: 'in_transit', value: stats.in_transit ? stats.in_transit : 0, color: 'text-purple-600' },
    { label: 'Approved', status: 'approved', value: stats.approved ? stats.approved : 0, color: 'text-orange-600' },
    { label: 'Received', status: 'received', value: stats.received ? stats.received : 0, color: 'text-green-600' },
    // Transfers your store sent that the receiving store got short or over: waiting for your Confirm or Re-Send
    { label: 'For Your Confirmation', status: 'for_confirmation', value: stats.for_confirmation ? stats.for_confirmation : 0, color: 'text-orange-600', alert: true },
  ]
})

const showStatus = (value) => {
  status.value = value
}

// A transfer with items received short or over, waiting for the sending store's Confirm or Re-Send
const itemCount = (count) => `${count} item${count === 1 ? '' : 's'}`

const confirmationLabel = (order) => order.confirmation_is_yours
  ? `For your confirmation · ${itemCount(order.for_confirmation_count)}`
  : `Waiting for ${fromStoreName(order)} · ${itemCount(order.for_confirmation_count)}`

const confirmationTitle = (order) => order.confirmation_is_yours
  ? `${toStoreName(order)} received a different quantity than was sent. Open the transfer to Confirm or Re-Send.`
  : `${fromStoreName(order)} has to confirm the received quantity of ${itemCount(order.for_confirmation_count)}. Nothing has moved for them yet.`

// Methods
const applyFilters = () => {
  const params = {}

  if (search.value) params.search = search.value
  if (status.value) params.status = status.value
  if (dateRange.value) params.date_range = dateRange.value

  router.get(route('interco.index'), params, {
    preserveState: true,
    preserveScroll: true
  })
}

const clearFilters = () => {
  search.value = ''
  status.value = 'all'
  dateRange.value = ''

  router.get(route('interco.index'), {}, {
    preserveState: true,
    preserveScroll: true
  })
}

const exportData = () => {
  const params = {}

  if (search.value) params.search = search.value
  if (status.value) params.status = status.value
  if (dateRange.value) params.date_range = dateRange.value

  window.open(route('interco.export', params), '_blank')
}

const formatDate = (dateString) => {
  if (!dateString) return 'N/A'
  return new Date(dateString).toLocaleDateString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}

const formatCurrency = (amount) => {
  if (!amount) return '₱0.00'
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP'
  }).format(amount)
}

const fromStoreName = (order) => {
  return order.from_store_name ||
         order.sendingStore?.name ||
         order.sendingStore?.branch_name ||
         order.sendingStore?.brand_name ||
         'Unknown Sending Store'
}

const toStoreName = (order) => {
  return order.to_store_name ||
         order.store_branch?.name ||
         order.store_branch?.branch_name ||
         'Unknown Receiving Store'
}

const calculateTotalQuantity = (items) => {
  if (!items || !Array.isArray(items)) return 0
  return items.reduce((sum, item) => sum + (Number(item.quantity_ordered) || 0), 0)
}

const canCreateTransfer = computed(() => props.permissions.can_create)
const canEditTransfer = computed(() => props.permissions.can_edit)

// Watch for filter changes
watch([search, status, dateRange], () => {
  // Debounce search
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    applyFilters()
  }, 500)
})

let searchTimeout

</script>

<template>
  <Layout heading="Store-to-Store Transfers" :hasExcelDownload="true">
    <template #header-actions>
      <div class="flex gap-2">
        <Button v-if="canCreateTransfer" @click="router.get(route('interco.create'))">
          <Plus class="w-4 h-4 mr-2" />
          New Transfer
        </Button>
      </div>
    </template>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
      <Card
        v-for="stat in filteredStats"
        :key="stat.label"
        role="button"
        tabindex="0"
        class="cursor-pointer transition hover:shadow-md"
        :class="[
          stat.alert && stat.value > 0 ? 'border-orange-300 bg-orange-50' : '',
          status === stat.status ? 'ring-2 ring-primary' : '',
        ]"
        :title="stat.alert ? 'Transfers your store sent that were received short or over. Open one to Confirm or Re-Send.' : `Show ${stat.label}`"
        @click="showStatus(stat.status)"
        @keydown.enter="showStatus(stat.status)"
      >
        <CardContent class="p-4">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-muted-foreground">{{ stat.label }}</p>
              <p class="text-2xl font-bold" :class="stat.color">{{ stat.value }}</p>
            </div>
            <div
              class="w-8 h-8 shrink-0 rounded-full flex items-center justify-center"
              :class="stat.alert ? 'bg-orange-100' : 'bg-primary/10'"
            >
              <AlertCircle v-if="stat.alert" class="w-4 h-4 text-orange-600" />
              <ArrowLeftRight v-else class="w-4 h-4 text-primary" />
            </div>
          </div>
        </CardContent>
      </Card>
    </div>

    <!-- Transfer Summary (if there's an active transfer) -->
    <!-- This could be conditionally shown if there's a selected transfer -->

    <!-- Filters and Search -->
    <Card class="mb-6">
      <CardHeader>
        <CardTitle class="text-lg">Filters</CardTitle>
      </CardHeader>
      <CardContent>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <!-- Search -->
          <div class="relative">
            <Search class="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-muted-foreground" />
            <Input
              v-model="search"
              placeholder="Search transfers..."
              class="pl-10"
            />
          </div>

          <!-- Status Filter -->
          <Select v-model="status" :options="statusOptions">
            <SelectTrigger>
              <SelectValue placeholder="All Statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem :value="'all'">All Statuses</SelectItem>
              <SelectItem
                v-for="option in statusOptions"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }}
              </SelectItem>
            </SelectContent>
          </Select>

          <!-- Actions -->
          <div class="flex gap-2">
            <Button variant="outline" @click="clearFilters" :disabled="!hasActiveFilters">
              <RotateCcw class="w-4 h-4 mr-2" />
              Clear
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>

    <!-- Transfers Table -->
    <Card>
      <CardHeader>
        <CardTitle>Transfers</CardTitle>
        <p class="text-sm text-muted-foreground">
          Showing {{ orders.from }} to {{ orders.to }} of {{ orders.total }} transfers
        </p>
      </CardHeader>
      <CardContent>
        <!-- Desktop Table -->
        <div class="hidden md:block">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Transfer #</TableHead>
                <TableHead>From Store</TableHead>
                <TableHead>To Store</TableHead>
                <TableHead>Items</TableHead>
                <TableHead>Quantity</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Date</TableHead>
                <TableHead class="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="order in orders.data" :key="order.id">
                <TableCell class="font-medium">
                  {{ order.interco_number }}
                </TableCell>
                <TableCell>
                  {{ fromStoreName(order) }}
                </TableCell>
                <TableCell>
                  {{ toStoreName(order) }}
                </TableCell>
                <TableCell>
                  {{ order.store_order_items ? order.store_order_items.length : 0 }}
                </TableCell>
                <TableCell>
                  {{ calculateTotalQuantity(order.store_order_items) }}
                </TableCell>
                <TableCell>
                  <div class="flex flex-wrap items-center gap-2">
                    <TransferStatusBadge :status="order.interco_status" />
                    <Link
                      v-if="order.for_confirmation_count > 0"
                      :href="route('interco.show', order.id)"
                      class="inline-flex items-center gap-1 rounded-full border border-orange-200 bg-orange-50 px-2 py-0.5 text-xs font-medium text-orange-700 hover:bg-orange-100"
                      :title="confirmationTitle(order)"
                    >
                      <AlertCircle class="w-3 h-3" />
                      {{ confirmationLabel(order) }}
                    </Link>
                  </div>
                </TableCell>
                <TableCell>
                  {{ formatDate(order.order_date) }}
                </TableCell>
                <TableCell class="text-right">
                  <div class="flex justify-end gap-2">
                    <Button variant="ghost" size="sm" @click="router.get(route('interco.show', order.id))">
                      <Eye class="w-4 h-4" />
                    </Button>
                    <Button
                      v-if="canEditTransfer && order.interco_status === 'open'"
                      variant="ghost"
                      size="sm"
                      @click="router.get(route('interco.edit', order.id))"
                    >
                      <Edit class="w-4 h-4" />
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>

        <!-- Mobile Table -->
        <MobileTableContainer class="md:hidden">
          <div
            v-for="order in orders.data"
            :key="order.id"
            class="border-b last:border-b-0 p-4 space-y-3"
          >
            <!-- Header Row -->
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <span class="font-semibold text-sm">{{ order.interco_number }}</span>
                <TransferStatusBadge :status="order.interco_status" />
              </div>
              <div class="flex gap-1">
                <Button variant="ghost" size="sm" @click="router.get(route('interco.show', order.id))">
                  <Eye class="w-4 h-4" />
                </Button>
                <Button
                  v-if="canEditTransfer && order.interco_status === 'open'"
                  variant="ghost"
                  size="sm"
                  @click="router.get(route('interco.edit', order.id))"
                >
                  <Edit class="w-4 h-4" />
                </Button>
              </div>
            </div>

            <Link
              v-if="order.for_confirmation_count > 0"
              :href="route('interco.show', order.id)"
              class="inline-flex items-center gap-1 rounded-full border border-orange-200 bg-orange-50 px-2 py-0.5 text-xs font-medium text-orange-700"
              :title="confirmationTitle(order)"
            >
              <AlertCircle class="w-3 h-3" />
              {{ confirmationLabel(order) }}
            </Link>

            <!-- Store Information -->
            <div class="grid grid-cols-2 gap-3 text-sm">
              <div>
                <span class="text-muted-foreground text-xs">From:</span>
                <div class="font-medium">{{ fromStoreName(order) }}</div>
              </div>
              <div>
                <span class="text-muted-foreground text-xs">To:</span>
                <div class="font-medium">{{ toStoreName(order) }}</div>
              </div>
            </div>

            <!-- Order Details -->
            <div class="grid grid-cols-2 gap-3 text-sm">
              <div>
                <span class="text-muted-foreground text-xs">Items:</span>
                <div class="font-medium">{{ order.store_order_items ? order.store_order_items.length : 0 }}</div>
              </div>
              <div>
                <span class="text-muted-foreground text-xs">Quantity:</span>
                <div class="font-medium">
                  {{ calculateTotalQuantity(order.store_order_items) }}
                </div>
              </div>
            </div>

            <!-- Date Information -->
            <div class="flex items-center justify-between text-sm">
              <div>
                <span class="text-muted-foreground text-xs">Date:</span>
                <div class="font-medium">{{ formatDate(order.order_date) }}</div>
              </div>
            </div>
          </div>
        </MobileTableContainer>

        <!-- Empty State -->
        <div v-if="orders.data.length === 0" class="text-center py-12">
          <ArrowLeftRight class="w-12 h-12 mx-auto mb-4 text-muted-foreground" />
          <h3 class="text-lg font-medium mb-2">No transfers found</h3>
          <p class="text-muted-foreground mb-4">
            {{ hasActiveFilters ? 'Try adjusting your filters' : 'Get started by creating your first transfer' }}
          </p>
          <Button v-if="canCreateTransfer && !hasActiveFilters" @click="router.get(route('interco.create'))">
            <Plus class="w-4 h-4 mr-2" />
            Create Transfer
          </Button>
        </div>

        <!-- Pagination -->
        <div v-if="orders.data.length > 0" class="mt-6">
          <Pagination :data="orders" />
        </div>
      </CardContent>
    </Card>
  </Layout>
</template>

<style scoped>
/* Custom styles for better mobile responsiveness */
@media (max-width: 768px) {
  .grid-cols-5 {
    grid-template-columns: 1fr;
  }
}
</style>
