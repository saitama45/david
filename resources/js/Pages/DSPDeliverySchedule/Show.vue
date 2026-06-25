<script setup>
import { ref, computed } from 'vue';
import { Search } from 'lucide-vue-next';
import BackButton from '@/components/BackButton.vue';

const props = defineProps({
    supplier: { type: Object, required: true },
    storeBranches: { type: Array, required: true },
    scheduledMap: { type: [Object, Array], default: () => ({}) },
    days: { type: Array, required: true },
});

const heading = computed(() => `Delivery Schedule for ${props.supplier.name}`);

const map = computed(() => (Array.isArray(props.scheduledMap) ? {} : props.scheduledMap));

const isChecked = (branchId, dayId) => (map.value[branchId] || []).includes(dayId);
const isScheduled = (branchId) => (map.value[branchId]?.length || 0) > 0;

const searchQuery = ref('');
const filteredBranches = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return props.storeBranches;
    return props.storeBranches.filter((b) =>
        b.name.toLowerCase().includes(q) || (b.branch_code || '').toLowerCase().includes(q)
    );
});

const scheduledCount = computed(() => props.storeBranches.filter((b) => isScheduled(b.id)).length);
</script>

<template>
    <Layout :heading="heading">
        <div class="p-4 sm:p-6 bg-white rounded-lg shadow">
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

            <div class="overflow-x-auto border rounded-lg">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold text-gray-600 sticky left-0 bg-gray-50">Branch Code</th>
                            <th class="px-3 py-2 text-left font-semibold text-gray-600">Branch Name</th>
                            <th class="px-3 py-2 text-center font-semibold text-gray-600">Status</th>
                            <th v-for="day in days" :key="day.id" class="px-2 py-2 text-center font-semibold text-gray-600">{{ day.label }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-if="!filteredBranches.length">
                            <td :colspan="3 + days.length" class="px-3 py-6 text-center text-gray-500">No branches found.</td>
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
                            <td v-for="day in days" :key="day.id" class="px-2 py-2 text-center">
                                <span v-if="isChecked(branch.id, day.id)" class="text-green-600 font-bold">&check;</span>
                                <span v-else class="text-gray-300">&ndash;</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <BackButton :href="route('dsp-delivery-schedules.index')" />
    </Layout>
</template>
