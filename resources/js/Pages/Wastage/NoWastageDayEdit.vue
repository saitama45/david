<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ArrowLeft, CircleCheck, Plus, Undo2 } from 'lucide-vue-next'

// One day a store marked "No Wastage for the Day", opened with Edit from the No Wastage Days tab.
const props = defineProps({
  day: { type: Object, required: true },
  // Why Unmark is not offered for this marked day, e.g. a date closed by the month end count
  unmarkProblem: { type: String, default: null },
})

const confirm = useConfirm()
const toast = useToast()
const unmarking = ref(false)

const backToList = () => router.get(route('wastage.index', { tab: 'no_wastage' }))

const recordWastage = () => router.get(route('wastage.create', {
  store_branch_id: props.day.store_branch_id,
  wastage_date: props.day.wastage_date,
}))

const unmark = () => {
  confirm.require({
    header: 'Unmark No Wastage',
    message: `Unmark ${props.day.store} for ${props.day.wastage_date_label}? Do this only if the day was marked by mistake. Wastage can then be recorded for that day.`,
    icon: 'pi pi-undo',
    rejectProps: { label: 'Cancel', severity: 'secondary', outlined: true, size: 'small' },
    acceptProps: { label: 'Yes, unmark', severity: 'warn', size: 'small' },
    accept: () => {
      unmarking.value = true
      router.post(route('wastage.no-wastage.unmark'), {
        store_branch_id: props.day.store_branch_id,
        wastage_date: props.day.wastage_date,
      }, {
        preserveScroll: true,
        onSuccess: () => {
          toast.add({ severity: 'success', summary: 'Unmarked', detail: `${props.day.store}: wastage can now be recorded for ${props.day.wastage_date_label}.`, life: 4000 })
        },
        onError: (errors) => {
          toast.add({ severity: 'error', summary: 'Not unmarked', detail: errors.wastage_date || errors.store_branch_id || 'Could not unmark the day.', life: 5000 })
        },
        onFinish: () => {
          unmarking.value = false
        },
      })
    },
  })
}
</script>

<template>
  <Layout heading="Edit No Wastage Day">
    <template #header-actions>
      <Button variant="outline" @click="backToList">
        <ArrowLeft class="w-4 h-4 mr-2" />
        Back to No Wastage Days
      </Button>
    </template>

    <div class="mx-auto max-w-3xl space-y-4">
      <Card>
        <CardHeader>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <CardTitle>{{ day.store }}</CardTitle>
            <span
              class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wide"
              :class="day.marked ? 'bg-emerald-600 text-white' : 'bg-gray-200 text-gray-700'"
            >
              {{ day.marked ? 'Marked No Wastage' : 'Unmarked' }}
            </span>
          </div>
        </CardHeader>
        <CardContent class="space-y-5">
          <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
            <div>
              <dt class="text-muted-foreground">Wastage Date</dt>
              <dd class="font-medium">{{ day.wastage_date_label }}</dd>
            </div>
            <div>
              <dt class="text-muted-foreground">Store</dt>
              <dd class="font-medium">{{ day.store }}</dd>
            </div>
            <div>
              <dt class="text-muted-foreground">Marked By</dt>
              <dd class="font-medium">{{ day.declared_by }}</dd>
            </div>
            <div>
              <dt class="text-muted-foreground">Marked On</dt>
              <dd class="font-medium">{{ day.declared_at }}</dd>
            </div>
            <template v-if="!day.marked">
              <div>
                <dt class="text-muted-foreground">Unmarked By</dt>
                <dd class="font-medium">{{ day.unmarked_by }}</dd>
              </div>
              <div>
                <dt class="text-muted-foreground">Unmarked On</dt>
                <dd class="font-medium">{{ day.unmarked_at }}</dd>
              </div>
            </template>
          </dl>

          <!-- Still marked: no wastage can be recorded for the day until it is unmarked -->
          <div v-if="day.marked" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-semibold">Marked by mistake?</p>
            <p class="mt-1">
              While the day is marked, no wastage can be recorded for this store on {{ day.wastage_date_label }}.
              Unmark it to record the wastage.
            </p>
            <p v-if="unmarkProblem" class="mt-2 font-medium text-red-700">{{ unmarkProblem }}</p>
            <Button
              v-else
              class="mt-3 bg-amber-600 hover:bg-amber-700"
              :disabled="unmarking"
              @click="unmark"
            >
              <Undo2 class="w-4 h-4 mr-2" />
              {{ unmarking ? 'Unmarking...' : 'Unmark' }}
            </Button>
          </div>

          <!-- Unmarked: the day is open for a wastage record again -->
          <div v-else class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            <p class="flex items-center gap-2 font-semibold">
              <CircleCheck class="h-4 w-4" />
              This day is open for wastage again.
            </p>
            <p class="mt-1">It no longer shows under No Wastage Days. Record the wastage for this store and date.</p>
            <Button class="mt-3 bg-green-600 hover:bg-green-700" @click="recordWastage">
              <Plus class="w-4 h-4 mr-2" />
              Record wastage for this day
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  </Layout>
</template>
