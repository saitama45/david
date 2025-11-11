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
import { ArrowLeft, Package, Calendar, User, CheckCircle, AlertTriangle } from 'lucide-vue-next'
import WastageStatusBadge from './Components/WastageStatusBadge.vue'

const props = defineProps({
  wastage: Object,
  permissions: Object,
  statusTransitions: Array
})


// Computed properties
const wastageNumber = computed(() => props.wastage.wastage_no || 'N/A')
const status = computed(() => props.wastage.wastage_status || 'pending')
const storeName = computed(() => {
  return props.wastage.storeBranch?.name ||
         props.wastage.storeBranch?.branch_name ||
         'Unknown Store'
})
const createdDate = computed(() => new Date(props.wastage.created_at).toLocaleDateString())
const reason = computed(() => props.wastage.remarks || 'No remarks provided')

// Format date and time
const formatDateTime = (dateString) => {
  if (!dateString) return 'N/A'
  const date = new Date(dateString)

  // Format date: MM/DD/YYYY
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const year = date.getFullYear()

  // Format time: HH:MM A.M./P.M.
  let hours = date.getHours()
  const minutes = String(date.getMinutes()).padStart(2, '0')
  const ampm = hours >= 12 ? 'P.M.' : 'A.M.'
  hours = hours % 12
  hours = hours ? hours : 12 // 0 should be 12

  return `${month}/${day}/${year} ${String(hours).padStart(2, '0')}:${minutes} ${ampm}`
}

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

// Wastage statistics
const totalItems = computed(() => {
  if (!props.wastage?.items || !Array.isArray(props.wastage.items)) return 0
  const total = props.wastage.items.reduce((total, item) => total + (Number(item.wastage_qty) || 0), 0)
  return Number(total) || 0
})

const totalCost = computed(() => {
  if (!props.wastage?.items || !Array.isArray(props.wastage.items)) return 0
  const total = props.wastage.items.reduce((total, item) => {
    const qty = Number(item.wastage_qty) || 0
    const cost = Number(item.cost) || 0
    return total + (qty * cost)
  }, 0)
  return Number(total) || 0
})

// Format currency
const formatCurrency = (amount) => {
  if (!amount) return '₱0.00'
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP'
  }).format(amount)
}

</script>

