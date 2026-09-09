<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import WorkflowTask from '@/components/WorkflowTask.vue';
const props = defineProps({ tasks: Array, summary: Object, filters: Object });
const ownership = ref('mine');
const search = ref('');
const urgency = ref('all');
const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const pageNumber = ref(1);
const filtered = computed(() => props.tasks.filter(task => (ownership.value === 'all' || task.ownership === ownership.value)
    && (urgency.value === 'all' || task.urgency === urgency.value)
    && `${task.branch} ${task.reference} ${task.action} ${task.roles.join(' ')}`.toLowerCase().includes(search.value.toLowerCase())));
const visible = computed(() => filtered.value.slice(0, pageNumber.value * 30));
function reload() { router.get(route('my-actions.index'), { date_from: dateFrom.value, date_to: dateTo.value }, { preserveScroll: true }); }
</script>
<template>
    <Layout heading="My Actions">
        <p class="text-sm text-slate-600">Open work for your assigned branches in the active entity. Pending handoffs include older records. The date range applies to missing orders and sales uploads.</p>
        <div class="grid gap-3 sm:grid-cols-4">
            <button v-for="[key, label] in [['mine', 'Needs your action'], ['waiting', 'Waiting for others'], ['unassigned', 'Needs assignment'], ['all', 'All open tasks']]" :key="key" class="rounded-lg border p-4 text-left" :class="ownership === key ? 'border-blue-500 bg-blue-50' : 'bg-white'" @click="ownership = key; pageNumber = 1">
                <span class="block text-sm">{{ label }}</span><strong class="text-2xl">{{ key === 'all' ? tasks.length : summary[key] }}</strong>
            </button>
        </div>
        <form @submit.prevent="reload" class="flex flex-wrap items-end gap-3 rounded-lg border bg-white p-4">
            <label class="text-sm">Missing submissions from<input v-model="dateFrom" type="date" required class="block rounded border-slate-300" /></label>
            <label class="text-sm">Through<input v-model="dateTo" type="date" required class="block rounded border-slate-300" /></label>
            <button class="rounded bg-blue-700 px-4 py-2 text-white">Refresh</button>
            <label class="text-sm">Find a task<input v-model="search" type="search" class="block rounded border-slate-300" placeholder="Branch, reference or role" /></label>
            <label class="text-sm">Deadline<select v-model="urgency" class="block rounded border-slate-300"><option value="all">All</option><option value="overdue">Overdue</option><option value="due_soon">Due in 24 hours</option></select></label>
        </form>
        <div class="grid gap-3 lg:grid-cols-2"><WorkflowTask v-for="task in visible" :key="task.id" :task="task" /></div>
        <p v-if="!filtered.length" role="status" class="rounded-lg border bg-white p-6">No tasks match this view. Check the other ownership views or adjust the missing-submission dates.</p>
        <button v-if="visible.length < filtered.length" @click="pageNumber++" class="rounded border px-4 py-2">Show more ({{ visible.length }} of {{ filtered.length }})</button>
        <details class="rounded-lg border bg-white p-4">
            <summary class="cursor-pointer font-semibold">Where work is waiting</summary>
            <p class="my-2 text-sm text-slate-600">Current open tasks, grouped by handoff. Age starts from the available submission or preceding action timestamp; this is not a historical completion-time measure.</p>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr><th class="p-2">Action</th><th class="p-2">Open</th><th class="p-2">Average hours</th><th class="p-2">Oldest hours</th></tr></thead><tbody><tr v-for="row in summary.handoffs" :key="row.action"><td class="p-2">{{ row.action }}</td><td class="p-2">{{ row.count }}</td><td class="p-2">{{ row.average_hours }}</td><td class="p-2">{{ row.oldest_hours }}</td></tr></tbody></table></div>
        </details>
        <p class="text-xs text-slate-600">Responsible roles are resolved from current branch and action access. Wastage submissions cannot be inferred for days without records. Missing deadlines remain unspecified rather than assuming an approval SLA.</p>
    </Layout>
</template>
