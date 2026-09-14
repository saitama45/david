<script setup>
/**
 * "Request a business-rule exception" dialog.
 *
 * Opened from any screen where a rule blocks the user (or, for excuse rules,
 * where an item was scored late). It asks the server whether an exception can
 * be requested for this exact subject, then collects the reason, justification
 * and optional evidence. Every check is repeated on the server on submit.
 *
 * Subject fields depend on the rule, e.g. { order_number } or
 * { supplier_code, order_date, store_branch_id }. When `storeOptions` is given
 * the user picks the store here and it is sent as store_branch_id.
 */
import { ref, computed, watch } from "vue";
import axios from "axios";
import { router } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";

const props = defineProps({
    ruleKey: { type: String, required: true },
    subject: { type: Object, default: () => ({}) },
    storeOptions: { type: Array, default: null },
    // Ask for the delivery date here (sent as order_date) instead of taking it from `subject`.
    askDate: { type: Boolean, default: false },
    title: { type: String, default: "Request a business-rule exception" },
});

const open = defineModel("open", { type: Boolean, default: false });
const emit = defineEmits(["submitted"]);
const toast = useToast();

const storeBranchId = ref(null);
const orderDate = ref("");
const loading = ref(false);
const eligibility = ref(null);
const submitting = ref(false);
const errors = ref({});
const form = ref({ reason_code: "", justification: "", attachment: null });

const needsStore = computed(() => Array.isArray(props.storeOptions));
const subjectPayload = computed(() => ({
    ...props.subject,
    ...(needsStore.value ? { store_branch_id: storeBranchId.value } : {}),
    ...(props.askDate ? { order_date: orderDate.value } : {}),
}));

const reasonOptions = computed(() =>
    Object.entries(eligibility.value?.reasons ?? {}).map(([value, label]) => ({ value, label }))
);
const attachmentRequired = computed(() =>
    (eligibility.value?.attachment_required_reasons ?? []).includes(form.value.reason_code)
);
const minLength = computed(() => eligibility.value?.justification_min ?? 20);
const canSubmit = computed(() =>
    eligibility.value?.can_request
    && form.value.reason_code
    && form.value.justification.trim().length >= minLength.value
    && (!attachmentRequired.value || form.value.attachment)
    && !submitting.value
);

const checkEligibility = async () => {
    if ((needsStore.value && !storeBranchId.value) || (props.askDate && !orderDate.value)) {
        eligibility.value = null;
        return;
    }

    loading.value = true;
    errors.value = {};

    try {
        const { data } = await axios.get(route("rule-exceptions.eligibility"), {
            params: { rule_key: props.ruleKey, ...subjectPayload.value },
        });
        eligibility.value = data;
    } catch (e) {
        eligibility.value = {
            can_request: false,
            reason: e.response?.data?.message || "Could not check whether an exception can be requested.",
        };
    } finally {
        loading.value = false;
    }
};

watch(open, (isOpen) => {
    if (!isOpen) return;

    form.value = { reason_code: "", justification: "", attachment: null };
    storeBranchId.value = needsStore.value && props.storeOptions.length === 1 ? props.storeOptions[0].value : null;
    orderDate.value = props.subject.order_date ?? "";
    eligibility.value = null;
    checkEligibility();
});

watch([storeBranchId, orderDate], () => open.value && checkEligibility());

const onFile = (event) => {
    form.value.attachment = event.target.files?.[0] ?? null;
};

