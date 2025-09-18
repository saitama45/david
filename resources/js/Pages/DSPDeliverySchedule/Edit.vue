<script setup>
import { ref, nextTick, onMounted } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import Multiselect from 'vue-multiselect';
import { useToast } from '@/Components/ui/toast/use-toast';
import { Toaster } from '@/Components/ui/toast';

const props = defineProps({
    supplier: Object,
    storeBranches: Array,
    schedulesByDay: Object,
});

const { toast } = useToast();
const days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY'];

// Initialize with an empty structure
const form = useForm({
    schedules: days.reduce((acc, day) => ({ ...acc, [day]: [] }), {}),
});

// Populate the data after the component is mounted
onMounted(() => {
    const initialSchedules = {};
    for (const day of days) {
        initialSchedules[day] = (props.schedulesByDay[day] || []).map(branch => ({
            ...branch,
            instance_id: `${branch.id}_${Date.now()}_${Math.random()}`
        }));
    }
    form.schedules = initialSchedules;
});

const stagedBranches = ref([]);
const multiselectRef = ref(null);

// --- Async Search State ---
const filteredBranchOptions = ref([]);
const isLoading = ref(false);

const asyncFind = (query) => {
    if (query.length < 2) {
        filteredBranchOptions.value = [];
        return;
    }
    isLoading.value = true;
    const lowerQuery = query.toLowerCase();
    const result = props.storeBranches.filter(branch => 
        branch.name.toLowerCase().includes(lowerQuery) ||
        branch.branch_code.toLowerCase().includes(lowerQuery)
    ).map(branch => ({
        ...branch,
        display_label: `${branch.name} (${branch.branch_code})`
    }));

    filteredBranchOptions.value = result;
    isLoading.value = false;
};
// ------------------------

const selectedBranch = ref(null);

const onBranchSelect = (selectedOption) => {
    if (selectedOption) {
        stagedBranches.value.push({
            ...selectedOption,
            instance_id: `${selectedOption.id}_${Date.now()}_${Math.random()}`
        });
        selectedBranch.value = null;
        filteredBranchOptions.value = []; // Clear options after select
        nextTick(() => {
            multiselectRef.value?.activate();
        });
    }
};

const handleDayChange = (event, day) => {
    if (event.added) {
        const addedElement = event.added.element;
        const targetList = form.schedules[day];
        const count = targetList.filter(item => item.id === addedElement.id).length;

        if (count > 1) {
            toast({
                title: 'Duplicate Entry',
                description: `'${addedElement.name}' is already scheduled for ${day}.`,
                variant: 'destructive',
            });
            const indexToRemove = targetList.findIndex(item => item.instance_id === addedElement.instance_id);
            if (indexToRemove !== -1) {
                form.schedules[day].splice(indexToRemove, 1);
            }
        }
    }
};

const moveAllTo = (day) => {
    const targetList = form.schedules[day];
    const targetIds = new Set(targetList.map(b => b.id));
    
    const branchesToMove = [];
    const branchesToKeep = [];
    let duplicatesFoundInStaging = false;

    for (const branch of stagedBranches.value) {
        if (!targetIds.has(branch.id)) {
            branchesToMove.push(branch);
            targetIds.add(branch.id);
        } else {
            branchesToKeep.push(branch);
            duplicatesFoundInStaging = true;
        }
    }

    if (branchesToMove.length === 0 && stagedBranches.value.length > 0) {
        toast({
            title: 'No Branches Moved',
            description: `All currently unscheduled branches are already in ${day}'s schedule.`,
            variant: 'destructive',
        });
        return;
    }

    if (duplicatesFoundInStaging && branchesToMove.length > 0) {
         toast({
            title: 'Partial Move',
            description: `Some branches were not moved because they were already in ${day}'s schedule.`,
        });
    }

    form.schedules[day].push(...branchesToMove);
    stagedBranches.value = branchesToKeep;
};

const clearStagedBranches = () => {
    stagedBranches.value = [];
};

const clearDay = (day) => {
    form.schedules[day] = [];
};

const removeStagedBranch = (instanceId) => {
    const index = stagedBranches.value.findIndex(b => b.instance_id === instanceId);
    if (index !== -1) {
        stagedBranches.value.splice(index, 1);
    }
};

const removeScheduledBranch = (day, instanceId) => {
    const daySchedule = form.schedules[day];
    if (daySchedule) {
        const index = daySchedule.findIndex(b => b.instance_id === instanceId);
        if (index !== -1) {
            daySchedule.splice(index, 1);
        }
    }
};

const submit = () => {
    const schedulesPayload = {};
    for (const day of days) {
        schedulesPayload[day] = form.schedules[day] ? form.schedules[day].map(branch => branch.id) : [];
    }
    
    form.schedules = schedulesPayload;
    form.post(route('dsp-delivery-schedules.update', props.supplier.id));
};

</script>

<style src="vue-multiselect/dist/vue-multiselect.css"></style>
<style>
.ghost {
    opacity: 0.5;
    background: #c8ebfb;
}
</style>

