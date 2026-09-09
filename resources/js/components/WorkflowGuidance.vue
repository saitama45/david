<script setup>
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { guidanceContext, eligiblePeople } from '@/composables/workflowGuidance';

const page = usePage();
const selectedBranch = ref('');
const selectedSupplier = ref('');
const context = computed(() => guidanceContext(page.component, page.props, selectedBranch.value));
const catalog = computed(() => page.props.workflowGuidance?.catalog || {});
const branches = computed(() => page.props.workflowGuidance?.branches || []);
const suppliers = computed(() => page.props.workflowGuidance?.suppliers || []);
const recordBranch = computed(() => context.value.current.includes('interco_commit')
    ? context.value.record?.sending_store_branch_id
    : context.value.record?.store_branch_id || page.props.branch?.id);
watch(() => [page.url, recordBranch.value], () => {
    selectedBranch.value = recordBranch.value || (branches.value.length === 1 ? branches.value[0].id : '');
    selectedSupplier.value = context.value.record?.supplier_id || (suppliers.value.length === 1 ? suppliers.value[0].id : '');
}, { immediate: true });
const activeKeys = computed(() => context.value.current.length ? context.value.current : context.value.message ? [] : context.value.suggested);
const needsSupplier = key => ['order', 'fg_commit', 'other_commit'].includes(key);
const owners = key => eligiblePeople(catalog.value[key], selectedBranch.value, selectedSupplier.value);
const roles = key => [...new Set(owners(key).flatMap(person => person.roles))].sort();
const actionUrl = key => {
    const url = catalog.value[key]?.action_url || catalog.value[key]?.url;
    return url && new URL(url, 'http://localhost').pathname !== page.url.split('?')[0] ? url : null;
};
</script>

<template>
    <section v-if="context.steps.length && Object.keys(catalog).length" class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950" aria-label="Workflow guidance">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold">{{ context.current.length || context.message ? 'What happens next?' : 'Business rules and next actions' }}</h2>
            <Link :href="route('my-actions.index')" class="font-medium underline">My Actions →</Link>
        </div>
        <p v-if="context.status" class="mt-2">Current status: <strong>{{ context.status.replaceAll('_', ' ') }}</strong></p>
        <p v-if="context.message" class="mt-2">{{ context.message }}</p>
        <label v-if="!recordBranch && !context.message" class="mt-3 block">Show responsible roles for
            <select v-model="selectedBranch" class="ml-2 max-w-full rounded border-blue-200 bg-white p-2 text-sm">
                <option value="">Select a branch</option>
                <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
            </select>
        </label>
        <label v-if="!context.record?.supplier_id && activeKeys.some(needsSupplier)" class="mt-3 block">Ordering supplier
            <select v-model="selectedSupplier" class="ml-2 max-w-full rounded border-blue-200 bg-white p-2 text-sm"><option value="">Select a supplier</option><option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.supplier_code }} — {{ supplier.name }}</option></select>
        </label>
        <div v-if="activeKeys.length" class="mt-3 grid gap-3" :class="activeKeys.length > 1 ? 'lg:grid-cols-2' : ''">
            <div v-for="key in activeKeys" :key="key" class="rounded border border-blue-100 bg-white p-3">
                <p class="font-semibold">{{ catalog[key]?.label }}</p>
                <p class="mt-1">{{ catalog[key]?.rule }}</p>
                <template v-if="selectedBranch">
                    <p v-if="needsSupplier(key) && !selectedSupplier" class="mt-2">Select the supplier to show its responsible roles.</p>
                    <template v-else>
                        <p class="mt-2 font-medium">{{ roles(key).length ? 'Responsible roles: ' + roles(key).join(', ') : 'No responsible non-admin role assigned' }}</p>
                        <p v-if="!roles(key).length">Ask your administrator to check branch, entity and role assignments.</p>
                        <Link v-if="!roles(key).length && page.props.auth?.permissions?.includes('view users')" :href="route('users.index')" class="inline-block text-blue-700 underline">Review user assignments →</Link>
                    </template>
                </template>
                <Link v-if="actionUrl(key)" :href="actionUrl(key)" class="mt-2 inline-block font-medium underline">{{ catalog[key]?.action_url ? 'Create record' : 'Open workflow' }} →</Link>
            </div>
        </div>
        <p v-if="activeKeys.length" class="mt-3 text-xs">Roles shown apply to this branch and action. Open My Actions to see outstanding work and deadlines.</p>
        <details class="mt-3">
            <summary class="cursor-pointer font-medium">View the full process</summary>
            <ol class="mt-2 list-decimal space-y-2 pl-5"><li v-for="key in context.steps" :key="key"><strong>{{ catalog[key]?.label }}:</strong> {{ catalog[key]?.rule }}</li></ol>
        </details>
    </section>
</template>
