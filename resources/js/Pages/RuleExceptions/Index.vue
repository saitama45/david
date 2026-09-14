<script setup>
/**
 * Business-rule exceptions: the approver queue, the user's own requests and
 * (with `view rule exception log`) the full audit log. Every action here is
 * re-authorized on the server by RuleExceptionService.
 */
import { ref, computed } from "vue";
import axios from "axios";
import { router } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";

const props = defineProps({
    requests: { type: Object, required: true },
    tab: { type: String, required: true },
    canApprove: { type: Boolean, default: false },
    canViewLog: { type: Boolean, default: false },
    filters: { type: Object, default: () => ({}) },
    modules: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

const toast = useToast();

const tabs = computed(() => [
    ...(props.canApprove ? [{ key: "approvals", label: "For My Approval" }] : []),
    { key: "mine", label: "My Requests" },
    ...(props.canViewLog ? [{ key: "log", label: "Exception Log" }] : []),
]);

const filterForm = ref({
    status: props.filters.status ?? "",
    module: props.filters.module ?? "",
    date_from: props.filters.date_from ?? "",
    date_to: props.filters.date_to ?? "",
});

const moduleLabel = (module) => module.replaceAll("_", " ").replace(/\b\w/g, (c) => c.toUpperCase());
const moduleOptions = computed(() => [{ value: "", label: "All modules" }, ...props.modules.map((m) => ({ value: m, label: moduleLabel(m) }))]);
const statusOptions = computed(() => [{ value: "", label: "All statuses" }, ...props.statuses]);

const go = (tab, filters = {}) => {
    router.get(route("rule-exceptions.index"), { tab, ...filters }, { preserveState: false, preserveScroll: true });
};
const applyFilters = () => go(props.tab, Object.fromEntries(Object.entries(filterForm.value).filter(([, v]) => v)));
const resetFilters = () => go(props.tab);

const statusClass = (status) => ({
    pending: "bg-amber-100 text-amber-800",
    approved: "bg-green-100 text-green-800",
    consumed: "bg-blue-100 text-blue-800",
    rejected: "bg-red-100 text-red-700",
    cancelled: "bg-gray-100 text-gray-600",
    expired: "bg-gray-100 text-gray-600",
}[status] ?? "bg-gray-100 text-gray-600");

const actionLabel = {
    submitted: "Submitted",
    approved: "Approved",
    rejected: "Rejected",
    cancelled: "Cancelled",
    consumed: "Used",
    expired: "Expired",
};

// --- detail ------------------------------------------------------------------
const detailOpen = ref(false);
const detail = ref(null);
const detailLoading = ref(false);
const decision = ref({ valid_until: "", decision_remarks: "" });
const errors = ref({});
const busy = ref(false);

const openDetail = async (row) => {
    detailOpen.value = true;
    detailLoading.value = true;
    detail.value = null;
    errors.value = {};

    try {
        const { data } = await axios.get(route("rule-exceptions.show", row.id));
        detail.value = data;
        decision.value = { valid_until: data.validity?.default ?? "", decision_remarks: "" };
    } catch (e) {
        detailOpen.value = false;
        toast.add({ severity: "error", summary: "Error", detail: e.response?.data?.message || "Could not load the request.", life: 5000 });
    } finally {
        detailLoading.value = false;
    }
};

const act = (action, payload = {}) => {
    busy.value = true;
    errors.value = {};

    router.post(route(`rule-exceptions.${action}`, detail.value.id), payload, {
        preserveScroll: true,
        onSuccess: (page) => {
            detailOpen.value = false;
            toast.add({ severity: "success", summary: "Done", detail: page.props.flash?.success, life: 5000 });
        },
        onError: (bag) => {
            errors.value = bag;
        },
        onFinish: () => {
            busy.value = false;
        },
    });
};

const approve = () => act("approve", {
    valid_until: detail.value.type === "unlock" ? decision.value.valid_until : null,
    decision_remarks: decision.value.decision_remarks || null,
});
const reject = () => act("reject", { decision_remarks: decision.value.decision_remarks });
const cancel = () => act("cancel");

const firstError = computed(() => Object.values(errors.value)[0]);
</script>

<template>
    <Layout heading="Business Rule Exceptions">
        <div class="mb-4 flex flex-wrap gap-2 border-b border-gray-200">
            <button
                v-for="item in tabs"
                :key="item.key"
                type="button"
                :class="[
                    'border-b-2 px-4 py-2 text-sm font-semibold transition-colors',
                    tab === item.key ? 'border-cyan-600 text-cyan-700' : 'border-transparent text-gray-500 hover:text-gray-800',
                ]"
                @click="go(item.key)"
            >
                {{ item.label }}
            </button>
        </div>

        <div class="mb-4 grid gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-[12rem_12rem_10rem_10rem_auto_auto]">
            <Select v-model="filterForm.module" :options="moduleOptions" optionLabel="label" optionValue="value" placeholder="All modules" />
            <Select v-model="filterForm.status" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="All statuses" />
            <Input v-model="filterForm.date_from" type="date" />
            <Input v-model="filterForm.date_to" type="date" />
            <Button @click="applyFilters">Apply</Button>
            <Button variant="outline" @click="resetFilters">Reset</Button>
        </div>

        <div v-if="tab === 'log'" class="mb-4 flex justify-end">
            <a
                :href="route('rule-exceptions.export', Object.fromEntries(Object.entries(filters).filter(([, v]) => v)))"
                class="inline-flex h-9 items-center rounded-md bg-cyan-700 px-4 text-sm font-medium text-white hover:bg-cyan-800"
            >
                Export log to Excel
            </a>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2 text-left">#</th>
                        <th class="px-3 py-2 text-left">Requested</th>
                        <th class="px-3 py-2 text-left">Rule</th>
                        <th class="px-3 py-2 text-left">Item</th>
                        <th class="px-3 py-2 text-left">Store</th>
                        <th class="px-3 py-2 text-left">Requested By</th>
                        <th class="px-3 py-2 text-left">Reason</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-if="!requests.data.length">
                        <td colspan="9" class="px-3 py-10 text-center text-gray-500">No exception requests found.</td>
                    </tr>
                    <tr v-for="row in requests.data" :key="row.id">
                        <td class="px-3 py-2 text-gray-500">{{ row.id }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ row.requested_at }}</td>
                        <td class="px-3 py-2">
                            <p class="font-medium text-gray-900">{{ row.rule_label }}</p>
                            <p class="text-xs text-gray-500">{{ moduleLabel(row.module) }} - {{ row.type === 'unlock' ? 'one-time unlock' : 'excuse' }}</p>
                        </td>
                        <td class="px-3 py-2 text-gray-700">{{ row.summary }}</td>
                        <td class="px-3 py-2 text-gray-700">{{ row.store_name }}</td>
                        <td class="px-3 py-2 text-gray-700">{{ row.requested_by_name }}</td>
                        <td class="px-3 py-2 text-gray-700">{{ row.reason_label }}</td>
                        <td class="px-3 py-2">
                            <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', statusClass(row.status)]">{{ row.status_label }}</span>
                            <p v-if="row.status === 'approved' && row.valid_until" class="mt-1 text-xs text-gray-500">until {{ row.valid_until }}</p>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <Button size="sm" variant="outline" @click="openDetail(row)">
                                {{ tab === 'approvals' ? 'Review' : 'View' }}
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination class="mt-4" :data="requests" />

        <Dialog v-model:open="detailOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Exception request #{{ detail?.id }}</DialogTitle>
                    <DialogDescription>{{ detail?.rule_label }}</DialogDescription>
                </DialogHeader>

                <div v-if="detailLoading" class="py-8 text-center text-sm text-gray-500">Loading...</div>

                <div v-else-if="detail" class="flex flex-col gap-4 text-sm">
                    <div class="grid gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 sm:grid-cols-2">
                        <div><p class="text-xs uppercase text-gray-500">Item</p><p class="font-medium text-gray-900">{{ detail.summary }}</p></div>
                        <div><p class="text-xs uppercase text-gray-500">Store</p><p class="font-medium text-gray-900">{{ detail.store_name }}</p></div>
                        <div><p class="text-xs uppercase text-gray-500">Requested by</p><p class="text-gray-900">{{ detail.requested_by_name }} - {{ detail.requested_at }}</p></div>
                        <div>
                            <p class="text-xs uppercase text-gray-500">Status</p>
                            <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', statusClass(detail.status)]">{{ detail.status_label }}</span>
                            <span v-if="detail.valid_until" class="ml-2 text-xs text-gray-500">valid until {{ detail.valid_until }}</span>
                        </div>
                    </div>

                    <div>
                        <p class="text-xs uppercase text-gray-500">Reason</p>
                        <p class="font-medium text-gray-900">{{ detail.reason_label }}</p>
                        <p class="mt-1 whitespace-pre-line text-gray-700">{{ detail.justification }}</p>
                        <a
                            v-if="detail.has_attachment"
                            :href="route('rule-exceptions.attachment', detail.id)"
                            class="mt-2 inline-block font-medium text-cyan-700 underline"
                        >
                            Download supporting evidence
                        </a>
                    </div>

                    <div v-if="detail.decision_remarks">
                        <p class="text-xs uppercase text-gray-500">Decision remarks ({{ detail.decided_by_name }})</p>
                        <p class="whitespace-pre-line text-gray-700">{{ detail.decision_remarks }}</p>
                    </div>

                    <div v-if="detail.consumed_ref">
                        <p class="text-xs uppercase text-gray-500">Used for</p>
                        <p class="text-gray-700">{{ detail.consumed_ref }} by {{ detail.consumed_by_name }} on {{ detail.consumed_at }}</p>
                    </div>

                    <div>
                        <p class="mb-2 text-xs uppercase text-gray-500">Audit trail</p>
                        <ol class="space-y-2 border-l-2 border-gray-200 pl-4">
                            <li v-for="(entry, index) in detail.actions" :key="index">
                                <p class="font-medium text-gray-900">{{ actionLabel[entry.action] ?? entry.action }}</p>
                                <p class="text-xs text-gray-500">{{ entry.user_name }} - {{ entry.created_at }}</p>
                                <p v-if="entry.remarks" class="text-xs text-gray-600">{{ entry.remarks }}</p>
                            </li>
                        </ol>
                    </div>

                    <div v-if="detail.can_decide" class="space-y-3 rounded-md border border-cyan-200 bg-cyan-50 p-3">
                        <p class="font-medium text-cyan-900">Your decision</p>

                        <div v-if="detail.type === 'unlock'">
                            <p v-if="detail.validity?.error" class="text-sm text-red-700">{{ detail.validity.error }}</p>
                            <template v-else>
                                <Label class="text-xs font-semibold uppercase text-gray-600">Usable until</Label>
                                <Input v-model="decision.valid_until" type="datetime-local" :max="detail.validity?.latest" />
                                <p class="mt-1 text-xs text-gray-600">
                                    One-time use. Latest allowed: {{ detail.validity?.latest?.replace('T', ' ') }}.
                                </p>
                            </template>
                        </div>
                        <p v-else class="text-xs text-gray-600">
                            Approving marks this item as Excused in the Adoption Rate report.
                        </p>

                        <div>
                            <Label class="text-xs font-semibold uppercase text-gray-600">Remarks (required to reject)</Label>
                            <Textarea v-model="decision.decision_remarks" rows="3" />
                        </div>
                    </div>

                    <p v-if="firstError" class="rounded-md border border-red-200 bg-red-50 p-3 text-red-700">{{ firstError }}</p>
                </div>

                <DialogFooter v-if="detail">
                    <Button variant="outline" :disabled="busy" @click="detailOpen = false">Close</Button>
                    <Button v-if="detail.can_cancel" variant="outline" :disabled="busy" @click="cancel">Cancel request</Button>
                    <template v-if="detail.can_decide">
                        <Button variant="destructive" :disabled="busy || !decision.decision_remarks" @click="reject">Reject</Button>
                        <Button :disabled="busy || !!detail.validity?.error" @click="approve">Approve</Button>
                    </template>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
