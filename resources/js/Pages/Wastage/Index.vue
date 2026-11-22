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
import { Search, Filter, RotateCcw, Download, Plus, Eye, Edit, Trash2, CircleCheck } from 'lucide-vue-next'
import WastageStatusBadge from './components/WastageStatusBadge.vue'
import MobileTableContainer from '@/components/table/MobileTableContainer.vue'
import MobileTableRow from '@/components/table/MobileTableRow.vue'
import dayjs from 'dayjs'
import utc from 'dayjs/plugin/utc'
import timezone from 'dayjs/plugin/timezone'

dayjs.extend(utc)
dayjs.extend(timezone)

const props = defineProps({
  wastages: Object,
  statistics: Object,
  filters: Object,
  statusOptions: Array,
  storeOptions: Array,
  permissions: Object,
  approvalConfig: Object,
  // "No Wastage for the Day" statements of the user's stores; only sent on that tab
  noWastageDays: { type: Object, default: null },
  noWastageCount: { type: Number, default: 0 },
})

// Local state
const search = ref(props.filters.search || '')
const status = ref(props.filters.status || 'pending')
const dateRange = ref('')
const tab = ref(props.filters.tab === 'no_wastage' ? 'no_wastage' : 'records')
const noWastageStore = ref(props.filters.no_wastage_store ? String(props.filters.no_wastage_store) : 'all')
const noWastageDate = ref(props.filters.no_wastage_date || '')

// Watch for status changes to apply filters
watch(status, () => {
  applyFilters()
})

watch([noWastageStore, noWastageDate], () => {
  applyFilters()
})

const switchTab = (value) => {
  if (tab.value === value) return
  tab.value = value
  applyFilters()
}

const hasNoWastageFilters = computed(() => noWastageStore.value !== 'all' || !!noWastageDate.value)

const clearNoWastageFilters = () => {
  noWastageStore.value = 'all'
  noWastageDate.value = ''
}

const formatDay = (date) => date ? dayjs(date).format('MM/DD/YYYY') : 'N/A'

// Computed properties
const hasActiveFilters = computed(() => {
  return search.value ||
         (status.value && status.value !== 'all') ||
         dateRange.value
})

const filteredStats = computed(() => {
  const stats = props.statistics ? props.statistics : {}
  const rows = [
    { label: 'Total Records', value: stats.total ? stats.total : 0, color: 'text-blue-600' },
    { label: 'Pending', value: stats.pending ? stats.pending : 0, color: 'text-yellow-600' },
    { label: 'Approved L1', value: stats.approved_lvl1 ? stats.approved_lvl1 : 0, color: 'text-blue-600' },
    { label: props.approvalConfig?.is_one_level_mode ? 'Approved' : 'Approved L2', value: stats.approved_lvl2 ? stats.approved_lvl2 : 0, color: 'text-green-600' },
    { label: 'Cancelled', value: stats.cancelled ? stats.cancelled : 0, color: 'text-red-600' }
  ]

  return props.approvalConfig?.show_level2 === false
    ? rows.filter((row) => row.label !== 'Approved L1')
    : rows
})

// Methods
const applyFilters = () => {
  const params = {}

  if (search.value) params.search = search.value
  if (status.value) params.status = status.value
  if (dateRange.value) params.date_range = dateRange.value
  if (tab.value === 'no_wastage') {
    params.tab = 'no_wastage'
    if (noWastageStore.value !== 'all') params.no_wastage_store = noWastageStore.value
    if (noWastageDate.value) params.no_wastage_date = noWastageDate.value
  }

  router.get(route('wastage.index'), params, {
    preserveState: true,
    preserveScroll: true
  })
}

const clearFilters = () => {
  search.value = ''
  status.value = 'pending'
  dateRange.value = ''

  router.get(route('wastage.index'), {}, {
    preserveState: true,
    preserveScroll: true
  })
}

const exportData = () => {
  const params = {}

  if (search.value) params.search = search.value
  if (status.value) params.status = status.value
  if (dateRange.value) params.date_range = dateRange.value

  window.open(route('wastage.export', params), '_blank')
}

const formatDate = (dateString) => {
  if (!dateString) return 'N/A'
  
  // Use dayjs with Manila timezone to ensure consistency across environments
  // This prevents the "added 1 day" issue by explicitly treating the date in Manila time
  return dayjs.tz(dateString, "Asia/Manila").format('MM/DD/YYYY hh:mm A')
}