<template>
    <Head :title="`DSP Delivery Schedule for ${supplier.name}`" />

    <Layout :heading="`DSP Delivery Schedule for ${supplier.name}`">
        <Toaster />
        <div class="p-4 bg-white shadow-md rounded-lg">

            <!-- Search and Staging Area -->
            <div class="mb-6 p-4 border rounded-lg">
                <label class="font-bold mb-2 block">Add Branch to Schedule</label>
                <Multiselect
                    ref="multiselectRef"
                    v-model="selectedBranch"
                    :options="filteredBranchOptions"
                    :internal-search="false"
                    :loading="isLoading"
                    @search-change="asyncFind"
                    label="display_label"
                    track-by="id"
                    placeholder="Type to search for a branch..."
                    @select="onBranchSelect"
                    class="mb-4"
                >
                    <template #option="{ option }">
                        <div>
                            <span class="font-semibold">{{ option.name }}</span>
                            <span class="text-sm text-gray-500 ml-2">({{ option.branch_code }})</span>
                        </div>
                    </template>
                    <template #noResult>
                        <span>No branches found. Try a different search.</span>
                    </template>
                     <template #noOptions>
                        <span>Type at least 2 characters to begin searching.</span>
                    </template>
                </Multiselect>

                <div class="flex justify-between items-center mt-4 mb-2">
                    <h4 class="font-semibold text-gray-700">Unscheduled Branches (drag from here)</h4>
                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-1">
                            <span class="text-xs font-medium mr-1">Move all to:</span>
                            <button @click="moveAllTo('MONDAY')" title="Move all to Monday" class="px-2 py-1 text-xs bg-gray-200 hover:bg-gray-300 rounded">Mon</button>
                            <button @click="moveAllTo('TUESDAY')" title="Move all to Tuesday" class="px-2 py-1 text-xs bg-gray-200 hover:bg-gray-300 rounded">Tue</button>
                            <button @click="moveAllTo('WEDNESDAY')" title="Move all to Wednesday" class="px-2 py-1 text-xs bg-gray-200 hover:bg-gray-300 rounded">Wed</button>
                            <button @click="moveAllTo('THURSDAY')" title="Move all to Thursday" class="px-2 py-1 text-xs bg-gray-200 hover:bg-gray-300 rounded">Thu</button>
                            <button @click="moveAllTo('FRIDAY')" title="Move all to Friday" class="px-2 py-1 text-xs bg-gray-200 hover:bg-gray-300 rounded">Fri</button>
                            <button @click="moveAllTo('SATURDAY')" title="Move all to Saturday" class="px-2 py-1 text-xs bg-gray-200 hover:bg-gray-300 rounded">Sat</button>
                        </div>
                        <div class="border-l border-gray-300 pl-2 ml-2">
                            <button @click="clearStagedBranches" title="Clear all unscheduled branches" class="px-2 py-1 text-xs bg-red-500 text-white hover:bg-red-600 rounded">Clear All</button>
                        </div>
                    </div>
                </div>
                <draggable
                    v-model="stagedBranches"
                    group="branches"
                    item-key="instance_id"
                    class="flex flex-wrap gap-2 p-4 bg-gray-100 rounded-lg min-h-[70px] border-2 border-dashed border-gray-300"
                >
                    <template #item="{ element }">
                        <div class="relative p-2 pr-7 bg-gray-300 border border-gray-400 rounded cursor-move">
                            <span>{{ element.name }}</span>
                            <button @click="removeStagedBranch(element.instance_id)" class="absolute top-0 right-0 px-2 py-1 text-gray-500 hover:text-red-600 font-bold text-lg">&times;</button>
                        </div>
                    </template>
                </draggable>
            </div>

            <!-- Day Columns -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div v-for="day in days" :key="day" class="border p-2 rounded-lg">
                    <div class="flex justify-center items-center mb-2 relative">
                        <h3 class="font-bold text-center">{{ day }}</h3>
                        <button @click="clearDay(day)" title="Clear all for this day" class="absolute right-1 top-[-2px] text-gray-400 hover:text-red-600 text-xl font-bold">&times;</button>
                    </div>
                    <draggable
                        v-model="form.schedules[day]"
                        group="branches"
                        item-key="instance_id"
                        @change="(event) => handleDayChange(event, day)"
                        ghost-class="ghost"
                        class="min-h-[200px] bg-green-50 p-4 rounded-lg border-2 border-dashed border-green-200 transition-colors duration-200"
                    >
                        <template #item="{ element }">
                            <div class="relative p-2 pr-7 mb-2 bg-blue-100 border border-blue-300 rounded cursor-move">
                                <span>{{ element.name }}</span>
                                <button @click="removeScheduledBranch(day, element.instance_id)" class="absolute top-0 right-0 px-2 py-1 text-blue-500 hover:text-red-600 font-bold text-lg">&times;</button>
                            </div>
                        </template>
                    </draggable>
                </div>
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