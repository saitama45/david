<script setup>
import { useSelectOptions } from "@/composables/useSelectOptions";
import { useAuth } from "@/composables/useAuth";
import { router, useForm } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { ref, computed, watch } from "vue";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/composables/useToast";
import Dialog from "primevue/dialog";

const confirm = useConfirm();
const { toast } = useToast();
const { hasAccess } = useAuth();

const props = defineProps({
    // The store's items, paginated (Items tab only)
    items: {
        type: Object,
        default: null,
    },
    // The adjustments waiting for approval (Waiting for Approval tab only)
    pending: {
        type: Array,
        default: () => [],
    },
    pendingCount: {
        type: Number,
        default: 0,
    },
    branches: {
        type: Array,
        required: true,
    },
    tab: {
        type: String,
        default: "items",
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const { options: branchesOptions } = useSelectOptions(props.branches);

const branchId = ref(props.filters.branchId || branchesOptions.value[0]?.value);
const search = ref(props.filters.search);
const currentTab = ref(props.tab);
const selectedItems = ref([]);

const canCreate = computed(() => hasAccess("create soh adjustment"));
const canApprove = computed(() => hasAccess("approve soh adjustment"));

const visit = () => {
    selectedItems.value = [];
    router.get(
        route("soh-adjustment.index"),
        { branchId: branchId.value, search: search.value, tab: currentTab.value },
        { preserveScroll: true, preserveState: true, replace: true }
    );
};

watch(branchId, visit);
watch(search, throttle(visit, 500));

const changeTab = (tab) => {
    currentTab.value = tab;
    visit();
};

const formatQuantity = (value) =>
    Number(value ?? 0).toLocaleString("en-US", { maximumFractionDigits: 4 });
const signed = (value) => `${Number(value) > 0 ? "+" : ""}${formatQuantity(value)}`;

// ---- Filing an adjustment
const adjusting = ref(null);
const isAdjustVisible = ref(false);
const adjustForm = useForm({
    branchId: null,
    sap_masterfile_id: null,
    new_quantity: null,
    remarks: "",
});

const difference = computed(() => {
    if (!adjusting.value || adjustForm.new_quantity === null || adjustForm.new_quantity === "") {
        return null;
    }

    return Number(adjustForm.new_quantity) - Number(adjusting.value.soh);
});

const openAdjust = (item) => {
    adjusting.value = item;
    adjustForm.reset();
    adjustForm.clearErrors();
    isAdjustVisible.value = true;
};

const submitAdjustment = () => {
    adjustForm
        .transform((data) => ({
            ...data,
            branchId: branchId.value,
            sap_masterfile_id: adjusting.value.id,
        }))
        .post(route("soh-adjustment.store"), {
            preserveScroll: true,
            onSuccess: (page) => {
                isAdjustVisible.value = false;
                toast.add({
                    severity: "success",
                    summary: "Sent for Approval",
                    detail: page.props.flash?.success || "SOH adjustment sent for approval.",
                    life: 4000,
                });
            },
        });
};

// ---- Approving and rejecting
const allPendingIds = computed(() => props.pending.map((item) => item.id));
const allSelected = computed({
    get: () => allPendingIds.value.length > 0 && selectedItems.value.length === allPendingIds.value.length,
    set: (value) => {
        selectedItems.value = value ? [...allPendingIds.value] : [];
    },
});

const decide = (action, ids) => {
    const approving = action === "approve";

    confirm.require({
        message: approving
            ? `Approve ${ids.length} SOH adjustment${ids.length === 1 ? "" : "s"}? The stock on hand changes at once.`
            : `Reject ${ids.length} SOH adjustment${ids.length === 1 ? "" : "s"}? The stock on hand is not changed.`,
        header: approving ? "Approve SOH Adjustment" : "Reject SOH Adjustment",
        icon: "pi pi-exclamation-triangle",
        rejectProps: { label: "Cancel", severity: "secondary", outlined: true },
        acceptProps: { label: approving ? "Approve" : "Reject", severity: approving ? "success" : "danger" },
        accept: () => {
            router.post(
                route(approving ? "soh-adjustment.approve-selected-items" : "soh-adjustment.reject-selected-items"),
                { selectedItems: ids, branchId: branchId.value },
                {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        selectedItems.value = [];
                        toast.add({
                            severity: "success",
                            summary: approving ? "Approved" : "Rejected",
                            detail: page.props.flash?.success,
                            life: 4000,
                        });
                    },
                    onError: (errors) => {
                        toast.add({
                            severity: "error",
                            summary: "Error",
                            detail: Object.values(errors).flat().join(" ") || "The adjustment could not be saved.",
                            life: 5000,
                        });
                    },
                }
            );
        },
    });
};
</script>

<template>
    <Layout heading="SOH Adjustments">
        <FilterTab>
            <FilterTabButton
                label="Items"
                filter="items"
                :currentFilter="currentTab"
                @click="changeTab('items')"
            />
            <FilterTabButton
                label="Waiting for Approval"
                filter="pending"
                :currentFilter="currentTab"
                :hasBadge="true"
                :badgeText="String(pendingCount)"
                @click="changeTab('pending')"
            />
        </FilterTab>

        <TableContainer>
            <DivFlexCenter class="justify-between sm:flex-row flex-col gap-3">
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search item code or name..."
                        v-model="search"
                    />
                </SearchBar>
                <DivFlexCenter class="gap-3">
                    <Select
                        filter
                        class="min-w-72"
                        placeholder="Select a Store"
                        :options="branchesOptions"
                        optionLabel="label"
                        optionValue="value"
                        v-model="branchId"
                    >
                    </Select>
                    <template v-if="currentTab === 'pending' && canApprove && selectedItems.length > 0">
                        <Button @click="decide('approve', selectedItems)">Approve All Selected Items</Button>
                        <Button variant="outline" @click="decide('reject', selectedItems)">Reject Selected</Button>
                    </template>
                </DivFlexCenter>
            </DivFlexCenter>

            <!-- Items: the store's stock on hand, to file a correction -->
            <template v-if="currentTab === 'items'">
                <p class="text-xs text-gray-500">
                    Click Adjust on an item and type the stock on hand that was counted. The stock changes only after the adjustment is approved.
                </p>
                <Table>
                    <TableHead>
                        <TH>Name</TH>
                        <TH>Code</TH>
                        <TH>UOM</TH>
                        <TH>SOH Quantity</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="item in items?.data ?? []" :key="item.id">
                            <TD>
                                {{ item.name }}
                                <span v-if="!item.is_active" class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-semibold text-gray-600">Inactive</span>
                            </TD>
                            <TD>{{ item.item_code }}</TD>
                            <TD>{{ item.uom }}</TD>
                            <TD>{{ item.soh === null ? "-" : formatQuantity(item.soh) }}</TD>
                            <TD>
                                <span
                                    v-if="item.pending_difference !== null"
                                    class="inline-block rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800"
                                >
                                    Waiting for approval: {{ signed(item.pending_difference) }} {{ item.uom }}
                                </span>
                                <span v-else-if="!item.adjustable" class="text-xs text-gray-500">
                                    No conversion to its stock unit
                                </span>
                                <Button
                                    v-else-if="canCreate"
                                    variant="link"
                                    class="text-green-600 p-0"
                                    @click="openAdjust(item)"
                                >
                                    Adjust
                                </Button>
                            </TD>
                        </tr>
                        <tr v-if="(items?.data ?? []).length === 0">
                            <TD colspan="5" class="text-center text-gray-500">
                                No items found{{ search ? ` matching "${search}"` : "" }}.
                            </TD>
                        </tr>
                    </TableBody>
                </Table>
                <Pagination v-if="items" :data="items" />
            </template>

            <!-- Waiting for Approval -->
            <template v-else>
                <Table>
                    <TableHead>
                        <TH v-if="canApprove">
                            <div class="cursor-pointer">
                                <Checkbox v-model="allSelected" :binary="true" />
                            </div>
                        </TH>
                        <TH>Name</TH>
                        <TH>Code</TH>
                        <TH>UOM</TH>
                        <TH>Current SOH</TH>
                        <TH>Adjustment</TH>
                        <TH>SOH After</TH>
                        <TH>Remarks</TH>
                        <TH>Requested</TH>
                        <TH v-if="canApprove">Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="item in pending" :key="item.id">
                            <TD v-if="canApprove">
                                <Checkbox
                                    v-model="selectedItems"
                                    :value="item.id"
                                    :inputId="`item-${item.id}`"
                                />
                            </TD>
                            <TD>{{ item.name }}</TD>
                            <TD>{{ item.item_code }}</TD>
                            <TD>{{ item.uom }}</TD>
                            <TD>{{ formatQuantity(item.soh) }}</TD>
                            <TD :class="item.difference > 0 ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold'">
                                {{ signed(item.difference) }}
                            </TD>
                            <TD>{{ formatQuantity(item.new_soh) }}</TD>
                            <TD class="max-w-xs whitespace-normal text-xs text-gray-600">{{ item.remarks }}</TD>
                            <TD class="text-xs text-gray-600">{{ item.requested_at }}</TD>
                            <TD v-if="canApprove">
                                <div class="flex items-center gap-3">
                                    <Button variant="link" class="text-green-600 p-0" @click="decide('approve', [item.id])">Approve</Button>
                                    <Button variant="link" class="text-red-600 p-0" @click="decide('reject', [item.id])">Reject</Button>
                                </div>
                            </TD>
                        </tr>
                        <tr v-if="pending.length === 0">
                            <TD :colspan="canApprove ? 10 : 8" class="text-center text-gray-500">
                                No SOH adjustment is waiting for approval at this store.
                            </TD>
                        </tr>
                    </TableBody>
                </Table>
            </template>
        </TableContainer>

        <!-- File an adjustment -->
        <Dialog
            v-model:visible="isAdjustVisible"
            modal
            header="Adjust SOH"
            :style="{ width: '480px' }"
            :breakpoints="{ '575px': '90vw' }"
        >
            <div v-if="adjusting" class="space-y-4">
                <div class="rounded-md bg-gray-50 p-3 text-sm">
                    <div class="font-semibold text-gray-900">{{ adjusting.name }}</div>
                    <div class="text-gray-600">{{ adjusting.item_code }}</div>
                    <div class="mt-1 text-gray-600">
                        Current SOH: <span class="font-semibold text-gray-900">{{ formatQuantity(adjusting.soh) }} {{ adjusting.uom }}</span>
                    </div>
                </div>

                <InputContainer>
                    <Label>New SOH ({{ adjusting.uom }})</Label>
                    <Input v-model="adjustForm.new_quantity" type="number" min="0" step="any" placeholder="The quantity counted" />
                    <FormError>{{ adjustForm.errors.new_quantity }}</FormError>
                    <p v-if="difference !== null && difference !== 0" class="text-xs" :class="difference > 0 ? 'text-green-600' : 'text-red-600'">
                        Adjustment: {{ signed(difference) }} {{ adjusting.uom }}
                    </p>
                </InputContainer>

                <InputContainer>
                    <Label>Remarks</Label>
                    <Input v-model="adjustForm.remarks" maxlength="255" placeholder="Why the SOH is being adjusted" />
                    <FormError>{{ adjustForm.errors.remarks }}</FormError>
                </InputContainer>

                <FormError>{{ adjustForm.errors.branchId || adjustForm.errors.sap_masterfile_id }}</FormError>

                <div class="flex justify-end gap-3">
                    <Button variant="outline" @click="isAdjustVisible = false">Cancel</Button>
                    <Button :disabled="adjustForm.processing" @click="submitAdjustment">Send for Approval</Button>
                </div>
            </div>
        </Dialog>
    </Layout>
</template>