const formatCurrency = (amount) => {
  if (!amount) return '₱0.00'
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP'
  }).format(amount)
}

const formatQty = (qty) => {
    if (qty === null || qty === undefined) return '0.000';
    return Number(qty).toFixed(3);
};

const getStoreName = (wastage) => {
  return wastage.storeBranch?.name ||
         wastage.store_branch?.name ||
         'Unknown Store'
}

const getStatusOption = (statusValue) => {
  return props.statusOptions?.find(option => option.value === statusValue) ||
         { label: statusValue, color: 'text-gray-600', bg_color: 'bg-gray-100' }
}

const canCreateRecord = computed(() => props.permissions.can_create)
const canEditRecord = computed(() => props.permissions.can_edit)
const canDeleteRecord = computed(() => props.permissions.can_delete)
const canExportRecords = computed(() => props.permissions.can_export)
const canViewCost = computed(() => props.permissions.can_view_cost)

// Check if a specific wastage record can be edited
const canEditWastageRecord = (wastage) => {
  // Only PENDING records can be edited
  return wastage.wastage_status === 'pending' && canEditRecord.value
}

const deleteRecord = (wastage) => {
  if (confirm('Are you sure you want to delete this wastage record? This action cannot be undone.')) {
    router.delete(route('wastage.destroy', wastage.id), {
      preserveScroll: true,
      onSuccess: () => {
        // Success message will be shown via flash message
      }
    })
  }
}

// Watch for filter changes (search and dateRange only, status has its own watcher)
watch([search, dateRange], () => {
  // Debounce search
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    applyFilters()
  }, 500)
})

let searchTimeout

</script>

