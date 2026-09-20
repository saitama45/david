<script setup>
import { useSelectOptions } from "@/composables/useSelectOptions";
import { router } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { computed, ref, watch } from "vue";

import { useStockRefresh } from "@/composables/useStockRefresh";
import { salesStatusLabel as statusLabel, salesStatusClass as statusClass } from "@/composables/salesImportStatus";
useStockRefresh(["logs"]);

const props = defineProps({
    canReviewBom: Boolean,
    logs: {
        type: Object,
        required: true,
    },
    branches: {
        type: [Array, Object],
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    isAdmin: {
        type: Boolean,
        default: false,
    },
});

const { options: branchOptions } = useSelectOptions(props.branches);
const tab = ref(props.filters.tab ?? "all");
const tabs = [{ value: "all", label: "All Jobs" }, { value: "automated", label: "Automated POS Sync" }, { value: "manual", label: "Manual Imports" }];
const search = ref(props.filters.search ?? "");
const branchId = ref(props.filters.branchId ?? "all");

const tableColspan = computed(() => (props.isAdmin ? 12 : 11));


const getLogs = (replace = false) => {
    router.get(
        route("import-logs.index"),
        {
            tab: tab.value,
            search: search.value || null,
            branchId: branchId.value || "all",
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace,
        }
    );
};

watch(
    search,
    throttle(() => {
        getLogs(true);
    }, 500)
);

watch(tab, () => getLogs());

watch(branchId, () => {
    getLogs();
});

const resetFilters = () => {
    search.value = "";
    branchId.value = "all";
    getLogs(true);
};

const refreshPage = () => {
    router.reload({
        only: ["logs"],
        preserveScroll: true,
    });
};

const typeLabel = (type) => {
    const map = {
        sap_masterfile: "SAP Masterfile",
        store_transaction: "Store Transaction",
        pos_sales: "POS Sales Sync",
    };
    return map[type] ?? type;
};

const formatDate = (dateStr) => {
    if (!dateStr) return "-";
    return new Date(dateStr).toLocaleString();
};

const formatDuration = (seconds) => {
    if (seconds === null || seconds === undefined) return "-";

    const total = Math.max(0, Math.floor(seconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const remainingSeconds = total % 60;

    if (hours > 0) return `${hours}h ${minutes}m`;
    if (minutes > 0) return `${minutes}m ${remainingSeconds}s`;
    return `${remainingSeconds}s`;
};

const storeNames = (log) => {
    if (!log.store_branches || log.store_branches.length === 0) {
        return "-";
    }

    return log.store_branches
        .map((branch) => `${branch.name} (${branch.branch_code})`)
        .join(", ");
};

const shortError = (message) => {
    if (!message) return "Failed";

    return message.length > 90 ? `${message.slice(0, 90)}...` : message;
};

</script>

<template>
    <Layout heading="Work Queue">
        <nav class="mb-4 flex flex-wrap gap-2" aria-label="Work Queue sources">
            <button v-for="option in tabs" :key="option.value" type="button" @click="tab = option.value"
                :aria-current="tab === option.value ? 'page' : undefined"
                class="rounded border px-4 py-2 text-sm" :class="tab === option.value ? 'bg-primary text-primary-foreground' : 'bg-background'">{{ option.label }}</button>
            <a v-if="canReviewBom" :href="route('import-logs.missing-bom')" class="rounded border px-4 py-2 text-sm">Missing BOM</a>
        </nav>
        <p class="mb-4 text-sm text-muted-foreground">Updates every 10 seconds. Check skipped counts and download the report for records that need review.</p>
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        v-model="search"
                        class="pl-10"
                        placeholder="Search jobs, users, stores..."
                    />
                </SearchBar>

                <DivFlexCenter class="gap-3">
                    <Select
                        v-model="branchId"
                        filter
                        placeholder="Select a Store"
                        :options="branchOptions"
                        optionLabel="label"
                        optionValue="value"
                        class="min-w-56"
                    />
                    <Button variant="outline" @click="resetFilters">
                        Reset
                    </Button>
                    <Button variant="outline" @click="refreshPage">
                        Refresh
                    </Button>
                </DivFlexCenter>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Type</TH>
                    <TH>File</TH>
                    <TH v-if="isAdmin">Uploader</TH>
                    <TH>Status</TH>
                    <TH>Stores</TH>
                    <TH>Processed</TH>
                    <TH>Skipped</TH>
                    <TH>Queued At</TH>
                    <TH>Started At</TH>
                    <TH>Runtime</TH>
                    <TH>Completed At</TH>
                    <TH>Actions</TH>
                </TableHead>

                <TableBody>
                    <tr v-if="logs.data.length === 0">
                        <TD :colspan="tableColspan" class="text-center text-muted-foreground py-8">
                            No import jobs found.
                        </TD>
                    </tr>
                    <tr v-for="log in logs.data" :key="log.id">
                        <TD>{{ typeLabel(log.type) }}</TD>
                        <TD class="max-w-[200px] truncate" :title="log.original_filename">
                            {{ log.original_filename }}
                        </TD>
                        <TD v-if="isAdmin" class="max-w-[180px] truncate" :title="log.user?.email">
                            {{ log.user?.name ?? "-" }}
                        </TD>
                        <TD>
                            <span
                                class="px-2 py-1 rounded text-xs font-medium"
                                :class="statusClass(log.display_status ?? log.status)"
                            >
                                {{ statusLabel(log.display_status ?? log.status) }}
                            </span>
                        </TD>
                        <TD class="max-w-[240px] truncate" :title="storeNames(log)">
                            {{ storeNames(log) }}
                        </TD>
                        <TD>{{ log.processed_count ?? "-" }}</TD>
                        <TD>{{ log.skipped_count ?? "-" }}</TD>
                        <TD>{{ formatDate(log.created_at) }}</TD>
                        <TD>{{ formatDate(log.processing_started_at) }}</TD>
                        <TD>{{ formatDuration(log.runtime_seconds) }}</TD>
                        <TD>{{ formatDate(log.completed_at) }}</TD>
                        <TD>
                            <a
                                v-if="log.skipped_file_path && (log.skipped_count > 0 || log.type === 'pos_sales')"
                                :href="route('import-logs.download', log.id)"
                                class="text-xs text-blue-600 underline hover:text-blue-800"
                            >
                                {{ log.type === 'pos_sales' ? 'Download Report' : 'Download Skipped' }}
                            </a>
                            <span v-else-if="log.status === 'failed'" class="text-xs text-red-500" :title="log.error_message">
                                {{ shortError(log.error_message) }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground">-</span>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="log in logs.data" :key="log.id">
                    <MobileTableHeading :title="typeLabel(log.type)">
                        <span
                            class="px-2 py-1 rounded text-xs font-medium"
                            :class="statusClass(log.display_status ?? log.status)"
                        >
                            {{ statusLabel(log.display_status ?? log.status) }}
                        </span>
                    </MobileTableHeading>
                    <LabelXS>File: {{ log.original_filename }}</LabelXS>
                    <LabelXS v-if="isAdmin">Uploader: {{ log.user?.name ?? "-" }}</LabelXS>
                    <LabelXS>Stores: {{ storeNames(log) }}</LabelXS>
                    <LabelXS>Processed: {{ log.processed_count ?? "-" }} | Skipped: {{ log.skipped_count ?? "-" }}</LabelXS>
                    <LabelXS>Queued: {{ formatDate(log.created_at) }}</LabelXS>
                    <LabelXS>Started: {{ formatDate(log.processing_started_at) }}</LabelXS>
                    <LabelXS>Runtime: {{ formatDuration(log.runtime_seconds) }}</LabelXS>
                    <LabelXS>Completed: {{ formatDate(log.completed_at) }}</LabelXS>
                    <LabelXS v-if="log.status === 'failed'" class="text-red-600">
                        Error: {{ shortError(log.error_message) }}
                    </LabelXS>
                    <div v-if="log.skipped_file_path && (log.skipped_count > 0 || log.type === 'pos_sales')" class="mt-1">
                        <a
                            :href="route('import-logs.download', log.id)"
                            class="text-xs text-blue-600 underline"
                        >
                            {{ log.type === 'pos_sales' ? 'Download Report' : 'Download Skipped Items' }}
                        </a>
                    </div>
                </MobileTableRow>
            </MobileTableContainer>

            <Pagination :data="logs" />
        </TableContainer>
    </Layout>
</template>
