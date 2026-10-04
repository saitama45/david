<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import Dialog from "primevue/dialog";
import { useToast } from "@/composables/useToast";

// Rejecting sends the count back and reopens the upload for this store. Level 1 and
// Level 2 share it; only the route the rejection is posted to differs.
const visible = defineModel('visible', { type: Boolean, default: false });

const props = defineProps({
    branchName: { type: String, required: true },
    action: { type: String, required: true },
});

const { toast } = useToast();

const rejectReason = ref('');
const reuploadUntil = ref('');
const isRejecting = ref(false);

// Same default grace period as the Store Progress reopen: 3 days out, 11:59 PM.
const defaultReuploadUntil = () => {
    const d = new Date();
    d.setDate(d.getDate() + 3);
    d.setHours(23, 59, 0, 0);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

watch(visible, (open) => {
    if (open) {
        rejectReason.value = '';
        reuploadUntil.value = defaultReuploadUntil();
    }
});

const submitReject = () => {
    if (!rejectReason.value.trim() || !reuploadUntil.value) return;

    isRejecting.value = true;
    router.post(props.action, {
        reason: rejectReason.value.trim(),
        reupload_until: reuploadUntil.value.replace('T', ' ') + ':00',
    }, {
        onSuccess: () => {
            visible.value = false;
            toast.add({ severity: 'success', summary: 'Rejected', detail: 'Count returned to the store for re-upload.', life: 3000 });
        },
        onError: (errors) => {
            const errorMsg = Object.values(errors)[0] || 'An unknown error occurred.';
            toast.add({ severity: 'error', summary: 'Rejection Failed', detail: errorMsg, life: 5000 });
        },
        onFinish: () => { isRejecting.value = false; },
    });
};
</script>

<template>
    <Dialog v-model:visible="visible" modal header="Reject Month End Count" :style="{ width: '32rem' }">
        <div class="space-y-4">
            <p class="text-sm text-gray-600">
                The count for <strong>{{ branchName }}</strong> goes back to the store. Uploading reopens for this
                store until the deadline below, even if the upload window has closed, and the new upload replaces this one.
            </p>
            <div>
                <Label for="reject_reason">Reason <span class="text-red-600">*</span></Label>
                <Textarea id="reject_reason" v-model="rejectReason" rows="3" maxlength="1000" placeholder="What should the store correct?" class="mt-1" />
            </div>
            <div>
                <Label for="reupload_until">Allow re-upload until <span class="text-red-600">*</span></Label>
                <Input id="reupload_until" v-model="reuploadUntil" type="datetime-local" class="mt-1 w-full" />
            </div>
            <div class="flex justify-end gap-2">
                <Button type="button" variant="outline" @click="visible = false">Cancel</Button>
                <Button
                    type="button"
                    class="bg-red-600 hover:bg-red-700 text-white"
                    :disabled="!rejectReason.trim() || !reuploadUntil || isRejecting"
                    @click="submitReject"
                >
                    <Loader2 v-if="isRejecting" class="h-4 w-4 mr-2 animate-spin" />
                    Reject and reopen upload
                </Button>
            </div>
        </div>
    </Dialog>
</template>