<template>
  <Layout heading="Wastage Records" :hasExcelDownload="canExportRecords">
    <template #header-actions>
      <div class="flex gap-2">
        
        <Button v-if="canCreateRecord" @click="router.get(route('wastage.create'))">
          <Plus class="w-4 h-4 mr-2" />
          New Wastage Record
        </Button>
      </div>
    </template>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
      <Card v-for="stat in filteredStats" :key="stat.label">
        <CardContent class="p-4">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm font-medium text-muted-foreground">{{ stat.label }}</p>
              <p class="text-2xl font-bold" :class="stat.color">{{ stat.value }}</p>
            </div>
            <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
              <Trash2 class="w-4 h-4 text-primary" />
            </div>
          </div>
        </CardContent>
      </Card>
    </div>

    <!-- Wastage records, or the days stores marked "No Wastage for the Day" -->
    <div class="mb-4 inline-flex flex-wrap gap-1 rounded-lg border bg-white p-1" role="tablist">
      <button
        type="button"
        role="tab"
        :aria-selected="tab === 'records'"
        class="rounded-md px-4 py-2 text-sm font-medium transition-colors"
        :class="tab === 'records' ? 'bg-primary text-primary-foreground' : 'text-gray-600 hover:bg-gray-100'"
        @click="switchTab('records')"
      >
        Wastage Records
      </button>
      <button
        type="button"
        role="tab"
        :aria-selected="tab === 'no_wastage'"
        class="flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors"
        :class="tab === 'no_wastage' ? 'bg-primary text-primary-foreground' : 'text-gray-600 hover:bg-gray-100'"
        @click="switchTab('no_wastage')"
      >
        No Wastage Days
        <span
          class="rounded-full px-2 py-0.5 text-xs font-semibold"
          :class="tab === 'no_wastage' ? 'bg-white/20' : 'bg-emerald-100 text-emerald-700'"
        >{{ noWastageCount }}</span>
      </button>
    </div>

    <!-- No Wastage Days: filters and list -->
    <template v-if="tab === 'no_wastage'">
      <Card class="mb-6">
        <CardHeader>
          <CardTitle class="text-lg">Filters</CardTitle>
        </CardHeader>
        <CardContent>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <Select v-model="noWastageStore">
              <SelectTrigger>
                <SelectValue placeholder="All Stores" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Stores</SelectItem>
                <SelectItem v-for="store in storeOptions" :key="store.value" :value="String(store.value)">
                  {{ store.label }}
                </SelectItem>
              </SelectContent>
            </Select>

            <Input v-model="noWastageDate" type="date" aria-label="Wastage date" />

            <div class="flex gap-2">
              <Button variant="outline" @click="clearNoWastageFilters" :disabled="!hasNoWastageFilters">
                <RotateCcw class="w-4 h-4 mr-2" />
                Clear
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>No Wastage Days</CardTitle>
          <p class="text-sm text-muted-foreground">
            Days a store marked "No Wastage for the Day" on Create Wastage Record. No wastage can be recorded for a marked day. A day marked by mistake is unmarked with Edit, and leaves this list.
          </p>
        </CardHeader>
        <CardContent>
          <div v-if="noWastageDays?.data?.length" class="hidden md:block">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Wastage Date</TableHead>
                  <TableHead>Store</TableHead>
                  <TableHead>Marked By</TableHead>
                  <TableHead>Marked On</TableHead>
                  <TableHead v-if="canCreateRecord" class="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow v-for="day in noWastageDays.data" :key="day.id">
                  <TableCell class="font-medium">{{ formatDay(day.wastage_date) }}</TableCell>
                  <TableCell>{{ day.store }}</TableCell>
                  <TableCell>{{ day.declared_by }}</TableCell>
                  <TableCell>{{ day.declared_at }}</TableCell>
                  <TableCell v-if="canCreateRecord" class="text-right">
                    <Button variant="ghost" size="sm" title="Edit: unmark this day" @click="router.get(route('wastage.no-wastage.edit', day.id))">
                      <Edit class="w-4 h-4" />
                    </Button>
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>

          <MobileTableContainer v-if="noWastageDays?.data?.length" class="md:hidden">
            <div v-for="day in noWastageDays.data" :key="day.id" class="border-b last:border-b-0 p-4 space-y-1 text-sm">
              <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 font-semibold">
                  <CircleCheck class="w-4 h-4 text-emerald-600" />
                  {{ formatDay(day.wastage_date) }}
                </div>
                <Button v-if="canCreateRecord" variant="ghost" size="sm" title="Edit: unmark this day" @click="router.get(route('wastage.no-wastage.edit', day.id))">
                  <Edit class="w-4 h-4" />
                </Button>
              </div>
              <div>{{ day.store }}</div>
              <div class="text-xs text-muted-foreground">Marked by {{ day.declared_by }} on {{ day.declared_at }}</div>
            </div>
          </MobileTableContainer>

          <div v-if="!noWastageDays?.data?.length" class="text-center py-12">
            <CircleCheck class="w-12 h-12 mx-auto mb-4 text-muted-foreground" />
            <h3 class="text-lg font-medium mb-2">No days marked No Wastage</h3>
            <p class="text-muted-foreground">
              {{ hasNoWastageFilters ? 'Try adjusting your filters' : 'A store marks a day on Create Wastage Record, under the Wastage Date.' }}
            </p>
          </div>

          <div v-if="noWastageDays?.data?.length" class="mt-6">
            <Pagination :data="noWastageDays" />
          </div>
        </CardContent>
      </Card>
    </template>

    <template v-else>
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
              placeholder="Search records..."
              class="pl-10"
            />
          </div>

          <!-- Status Filter -->
          <Select v-model="status" :options="statusOptions">
            <SelectTrigger>
              <SelectValue placeholder="All Statuses" />
            </SelectTrigger>
            <SelectContent>
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

    <!-- Wastage Records Table -->
    <Card>
      <CardHeader>
        <CardTitle>Wastage Records</CardTitle>
        <p class="text-sm text-muted-foreground">
          Showing {{ wastages.from }} to {{ wastages.to }} of {{ wastages.total }} records
        </p>
      </CardHeader>
      <CardContent>
        <!-- Desktop Table -->
        <div class="hidden md:block">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Wastage #</TableHead>
                <TableHead>Store</TableHead>
                <TableHead>Total Qty</TableHead>
                <TableHead>Items</TableHead>
                <TableHead v-if="canViewCost">Total Cost</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Date</TableHead>
                <TableHead class="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="wastage in wastages.data" :key="wastage.id">
                <TableCell class="font-medium">
                  {{ wastage.wastage_no }}
                </TableCell>
                <TableCell>
                  {{ getStoreName(wastage) }}
                </TableCell>
                <TableCell>
                  {{ formatQty(wastage.total_quantity) }}
                </TableCell>
                <TableCell>
                  {{ wastage.items_count }}
                </TableCell>
                <TableCell v-if="canViewCost">
                  {{ formatCurrency(wastage.total_cost) }}
                </TableCell>
                <TableCell>
                  <Badge :class="getStatusOption(wastage.wastage_status).bg_color + ' ' + getStatusOption(wastage.wastage_status).color">
                    {{ wastage.status_label }}
                  </Badge>
                </TableCell>
                <TableCell>
                  {{ formatDate(wastage.encoded_date) }}
                </TableCell>
                <TableCell class="text-right">
                  <div class="flex justify-end gap-2">
                    <Button variant="ghost" size="sm" @click="router.get(route('wastage.show', wastage.id))">
                      <Eye class="w-4 h-4" />
                    </Button>
                    <Button
                      v-if="canEditWastageRecord(wastage)"
                      variant="ghost"
                      size="sm"
                      @click="router.get(route('wastage.edit', wastage.id))"
                    >
                      <Edit class="w-4 h-4" />
                    </Button>
                    <Button
                      v-if="canDeleteRecord && wastage.wastage_status === 'pending'"
                      variant="ghost"
                      size="sm"
                      @click="deleteRecord(wastage)"
                      class="text-red-600 hover:text-red-700 hover:bg-red-50"
                    >
                      <Trash2 class="w-4 h-4" />
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
            v-for="wastage in wastages.data"
            :key="wastage.id"
            class="border-b last:border-b-0 p-4 space-y-3"
          >
            <!-- Header Row -->
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <span class="font-semibold text-sm">{{ wastage.wastage_no }}</span>
                <Badge :class="getStatusOption(wastage.wastage_status).bg_color + ' ' + getStatusOption(wastage.wastage_status).color">
                  {{ wastage.status_label }}
                </Badge>
              </div>
              <div class="flex gap-1">
                <Button variant="ghost" size="sm" @click="router.get(route('wastage.show', wastage.id))">
                  <Eye class="w-4 h-4" />
                </Button>
                <Button
                  v-if="canEditWastageRecord(wastage)"
                  variant="ghost"
                  size="sm"
                  @click="router.get(route('wastage.edit', wastage.id))"
                >
                  <Edit class="w-4 h-4" />
                </Button>
                <Button
                  v-if="canDeleteRecord && wastage.wastage_status === 'PENDING'"
                  variant="ghost"
                  size="sm"
                  @click="deleteRecord(wastage)"
                  class="text-red-600 hover:text-red-700 hover:bg-red-50"
                >
                  <Trash2 class="w-4 h-4" />
                </Button>
              </div>
            </div>

            <!-- Store Information -->
            <div class="text-sm">
              <span class="text-muted-foreground text-xs">Store:</span>
              <div class="font-medium">{{ getStoreName(wastage) }}</div>
            </div>

            <!-- Quantity and Items Info -->
            <div class="grid grid-cols-2 gap-3 text-sm">
              <div>
                <span class="text-muted-foreground text-xs">Total Qty:</span>
                <div class="font-medium">{{ formatQty(wastage.total_quantity) }}</div>
              </div>
              <div>
                <span class="text-muted-foreground text-xs">Items:</span>
                <div class="font-medium">{{ wastage.items_count }}</div>
              </div>
            </div>

            <!-- Total Cost -->
            <div class="text-sm" v-if="canViewCost">
              <span class="text-muted-foreground text-xs">Total Cost:</span>
              <div class="font-medium">{{ formatCurrency(wastage.total_cost) }}</div>
            </div>

            <!-- Date Information -->
            <div class="flex items-center justify-between text-sm">
              <div>
                <span class="text-muted-foreground text-xs">Date:</span>
                <div class="font-medium">{{ formatDate(wastage.encoded_date) }}</div>
              </div>
            </div>
          </div>
        </MobileTableContainer>

        <!-- Empty State -->
        <div v-if="wastages.data.length === 0" class="text-center py-12">
          <Trash2 class="w-12 h-12 mx-auto mb-4 text-muted-foreground" />
          <h3 class="text-lg font-medium mb-2">No wastage records found</h3>
          <p class="text-muted-foreground mb-4">
            {{ hasActiveFilters ? 'Try adjusting your filters' : 'Get started by creating your first wastage record' }}
          </p>
          <Button v-if="canCreateRecord && !hasActiveFilters" @click="router.get(route('wastage.create'))">
            <Plus class="w-4 h-4 mr-2" />
            Create Wastage Record
          </Button>
        </div>

        <!-- Pagination -->
        <div v-if="wastages.data.length > 0" class="mt-6">
          <Pagination :data="wastages" />
        </div>
      </CardContent>
    </Card>
    </template>
  </Layout>
</template>

<style scoped>
/* Custom styles for better mobile responsiveness */
@media (max-width: 768px) {
  .grid-cols-4 {
    grid-template-columns: 1fr;
  }
}
</style>
