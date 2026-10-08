<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import Dialog from "primevue/dialog";
import { useToast } from "@/composables/useToast";

// A store explains what it still had pending after the MEC Scheduled Date: a reason for
// each pending, the action taken and, while anything is still pending, a target date.
const visible = defineModel('visible', { type: Boolean, default: false });

const props = defineProps({
    // { file_url, branch_name, count_label, count_date, pendings: [{ key, label, open }] }
    report: { type: Object, default: null },
});

const { toast } = useToast();

const reasons = ref({});
const actionTaken = ref('');
const targetDate = ref('');
const isFiling = ref(false);

const pendings = computed(() => props.report?.pendings ?? []);
const hasOpenPending = computed(() => pendings.value.some((pending) => pending.open));

const pad = (n) => String(n).padStart(2, '0');
const today = computed(() => {
    const d = new Date();
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
});

watch(visible, (open) => {
    if (open) {
        reasons.value = Object.fromEntries(pendings.value.map((pending) => [pending.key, '']));
        actionTaken.value = '';
        targetDate.value = '';
    }
});

const isComplete = computed(() =>
    pendings.value.every((pending) => (reasons.value[pending.key] ?? '').trim())
    && actionTaken.value.trim()
    && (!hasOpenPending.value || targetDate.value)
);

const submit = () => {
    if (!isComplete.value || !props.report) return;

    isFiling.value = true;
    router.post(props.report.file_url, {
        reasons: reasons.value,
        action_taken: actionTaken.value.trim(),
        target_date: targetDate.value || null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            visible.value = false;
            toast.add({ severity: 'success', summary: 'Filed', detail: 'Incident Report filed.', life: 3000 });
        },
        onError: (errors) => {
            const errorMsg = Object.values(errors)[0] || 'An unknown error occurred.';
            toast.add({ severity: 'error', summary: 'Filing Failed', detail: errorMsg, life: 5000 });
        },
        onFinish: () => { isFiling.value = false; },
    });
};
</script>

<template>
    <Dialog v-model:visible="visible" modal header="Incident Report" :style="{ width: '40rem' }">
        <div v-if="report" class="space-y-4" data-testid="mec-incident-report-dialog">
            <p class="text-sm text-gray-600">
                <strong>{{ report.branch_name }}</strong> still had unfinished transactions after the MEC Scheduled Date
                ({{ report.count_date }}) of the {{ report.count_label }} count. Give the reason for each one.
                The report cannot be changed after it is filed.
            </p>

            <div v-for="pending in pendings" :key="pending.key">
                <Label :for="`ir_reason_${pending.key}`">
                    {{ pending.label }}
                    <span class="ml-1 text-xs font-normal" :class="pending.open ? 'text-red-700' : 'text-gray-500'">
                        ({{ pending.open ? 'still pending' : 'finished' }})
                    </span>
                    <span class="text-red-600"> *</span>
                </Label>
                <Textarea :id="`ir_reason_${pending.key}`" v-model="reasons[pending.key]" rows="2" maxlength="1000" placeholder="Reason for the delay" class="mt-1" />
            </div>

            <div>
                <Label for="ir_action_taken">Action taken <span class="text-red-600">*</span></Label>
                <Textarea id="ir_action_taken" v-model="actionTaken" rows="3" maxlength="2000" placeholder="What has been done, or will be done, to finish these and to keep it from happening again?" class="mt-1" />
            </div>

            <div v-if="hasOpenPending">
                <Label for="ir_target_date">Target date to finish what is still pending <span class="text-red-600">*</span></Label>
                <Input id="ir_target_date" v-model="targetDate" type="date" :min="today" class="mt-1 w-full" />
            </div>

            <div class="flex justify-end gap-2">
                <Button type="button" variant="outline" @click="visible = false">Cancel</Button>
                <Button type="button" :disabled="!isComplete || isFiling" @click="submit">
                    <Loader2 v-if="isFiling" class="h-4 w-4 mr-2 animate-spin" />
                    File Incident Report
                </Button>
            </div>
        </div>
    </Dialog>
</template>
