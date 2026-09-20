<script setup>
import { reactive, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import Autocomplete from '@/components/autocomplete.vue';
const props = defineProps({ report: Object, filters: Object, branches: Array });
const form = reactive({ ...props.filters });
const run = () => router.get(route('import-logs.missing-bom'), form, { preserveScroll: true });
const exportUrl = computed(() => route('import-logs.missing-bom', { ...props.filters, view: form.view, export: 'csv' }));
const storeOptions = computed(() => [{ value: 'all', label: 'All authorized stores' }, ...props.branches]);
const distinct = computed(() => form.view === 'distinct');
const rows = computed(() => distinct.value ? props.report.distinct_rows : props.report.rows);
const headings = computed(() => distinct.value
    ? ['POS Code', 'Description', 'Stores', 'Store Count', 'Issue', 'First Sale', 'Last Sale', 'POS Source Receipts', 'Imported Receipts']
    : ['POS Code', 'Description', 'Store', 'Issue', 'First Sale', 'Last Sale', 'POS Source Receipts', 'Imported Receipts']);
</script>

<template>
    <Layout heading="Work Queue">
        <nav class="mb-4 flex flex-wrap gap-2" aria-label="Work Queue sources">
            <a v-for="tab in [{ value: 'all', label: 'All Jobs' }, { value: 'automated', label: 'Automated POS Sync' }, { value: 'manual', label: 'Manual Imports' }]"
                :key="tab.value" :href="route('import-logs.index', { tab: tab.value })" class="rounded border px-4 py-2 text-sm">{{ tab.label }}</a>
            <span aria-current="page" class="rounded border bg-primary text-primary-foreground px-4 py-2 text-sm">Missing BOM</span>
        </nav>
        <p class="mb-3 text-sm text-muted-foreground">Current missing BOMs and missing or ambiguous POS masterfiles found in sales during the selected dates. Approved non-inventory products are excluded. POS source and imported receipt counts can overlap; do not add them together.</p>
        <form @submit.prevent="run" class="mb-4 flex flex-wrap items-end gap-3">
            <label class="text-sm">From<input v-model="form.from" type="date" required class="block rounded border p-2 bg-background" /></label>
            <label class="text-sm">To<input v-model="form.to" type="date" required class="block rounded border p-2 bg-background" /></label>
            <div class="text-sm min-w-72"><label>Store<Autocomplete v-model="form.branchId" :options="storeOptions" placeholder="Search stores..." /></label></div>
            <label class="text-sm">POS code / description<input v-model="form.search" maxlength="100" class="block rounded border p-2 bg-background" /></label>
            <Button type="submit">Run Report</Button>
            <a :href="exportUrl" class="rounded border px-4 py-2 text-sm">Export CSV</a>
        </form>
        <p v-if="$page.props.errors.from" class="mb-3 text-red-600">{{ $page.props.errors.from }}</p>
        <nav class="mb-4 flex gap-2" aria-label="Missing BOM views">
            <button v-for="view in [{ value: 'stores', label: 'By Store' }, { value: 'distinct', label: 'Distinct POS Codes' }]" :key="view.value"
                type="button" @click="form.view = view.value" :aria-current="form.view === view.value ? 'page' : undefined"
                class="rounded border px-4 py-2 text-sm" :class="form.view === view.value ? 'bg-primary text-primary-foreground' : 'bg-background'">{{ view.label }}</button>
        </nav>
        <p v-if="distinct" class="mb-3 text-sm">{{ report.distinct_rows.length }} distinct POS codes with no BOM in the current entity. One row per code across the selected stores; codes with an existing recipe are excluded even if their masterfile needs review.</p>
        <p class="mb-3 text-sm">{{ report.codes }} distinct POS codes · {{ report.rows.length }} code/store combinations · Generated {{ new Date(report.generated_at).toLocaleString() }}</p>
        <p v-for="warning in report.warnings" :key="warning" class="mb-2 text-sm text-amber-700">{{ warning }}</p>
        <p class="mb-3 text-sm text-muted-foreground">Up to 31 days per report. Source coverage uses configured stores and available replicated sold lines, including sales before automation started. Missing source lines cannot identify a product. This report checks recipe presence; ingredient quantities and unit-conversion issues remain in the queue reports. Run again after correcting master data.</p>
        <div class="overflow-auto rounded border">
            <table class="w-full text-sm text-left">
                <thead class="bg-muted"><tr><th v-for="title in headings" :key="title" class="p-3">{{ title }}</th></tr></thead>
                <tbody><tr v-for="row in rows" :key="row.code + (row.branch ?? '')" class="border-t"><td v-for="(value, key) in row" :key="key" class="p-3">{{ value }}</td></tr>
                    <tr v-if="!rows.length"><td :colspan="headings.length" class="p-8 text-center">No missing setup found in the available sales records for these filters.</td></tr></tbody>
            </table>
        </div>
    </Layout>
</template>
