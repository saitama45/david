<script setup>
import { useAuth } from "@/composables/useAuth";
import { router } from "@inertiajs/vue3";

const { hasAccess } = useAuth();

const props = defineProps({
    logs: {
        type: Object,
        required: true,
    },
});

const statusLabel = (status) => {
    const map = {
        pending: "Pending",
        processing: "Processing",
        completed: "Completed",
        failed: "Failed",
    };
    return map[status] ?? status;
};

const statusClass = (status) => {
    const map = {
        pending: "bg-gray-100 text-gray-700",
        processing: "bg-yellow-100 text-yellow-700",
        completed: "bg-green-100 text-green-700",
        failed: "bg-red-100 text-red-700",
    };
    return map[status] ?? "bg-gray-100 text-gray-700";
};

const typeLabel = (type) => {
    const map = {
        sap_masterfile: "SAP Masterfile",
    };
    return map[type] ?? type;
};

const formatDate = (dateStr) => {
    if (!dateStr) return "—";
    return new Date(dateStr).toLocaleString();
};

const refreshPage = () => {
    router.reload({ only: ["logs"] });
};
</script>

<template>
    <Layout heading="Work Queue">
        <TableContainer>
            <TableHeader>
                <div class="flex items-center gap-2">
                    <Button variant="outline" @click="refreshPage">
                        Refresh
                    </Button>
                </div>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Type</TH>
                    <TH>File</TH>
                    <TH>Status</TH>
                    <TH>Processed</TH>
                    <TH>Skipped</TH>
                    <TH>Queued At</TH>
                    <TH>Completed At</TH>
                    <TH>Actions</TH>
                </TableHead>

                <TableBody>
                    <tr v-if="logs.data.length === 0">
                        <TD colspan="8" class="text-center text-muted-foreground py-8">
                            No import jobs found.
                        </TD>
                    </tr>
                    <tr v-for="log in logs.data" :key="log.id">
                        <TD>{{ typeLabel(log.type) }}</TD>
                        <TD class="max-w-[200px] truncate" :title="log.original_filename">
                            {{ log.original_filename }}
                        </TD>
                        <TD>
                            <span
                                class="px-2 py-1 rounded text-xs font-medium"
                                :class="statusClass(log.status)"
                            >
                                {{ statusLabel(log.status) }}
                            </span>
                        </TD>
                        <TD>{{ log.processed_count ?? "—" }}</TD>
                        <TD>{{ log.skipped_count ?? "—" }}</TD>
                        <TD>{{ formatDate(log.created_at) }}</TD>
                        <TD>{{ formatDate(log.completed_at) }}</TD>
                        <TD>
                            <a
                                v-if="log.skipped_count > 0 && log.skipped_file_path"
                                :href="route('import-logs.download', log.id)"
                                class="text-xs text-blue-600 underline hover:text-blue-800"
                            >
                                Download Skipped
                            </a>
                            <span v-else-if="log.status === 'failed'" class="text-xs text-red-500" :title="log.error_message">
                                Failed
                            </span>
                            <span v-else class="text-xs text-muted-foreground">—</span>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="log in logs.data" :key="log.id">
                    <MobileTableHeading :title="typeLabel(log.type)">
                        <span
                            class="px-2 py-1 rounded text-xs font-medium"
                            :class="statusClass(log.status)"
                        >
                            {{ statusLabel(log.status) }}
                        </span>
                    </MobileTableHeading>
                    <LabelXS>File: {{ log.original_filename }}</LabelXS>
                    <LabelXS>Processed: {{ log.processed_count ?? "—" }} | Skipped: {{ log.skipped_count ?? "—" }}</LabelXS>
                    <LabelXS>Queued: {{ formatDate(log.created_at) }}</LabelXS>
                    <div v-if="log.skipped_count > 0 && log.skipped_file_path" class="mt-1">
                        <a
                            :href="route('import-logs.download', log.id)"
                            class="text-xs text-blue-600 underline"
                        >
                            Download Skipped Items
                        </a>
                    </div>
                </MobileTableRow>
            </MobileTableContainer>

            <Pagination :data="logs" />
        </TableContainer>
    </Layout>
</template>
