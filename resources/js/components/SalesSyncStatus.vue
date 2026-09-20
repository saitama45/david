<script setup>
import { computed } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import { salesStatusLabel, salesStatusClass } from "@/composables/salesImportStatus";
defineProps({ status: Object, branchId: [String, Number] });
const page = usePage();
const canViewQueue = computed(() => page.props.auth?.permissions?.includes("view import logs"));
const timestamp = (value) => value ? new Date(value).toLocaleString() : "No successful sync yet";
</script>

<template>
    <section v-if="status" class="mb-5 rounded-lg border bg-white p-4 space-y-3" aria-label="Sales import status">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold">Sales import status</h2>
            <Link v-if="canViewQueue" :href="route('import-logs.index', { branchId })" class="text-sm text-blue-700 underline">View Work Queue</Link>
        </div>
        <p class="text-sm text-muted-foreground">For the selected store, across all dates. Refreshes every 10 seconds. Automatic sync: {{ status.automation_enabled ? 'enabled' : 'not enabled' }}.</p>
        <p v-if="status.automation_enabled" class="text-sm" :class="status.scanner_ok ? 'text-green-700' : 'text-amber-800'">Scanner: {{ status.scanner_ok ? 'running' : 'no recent successful scan' }}<span v-if="status.scanner_checked_at"> ? last checked {{ timestamp(status.scanner_checked_at) }}</span></p>
        <p class="text-sm">Manual imports are allowed only when no sales have been automatically posted or verified for that store's sales date. The check covers all terminals and runs again when the file is processed.</p>
        <p v-if="status.unresolved_receipts" class="text-sm text-amber-800">{{ status.unresolved_receipts }} POS receipts remain unresolved across batches. They are retried after five minutes; see Work Queue reports for details.</p>
        <div class="grid gap-3 text-sm sm:grid-cols-2">
            <div><span class="text-muted-foreground">Last POS batch without skipped receipts</span><p>{{ timestamp(status.last_successful_sync) }}</p></div>
            <div><span class="text-muted-foreground">Latest imported sales date</span><p>{{ status.latest_sales_date ?? 'No sales imported yet' }}</p></div>
        </div>
        <div v-if="status.latest_run" class="border-t pt-3 text-sm space-y-2">
            <p>Latest batch #{{ status.latest_run.id }} · {{ status.latest_run.type === 'pos_sales' ? 'POS Sales Sync' : 'Excel upload' }}
                <span class="ml-2 rounded px-2 py-1" :class="salesStatusClass(status.latest_run.status)">{{ salesStatusLabel(status.latest_run.status) }}</span>
            </p>
            <p>{{ status.latest_run.processed_count }} processed · {{ status.latest_run.skipped_count }} skipped / need review in this batch</p>
            <p v-if="status.latest_run.status === 'completed_with_issues'" class="text-amber-800">Some records were not posted. Check the Work Queue report before treating inventory as fully up to date.</p>
            <p v-if="status.latest_run.status === 'failed'" class="text-red-700">Processing failed. Check the Work Queue for the error and any partial results.</p>
        </div>
        <p v-else class="text-sm text-muted-foreground">No sales import batches found for this store.</p>
    </section>
</template>