const submit = () => {
    if (!canSubmit.value) return;

    submitting.value = true;
    errors.value = {};

    router.post(
        route("rule-exceptions.store"),
        {
            rule_key: props.ruleKey,
            ...subjectPayload.value,
            reason_code: form.value.reason_code,
            justification: form.value.justification,
            attachment: form.value.attachment,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                toast.add({
                    severity: "success",
                    summary: "Request submitted",
                    detail: page.props.flash?.success || "Your exception request was sent for approval.",
                    life: 5000,
                });
                open.value = false;
                emit("submitted");
            },
            onError: (bag) => {
                errors.value = bag;
            },
            onFinish: () => {
                submitting.value = false;
            },
        }
    );
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    Explain why the business rule could not be met. The request goes to this store's
                    approver for the module.
                    <template v-if="eligibility?.rule?.type === 'excuse'">
                        If approved, the item is marked Excused in the Adoption Rate report.
                    </template>
                    <template v-else>If approved, it can be used once, for this item only, until the time the approver sets.</template>
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-4">
                <div v-if="needsStore" class="space-y-1">
                    <Label class="text-xs font-semibold uppercase text-gray-500">Store</Label>
                    <Select
                        v-model="storeBranchId"
                        :options="storeOptions"
                        optionLabel="label"
                        optionValue="value"
                        filter
                        placeholder="Select the store"
                        class="w-full"
                    />
                </div>

                <div v-if="askDate" class="space-y-1">
                    <Label class="text-xs font-semibold uppercase text-gray-500">Delivery date</Label>
                    <Input v-model="orderDate" type="date" />
                </div>

                <div v-if="loading" class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-500">
                    Checking whether an exception can be requested...
                </div>

                <template v-else-if="eligibility">
                    <div v-if="eligibility.subject" class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm">
                        <p class="font-medium text-gray-900">{{ eligibility.rule?.label }}</p>
                        <p class="text-gray-600">
                            {{ eligibility.subject.summary }}
                            <span v-if="eligibility.subject.store_name"> - {{ eligibility.subject.store_name }}</span>
                        </p>
                    </div>

                    <div
                        v-if="!eligibility.can_request"
                        class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"
                    >
                        {{ eligibility.reason }}
                        <a
                            v-if="eligibility.open_request"
                            :href="route('rule-exceptions.index', { tab: 'mine' })"
                            class="ml-1 font-medium underline"
                        >
                            View request #{{ eligibility.open_request.id }}
                        </a>
                    </div>

                    <template v-else>
                        <div class="space-y-1">
                            <Label class="text-xs font-semibold uppercase text-gray-500">Reason *</Label>
                            <Select
                                v-model="form.reason_code"
                                :options="reasonOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Select a reason"
                                class="w-full"
                            />
                            <p v-if="errors.reason_code" class="text-sm text-red-600">{{ errors.reason_code }}</p>
                        </div>

                        <div class="space-y-1">
                            <Label class="text-xs font-semibold uppercase text-gray-500">Justification *</Label>
                            <Textarea
                                v-model="form.justification"
                                rows="4"
                                placeholder="What happened, and why the deadline could not be met"
                            />
                            <p class="text-xs text-gray-500">
                                {{ form.justification.trim().length }} / at least {{ minLength }} characters
                            </p>
                            <p v-if="errors.justification" class="text-sm text-red-600">{{ errors.justification }}</p>
                        </div>

                        <div class="space-y-1">
                            <Label class="text-xs font-semibold uppercase text-gray-500">
                                Supporting evidence {{ attachmentRequired ? "*" : "(optional)" }}
                            </Label>
                            <input
                                type="file"
                                accept=".jpg,.jpeg,.png,.pdf"
                                class="block w-full text-sm text-gray-700 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2"
                                @change="onFile"
                            />
                            <p class="text-xs text-gray-500">JPG, PNG or PDF, up to 5 MB.</p>
                            <p v-if="errors.attachment" class="text-sm text-red-600">{{ errors.attachment }}</p>
                        </div>
                    </template>
                </template>

                <p v-if="errors.subject" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    {{ errors.subject }}
                </p>
            </div>

            <DialogFooter>
                <Button variant="outline" :disabled="submitting" @click="open = false">Close</Button>
                <Button :disabled="!canSubmit" @click="submit">
                    {{ submitting ? "Submitting..." : "Submit request" }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