<template>
  <Layout heading="Wastage Record Details">
    <template #header-actions>
      <div class="flex items-center gap-2">
        <Button variant="outline" @click="router.get(route('wastage.index'))">
          <ArrowLeft class="w-4 h-4 mr-2" />
          Back to Wastage Records
        </Button>
      </div>
    </template>

    <!-- Main Content -->
    <div class="space-y-6">
      <!-- Header Card -->
      <Card>
        <CardContent class="p-6">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center gap-4">
              <div class="p-3 bg-blue-100 rounded-lg">
                <Package class="w-6 h-6 text-blue-600" />
              </div>
              <div>
                <h1 class="text-2xl font-bold text-gray-900">Wastage Record #{{ wastageNumber }}</h1>
                <div class="flex items-center gap-2 mt-1">
                  <Calendar class="w-4 h-4 text-gray-500" />
                  <span class="text-sm text-gray-600">Created on {{ createdDate }}</span>
                </div>
              </div>
            </div>
            <WastageStatusBadge :status="status" />
          </div>
        </CardContent>
      </Card>

      <!-- Details Grid -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Store Information -->
        <Card>
          <CardHeader>
            <CardTitle class="flex items-center gap-2">
              <Package class="w-5 h-5" />
              Store Information
            </CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div>
              <label class="text-sm font-medium text-gray-500">Store Branch</label>
              <p class="text-lg font-semibold">{{ storeName }}</p>
            </div>
            <div>
              <label class="text-sm font-medium text-gray-500">Remarks</label>
              <p class="text-gray-900">{{ reason }}</p>
            </div>
          </CardContent>
        </Card>

        <!-- Statistics -->
        <Card>
          <CardHeader>
            <CardTitle class="flex items-center gap-2">
              <Package class="w-5 h-5" />
              Wastage Summary
            </CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="flex justify-between items-center">
              <span class="text-gray-600">Total Items</span>
              <span class="text-2xl font-bold">{{ totalItems }}</span>
            </div>
            <Separator />
            <div class="flex justify-between items-center">
              <span class="text-gray-600">Total Cost</span>
              <span class="text-2xl font-bold text-green-600">{{ formatCurrency(totalCost) }}</span>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Items Table -->
      <Card>
        <CardHeader>
          <CardTitle>Wastage Items</CardTitle>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Item</TableHead>
                <TableHead>Reason</TableHead>
                <TableHead class="text-center">Quantity</TableHead>
                <TableHead class="text-center">Approved Lvl1 Qty</TableHead>
                <TableHead class="text-center">Approved Lvl2 Qty</TableHead>
                <TableHead class="text-right">Cost</TableHead>
                <TableHead class="text-right">Total</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="wastage.items && wastage.items.length > 0" v-for="item in wastage.items" :key="item.id">
                <TableCell>
                  <div>
                    <div class="font-medium">{{ item.sap_masterfile?.ItemCode || 'N/A' }}</div>
                    <div class="text-sm text-gray-500">{{ item.sap_masterfile?.ItemDescription || 'No description' }}</div>
                  </div>
                </TableCell>
                <TableCell class="text-sm">{{ item.reason || 'No reason specified' }}</TableCell>
                <TableCell class="text-center">{{ item.wastage_qty }} {{ (item.sap_masterfile?.AltUOM || item.sap_masterfile?.BaseUOM) ?? 'PCS' }}</TableCell>
                <TableCell class="text-center">{{ item.approverlvl1_qty ?? 0 }} {{ (item.sap_masterfile?.AltUOM || item.sap_masterfile?.BaseUOM) ?? 'PCS' }}</TableCell>
                <TableCell class="text-center">{{ item.approverlvl2_qty ?? 0 }} {{ (item.sap_masterfile?.AltUOM || item.sap_masterfile?.BaseUOM) ?? 'PCS' }}</TableCell>
                <TableCell class="text-right">{{ formatCurrency(item.cost) }}</TableCell>
                <TableCell class="text-right font-semibold">{{ formatCurrency(item.wastage_qty * item.cost) }}</TableCell>
              </TableRow>
              <TableRow v-else>
                <TableCell colspan="7" class="text-center py-8 text-gray-500">
                  No items found
                </TableCell>
              </TableRow>
            </TableBody>
            <tfoot v-if="wastage.items && wastage.items.length > 0">
              <TableRow>
                <TableCell colspan="6" class="text-right font-bold">Total:</TableCell>
                <TableCell class="text-right font-bold text-green-600">{{ formatCurrency(totalCost) }}</TableCell>
              </TableRow>
            </tfoot>
          </Table>
        </CardContent>
      </Card>

      <!-- Approval Workflow -->
      <Card>
        <CardHeader>
          <CardTitle class="flex items-center gap-2">
            <User class="w-5 h-5" />
            Approval Workflow
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div class="space-y-4">
            <!-- Created By -->
            <div class="flex items-center gap-3">
              <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                <User class="w-4 h-4 text-blue-600" />
              </div>
              <div>
                <div class="font-medium">{{ formatUserName(wastage.encoder) }}</div>
                <div class="text-sm text-gray-500">Created on {{ formatDateTime(wastage.created_at) }}</div>
              </div>
            </div>

            <!-- Level 1 Approval -->
            <div v-if="wastage.approver1" class="flex items-center gap-3">
              <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                <CheckCircle class="w-4 h-4 text-green-600" />
              </div>
              <div>
                <div class="font-medium">{{ formatUserName(wastage.approver1) }}</div>
                <div class="text-sm text-gray-500">Approved Level 1 on {{ formatDateTime(wastage.approved_level1_date) }}</div>
              </div>
            </div>

            <!-- Level 2 Approval -->
            <div v-if="wastage.approver2" class="flex items-center gap-3">
              <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                <CheckCircle class="w-4 h-4 text-green-600" />
              </div>
              <div>
                <div class="font-medium">{{ formatUserName(wastage.approver2) }}</div>
                <div class="text-sm text-gray-500">Approved Level 2 on {{ formatDateTime(wastage.approved_level2_date) }}</div>
              </div>
            </div>

            <!-- Cancelled By -->
            <div v-if="wastage.canceller" class="flex items-center gap-3">
              <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center">
                <AlertTriangle class="w-4 h-4 text-red-600" />
              </div>
              <div>
                <div class="font-medium">{{ formatUserName(wastage.canceller) }}</div>
                <div class="text-sm text-gray-500">Cancelled on {{ formatDateTime(wastage.cancelled_date) }}</div>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  </Layout>
</template>