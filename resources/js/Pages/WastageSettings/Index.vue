<script setup>
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
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
})

const { toast } = useToast()

const approvalLevelOptions = [
  { label: '1 level', value: '1' },
  { label: '2 levels', value: '2' },
]

const hasInFlightLevel2Records = computed(() => Boolean(props.config.has_in_flight_level2_records))

const save = () => {
  form.transform((data) => ({
    required_levels: Number(data.required_levels),
  })).post(route('wastage-settings.update'), {
    preserveScroll: true,
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

          <div class="flex justify-end">
            <Button :disabled="form.processing" @click="save">
              {{ form.processing ? 'Saving...' : 'Save Changes' }}
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  </Layout>
</template>
