<script setup>
import { ref, reactive, computed } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { useToast } from '@/components/ui/toast/use-toast';
import { Toaster } from '@/components/ui/toast';
import { Search } from 'lucide-vue-next';

const props = defineProps({
    supplier: Object,
    storeBranches: Array,
    // { [branchId]: number[] } — may arrive as [] when empty.
    scheduledMap: { type: [Object, Array], default: () => ({}) },
    days: Array,
});

const { toast } = useToast();

// Reactive assignment state: { [branchId]: number[] of dayIds }.
const initialMap = Array.isArray(props.scheduledMap) ? {} : props.scheduledMap;
const assignments = reactive({});
props.storeBranches.forEach((branch) => {
    assignments[branch.id] = Array.isArray(initialMap[branch.id]) ? [...initialMap[branch.id]] : [];
});

const searchQuery = ref('');
const filteredBranches = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return props.storeBranches;
    return props.storeBranches.filter((b) =>
        b.name.toLowerCase().includes(q) || (b.branch_code || '').toLowerCase().includes(q)
    );
});

const isChecked = (branchId, dayId) => assignments[branchId]?.includes(dayId);

const toggleCell = (branchId, dayId) => {
    const arr = assignments[branchId];
    const idx = arr.indexOf(dayId);
    if (idx === -1) arr.push(dayId);
    else arr.splice(idx, 1);
};

const isScheduled = (branchId) => (assignments[branchId]?.length || 0) > 0;

// Row helper: tick / untick every day for a branch.
const rowAllChecked = (branchId) => assignments[branchId]?.length === props.days.length;
const toggleRowAll = (branchId) => {
    assignments[branchId] = rowAllChecked(branchId) ? [] : props.days.map((d) => d.id);
};

// Column helper: tick / untick a day for every visible branch.
const colAllChecked = (dayId) =>
    filteredBranches.value.length > 0 && filteredBranches.value.every((b) => isChecked(b.id, dayId));
const toggleColAll = (dayId) => {
    const check = !colAllChecked(dayId);
    filteredBranches.value.forEach((b) => {
        const arr = assignments[b.id];
        const idx = arr.indexOf(dayId);
        if (check && idx === -1) arr.push(dayId);
        if (!check && idx !== -1) arr.splice(idx, 1);
    });
};

const scheduledCount = computed(() => props.storeBranches.filter((b) => isScheduled(b.id)).length);

const form = useForm({ assignments: {} });
const submit = () => {
    form.assignments = { ...assignments };
    form.post(route('dsp-delivery-schedules.update', props.supplier.id), {
        preserveScroll: true,
        onSuccess: () => {
            toast({ title: 'Saved', description: 'Delivery schedule updated successfully.' });
        },
        onError: () => {
            toast({ title: 'Save Failed', description: 'Please try again.', variant: 'destructive' });
        },
    });
};
</script>

<template>
    <Head :title="`Delivery Schedule for ${supplier.name}`" />

    <Layout :heading="`Delivery Schedule for ${supplier.name}`">
        <Toaster />
        <div class="p-4 bg-white shadow-md rounded-lg">
            <!-- Header row: supplier info + search -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                <div>
                    <p class="text-sm text-gray-500">Supplier</p>
                    <p class="font-semibold text-gray-800">{{ supplier.name }} <span class="text-gray-400">({{ supplier.supplier_code }})</span></p>
                    <p class="text-xs text-gray-500 mt-1">{{ scheduledCount }} of {{ storeBranches.length }} branches scheduled</p>
                </div>
                <div class="relative w-full sm:w-72">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                    <Input v-model="searchQuery" placeholder="Search branch name or code..." class="w-full pl-9" />
                </div>
            </div>

            <p class="text-sm text-gray-500 mb-3">Tick the delivery days for each branch. Use a column header to set a day for all listed branches, or the "All days" box to set every day for a branch.</p>

            <div class="overflow-x-auto border rounded-lg">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold text-gray-600 sticky left-0 bg-gray-50">Branch Code</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-600">Branch Name</th>
                            <th class="px-3 py-2 text-center font-semibold text-gray-600">Status</th>
                            <th class="px-3 py-2 text-center font-semibold text-gray-600">All days</th>
                            <th v-for="day in days" :key="day.id" class="px-2 py-2 text-center font-semibold text-gray-600">
                                <div class="flex flex-col items-center gap-1">
                                    <span>{{ day.label }}</span>
                                    <input
                                        type="checkbox"
                                        :checked="colAllChecked(day.id)"
                                        @change="toggleColAll(day.id)"
                                        title="Toggle this day for all listed branches"
                                        class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-if="!filteredBranches.length">
                            <td :colspan="4 + days.length" class="px-3 py-6 text-center text-gray-500">No branches found.</td>
                        </tr>
                        <tr v-for="branch in filteredBranches" :key="branch.id" class="hover:bg-gray-50">
                            <td class="px-3 py-2 text-gray-700 sticky left-0 bg-white">{{ branch.branch_code }}</td>
                            <td class="px-3 py-2 font-medium text-gray-800">{{ branch.name }}</td>
                            <td class="px-3 py-2 text-center">
                                <span
                                    class="inline-block px-2 py-0.5 text-xs font-semibold rounded-full"
                                    :class="isScheduled(branch.id) ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                                >
                                    {{ isScheduled(branch.id) ? 'Scheduled' : 'Unscheduled' }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <input
                                    type="checkbox"
                                    :checked="rowAllChecked(branch.id)"
                                    @change="toggleRowAll(branch.id)"
                                    class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                />
                            </td>
                            <td v-for="day in days" :key="day.id" class="px-2 py-2 text-center">
                                <input
                                    type="checkbox"
                                    :checked="isChecked(branch.id, day.id)"
                                    @change="toggleCell(branch.id, day.id)"
                                    class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center justify-between mt-6">
                <Link
                    :href="route('dsp-delivery-schedules.index')"
                    class="inline-flex items-center justify-center px-6 py-2 border border-gray-300 text-base font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                    Back
                </Link>
                <button
                    @click="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center justify-center px-6 py-2 border border-transparent text-base font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
                >
                    Save Changes
                </button>
            </div>
        </div>
    </Layout>
</template>
