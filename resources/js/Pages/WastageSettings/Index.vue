<script setup>
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import PrimeDialog from 'primevue/dialog'
import { AlertTriangle, ShieldCheck } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Select } from '@/components/ui/select'
import { useToast } from '@/composables/useToast'

const props = defineProps({
  config: {
    type: Object,
    required: true,
  },
})

const form = useForm({
  required_levels: String(props.config.required_levels || 2),
  allow_negative_stock: props.config.allow_negative_stock ?? true,
})

const { toast } = useToast()

const approvalLevelOptions = [
  { label: '1 level', value: '1' },
  { label: '2 levels', value: '2' },
]

const negativeStockOptions = [
  {
    value: true,
    title: 'Allow negative stock',
    description: 'Wastage can be approved even when the store does not have enough stock on hand (SOH). The SOH of those items goes below zero.',
  },
  {
    value: false,
    title: 'Block approval',
    description: 'Wastage cannot be approved while any item does not have enough stock on hand (SOH).',
  },
]

const hasInFlightLevel2Records = computed(() => Boolean(props.config.has_in_flight_level2_records))
const negativeStockChanged = computed(() => form.allow_negative_stock !== (props.config.allow_negative_stock ?? true))

const isConfirmOpen = ref(false)

const save = () => {
  isConfirmOpen.value = true
}

const confirmSave = () => {
  form.transform((data) => ({
    required_levels: Number(data.required_levels),
    allow_negative_stock: Boolean(data.allow_negative_stock),
  })).post(route('wastage-settings.update'), {
    preserveScroll: true,
    onFinish: () => {
      isConfirmOpen.value = false
    },
    onSuccess: (page) => {
      toast.add({
        severity: 'success',
        summary: 'Saved',
        detail: page.props.flash?.success || 'Wastage approval settings saved successfully.',
        life: 3000,
      })
    },
  })
}
</script>

<template>
  <Layout heading="Wastage Settings">
    <div class="mx-auto max-w-3xl space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>Approval Requirement</CardTitle>
        </CardHeader>
        <CardContent class="space-y-5">
          <div class="space-y-2">
            <Label>Required approval levels</Label>
            <Select
              v-model="form.required_levels"
              :options="approvalLevelOptions"
              optionLabel="label"
              optionValue="value"
              placeholder="Select approval levels"
              class="w-full sm:w-72"
            />
            <p class="text-sm text-muted-foreground">
              In 1-level mode, Level 1 approval is final and inventory is deducted immediately.
            </p>
          </div>

          <div v-if="hasInFlightLevel2Records" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Existing records are already approved at Level 1. Level 2 will stay visible until those records are completed or cancelled.
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <CardTitle>Negative Stock on Approval</CardTitle>
            <span
              class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide"
              :class="form.allow_negative_stock ? 'bg-red-600 text-white' : 'bg-gray-200 text-gray-700'"
            >
              {{ form.allow_negative_stock ? 'On - negative SOH allowed' : 'Off - approval blocked' }}
            </span>
          </div>
        </CardHeader>
        <CardContent class="space-y-5">
          <div role="radiogroup" aria-label="Negative stock on approval" class="grid gap-3 sm:grid-cols-2">
            <button
              v-for="option in negativeStockOptions"
              :key="String(option.value)"
              type="button"
              role="radio"
              :aria-checked="form.allow_negative_stock === option.value"
              class="rounded-lg border-2 p-4 text-left transition-colors"
              :class="form.allow_negative_stock === option.value
                ? (option.value ? 'border-red-500 bg-red-50' : 'border-blue-500 bg-blue-50')
                : 'border-gray-200 hover:border-gray-300'"
              @click="form.allow_negative_stock = option.value"
            >
              <span class="flex items-center gap-2 font-semibold" :class="option.value ? 'text-red-700' : 'text-gray-900'">
                <AlertTriangle v-if="option.value" class="h-4 w-4" />
                <ShieldCheck v-else class="h-4 w-4" />
                {{ option.title }}
              </span>
              <span class="mt-1 block text-sm text-gray-600">{{ option.description }}</span>
            </button>
          </div>

          <div v-if="form.allow_negative_stock" class="flex gap-3 rounded-md border-2 border-red-400 bg-red-50 px-4 py-3 text-sm text-red-900">
            <AlertTriangle class="mt-0.5 h-5 w-5 flex-shrink-0 text-red-600" />
            <div class="space-y-1">
              <p class="font-bold">Negative stock is allowed.</p>
              <p>
                Approving a wastage with more than the store has on hand will make that item's stock on hand
                <strong>negative</strong>. Level 1 and Level 2 approvers are shown which items will go negative and must
                confirm before the wastage is approved. Correct the stock (receiving, adjustment or month end count) afterwards.
              </p>
            </div>
          </div>
        </CardContent>
      </Card>

      <div class="flex justify-end">
        <Button :disabled="form.processing" @click="save">
          {{ form.processing ? 'Saving...' : 'Save Changes' }}
        </Button>
      </div>
    </div>

    <PrimeDialog
      v-model:visible="isConfirmOpen"
      modal
      header="Save Wastage Settings?"
      :closable="!form.processing"
      :style="{ width: '34rem' }"
      :breakpoints="{ '641px': '92vw' }"
    >
      <div class="space-y-4 text-sm">
        <p class="text-gray-700">
          Required approval levels:
          <strong>{{ form.required_levels === '1' ? '1 level' : '2 levels' }}</strong>
        </p>

        <div v-if="form.allow_negative_stock" class="flex gap-3 rounded-md border-2 border-red-400 bg-red-50 px-4 py-3 text-red-900">
          <AlertTriangle class="mt-0.5 h-5 w-5 flex-shrink-0 text-red-600" />
          <div class="space-y-1">
            <p class="font-bold uppercase">Negative stock will be allowed</p>
            <p>
              From now on, wastage approvals can make an item's stock on hand go <strong>below zero</strong> when the store
              does not have enough. Approvers will be warned and must confirm each time.
            </p>
          </div>
        </div>
        <div v-else class="flex gap-3 rounded-md border-2 border-blue-300 bg-blue-50 px-4 py-3 text-blue-900">
          <ShieldCheck class="mt-0.5 h-5 w-5 flex-shrink-0 text-blue-600" />
          <div class="space-y-1">
            <p class="font-bold uppercase">Negative stock will be blocked</p>
            <p>Wastage approvals will stop when any item does not have enough stock on hand.</p>
          </div>
        </div>

        <p v-if="negativeStockChanged" class="font-medium text-gray-900">
          This changes the negative stock setting for every store.
        </p>
      </div>

      <template #footer>
        <Button variant="outline" :disabled="form.processing" @click="isConfirmOpen = false">Cancel</Button>
        <Button
          :disabled="form.processing"
          :class="form.allow_negative_stock ? 'bg-red-600 hover:bg-red-700' : ''"
          @click="confirmSave"
        >
          {{ form.processing ? 'Saving...' : 'Yes, save settings' }}
        </Button>
      </template>
    </PrimeDialog>
  </Layout>
</template>
