<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { throttle } from 'lodash';
import { Eye, ArrowUp, ArrowDown } from 'lucide-vue-next';

const props = defineProps({
    approvals: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    tab: { type: String, required: true },
    counts: { type: Object, required: true },
});

const activeTab = ref(props.tab);
const sortKey = ref(props.filters.sort || 'id');
const sortDir = ref(props.filters.direction || 'desc');

const filterForm = ref({
    year: props.filters.year || null,
    month: props.filters.month || null,
    calculated_date: props.filters.calculated_date || null,
    status: props.filters.status || null,
    creator_name: props.filters.creator_name || null,
    branch_name: props.filters.branch_name || null,
});

const switchTab = (newTab) => {
    activeTab.value = newTab;
    router.get(route('month-end-count-approvals-level2.index'), { tab: newTab }, {
        preserveScroll: true,
        replace: true,
    });
};

const sortBy = (key) => {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortDir.value = 'asc';
    }
};

watch([filterForm, sortKey, sortDir], throttle(() => {
    router.get(route('month-end-count-approvals-level2.index'), {
        ...filterForm.value,
        sort: sortKey.value,
        direction: sortDir.value,
        tab: activeTab.value,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300), { deep: true });

const getMonthName = (monthNumber) => {
    const date = new Date();
    date.setMonth(monthNumber - 1);
    return date.toLocaleString('en-US', { month: 'long' });
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return dateString;
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    const correctedDate = new Date(date.getTime() + userTimezoneOffset);
    return new Intl.DateTimeFormat('en-US', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(correctedDate);
};

const monthOptions = [
    { value: 1, label: 'January' },
    { value: 2, label: 'February' },
    { value: 3, label: 'March' },
    { value: 4, label: 'April' },
    { value: 5, label: 'May' },
    { value: 6, label: 'June' },
    { value: 7, label: 'July' },
    { value: 8, label: 'August' },
    { value: 9, label: 'September' },
    { value: 10, label: 'October' },
    { value: 11, label: 'November' },
    { value: 12, label: 'December' },
];

const viewApproval = (scheduleId, branchId) => {
    router.get(route('month-end-count-approvals-level2.show', { schedule_id: scheduleId, branch_id: branchId }));
};

const statusColors = {
    'level1_approved': 'bg-blue-500 text-white',
    'level2_approved': 'bg-green-500 text-white',
    'expired': 'bg-red-500 text-white',
};

const getStatusClass = (status) => {
    return statusColors[status] || 'bg-gray-500 text-white';
};

</script>

<template>
    <Head title="MEC Approval - 2nd Level" />

    <Layout heading="MEC Approval - 2nd Level">
        <div class="flex items-center gap-x-2 mb-6">
            <button
                @click="switchTab('for_approval')"
                class="flex items-center gap-x-3 rounded-md px-4 py-2 text-sm font-semibold transition"
                :class="activeTab === 'for_approval' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-50 border'"
            >
                <span>For Approval</span>
                <span class="px-2 py-0.5 rounded-full text-xs" :class="activeTab === 'for_approval' ? 'bg-white/25' : 'bg-gray-200'">{{ counts.for_approval }}</span>
            </button>
            <button
                @click="switchTab('approved')"
                class="flex items-center gap-x-3 rounded-md px-4 py-2 text-sm font-semibold transition"
                :class="activeTab === 'approved' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-gray-700 hover:bg-gray-50 border'"
            >
                <span>Approved</span>
                <span class="px-2 py-0.5 rounded-full text-xs" :class="activeTab === 'approved' ? 'bg-white/25' : 'bg-gray-200'">{{ counts.approved }}</span>
            </button>
        </div>

        <TableContainer>
            <Table>
                <TableHead>
                    <TH>ID</TH>
                    <TH>
                        <div class="flex items-center cursor-pointer" @click="sortBy('year')">Year <ArrowUp v-if="sortKey === 'year' && sortDir === 'asc'" class="h-4 w-4 ml-1" /><ArrowDown v-if="sortKey === 'year' && sortDir === 'desc'" class="h-4 w-4 ml-1" /></div>
                        <Input type="text" v-model="filterForm.year" placeholder="Filter..." class="mt-1 w-full text-sm" @keydown.stop />
                    </TH>
                    <TH>
                        <div class="flex items-center cursor-pointer" @click="sortBy('month')">Month <ArrowUp v-if="sortKey === 'month' && sortDir === 'asc'" class="h-4 w-4 ml-1" /><ArrowDown v-if="sortKey === 'month' && sortDir === 'desc'" class="h-4 w-4 ml-1" /></div>
                        <Select v-model="filterForm.month" :options="monthOptions" optionLabel="label" optionValue="value" placeholder="Select Month" class="mt-1 w-full text-sm" showClear />
                    </TH>
                    <TH>
                        <div class="flex items-center cursor-pointer" @click="sortBy('calculated_date')">MEC Schedule Date <ArrowUp v-if="sortKey === 'calculated_date' && sortDir === 'asc'" class="h-4 w-4 ml-1" /><ArrowDown v-if="sortKey === 'calculated_date' && sortDir === 'desc'" class="h-4 w-4 ml-1" /></div>
                        <Input type="date" v-model="filterForm.calculated_date" class="mt-1 w-full text-sm" @keydown.stop />
                    </TH>
                    <TH v-if="activeTab === 'approved'">
                        <div class="flex items-center cursor-pointer" @click="sortBy('status')">Status <ArrowUp v-if="sortKey === 'status' && sortDir === 'asc'" class="h-4 w-4 ml-1" /><ArrowDown v-if="sortKey === 'status' && sortDir === 'desc'" class="h-4 w-4 ml-1" /></div>
                        <Input type="text" v-model="filterForm.status" placeholder="Filter..." class="mt-1 w-full text-sm" @keydown.stop />
                    </TH>
                    <TH v-else>
                        Status
                    </TH>
                    <TH>
                        <div class="flex items-center cursor-pointer" @click="sortBy('creator_name')">Created By <ArrowUp v-if="sortKey === 'creator_name' && sortDir === 'asc'" class="h-4 w-4 ml-1" /><ArrowDown v-if="sortKey === 'creator_name' && sortDir === 'desc'" class="h-4 w-4 ml-1" /></div>
                        <Input type="text" v-model="filterForm.creator_name" placeholder="Filter..." class="mt-1 w-full text-sm" @keydown.stop />
                    </TH>
                    <TH>
                        <div class="flex items-center cursor-pointer" @click="sortBy('branch_name')">{{ activeTab === 'for_approval' ? 'Branches Awaiting Approval' : 'Branch Details' }} <ArrowUp v-if="sortKey === 'branch_name' && sortDir === 'asc'" class="h-4 w-4 ml-1" /><ArrowDown v-if="sortKey === 'branch_name' && sortDir === 'desc'" class="h-4 w-4 ml-1" /></div>
                        <Input type="text" v-model="filterForm.branch_name" placeholder="Filter..." class="mt-1 w-full text-sm" @keydown.stop />
                    </TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-if="!approvals.data.length">
                        <td :colspan="8" class="text-center py-4">{{ activeTab === 'for_approval' ? 'No items awaiting approval.' : 'No approved items found.' }}</td>
                    </tr>
                    <tr v-for="approval in approvals.data" :key="`${approval.schedule_id}-${approval.branch_id}`">
                        <TD>{{ approval.schedule_id }}</TD>
                        <TD>{{ approval.year }}</TD>
                        <TD>{{ getMonthName(approval.month) }}</TD>
                        <TD>{{ formatDate(approval.calculated_date) }}</TD>
                        <TD>
                            <Badge class="capitalize" :class="getStatusClass(approval.status)">
                                {{ approval.status.replace(/_/g, ' ') }}
                            </Badge>
                        </TD>
                        <TD>{{ approval.creator_name }}</TD>
                        <TD>
                            <Badge class="bg-gray-200 text-gray-800">
                                {{ approval.branch_name }}
                            </Badge>
                        </TD>
                        <TD>
                            <Button @click="viewApproval(approval.schedule_id, approval.branch_id)" variant="outline" size="icon" :title="`View ${approval.branch_name}`">
                                <Eye class="h-4 w-4" />
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination v-if="approvals.data.length > 0" :data="approvals" />
        </TableContainer>
    </Layout>
</template>
