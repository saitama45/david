<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { throttle } from 'lodash';
import { CalendarCheck2, Eye, ArrowUp, ArrowDown } from 'lucide-vue-next';

const props = defineProps({
    schedulesAwaitingApproval: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

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

const sortBy = (key) => {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortDir.value = 'asc';
    }
};

watch([filterForm, sortKey, sortDir], throttle(() => {
    router.get(route('month-end-count-approvals.index'), {
        ...filterForm.value,
        sort: sortKey.value,
        direction: sortDir.value,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300), { deep: true });

const getMonthName = (monthNumber) => {
    const date = new Date();
    date.setMonth(monthNumber - 1); // Month is 0-indexed
    return date.toLocaleString('en-US', { month: 'long' });
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    if (isNaN(date.getTime())) {
        return dateString;
    }

    // To handle timezone issue with YYYY-MM-DD strings which are parsed as UTC.
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
    router.get(route('month-end-count-approvals.show', { schedule_id: scheduleId, branch_id: branchId }));
};

</script>

<template>
    <Head title="Month End Count Approvals" />

    <Layout heading="Month End Count Approvals">
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>ID</TH>
                    <TH>
                        <div class="flex items-center cursor-pointer" @click="sortBy('year')">
                            Year
                            <ArrowUp v-if="sortKey === 'year' && sortDir === 'asc'" class="h-4 w-4 ml-1" />
                            <ArrowDown v-if="sortKey === 'year' && sortDir === 'desc'" class="h-4 w-4 ml-1" />
                        </div>
                        <Input type="text" v-model="filterForm.year" placeholder="Filter..." class="mt-1 w-full text-sm" @keydown.stop />
                    </TH>
                    <TH>
                        <div class="flex items-center cursor-pointer" @click="sortBy('month')">
                            Month
                            <ArrowUp v-if="sortKey === 'month' && sortDir === 'asc'" class="h-4 w-4 ml-1" />
                            <ArrowDown v-if="sortKey === 'month' && sortDir === 'desc'" class="h-4 w-4 ml-1" />
                        </div>
                        <Select
                            v-model="filterForm.month"
                            :options="monthOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Select Month"
                            class="mt-1 w-full text-sm"
                            showClear
                        />
                    </TH>
                    <TH>
                        <div class="flex items-center cursor-pointer" @click="sortBy('calculated_date')">
                            Calculated Date
                            <ArrowUp v-if="sortKey === 'calculated_date' && sortDir === 'asc'" class="h-4 w-4 ml-1" />
                            <ArrowDown v-if="sortKey === 'calculated_date' && sortDir === 'desc'" class="h-4 w-4 ml-1" />
                        </div>
                        <Input type="date" v-model="filterForm.calculated_date" class="mt-1 w-full text-sm" @keydown.stop />
                    </TH>
                    <TH>
                        <div class="flex items-center cursor-pointer" @click="sortBy('status')">
                            Status
                            <ArrowUp v-if="sortKey === 'status' && sortDir === 'asc'" class="h-4 w-4 ml-1" />
                            <ArrowDown v-if="sortKey === 'status' && sortDir === 'desc'" class="h-4 w-4 ml-1" />
                        </div>
                        <Input type="text" v-model="filterForm.status" placeholder="Filter..." class="mt-1 w-full text-sm" @keydown.stop />
                    </TH>
                    <TH>
                        <div class="flex items-center cursor-pointer" @click="sortBy('creator_name')">
                            Created By
                            <ArrowUp v-if="sortKey === 'creator_name' && sortDir === 'asc'" class="h-4 w-4 ml-1" />
                            <ArrowDown v-if="sortKey === 'creator_name' && sortDir === 'desc'" class="h-4 w-4 ml-1" />
                        </div>
                        <Input type="text" v-model="filterForm.creator_name" placeholder="Filter..." class="mt-1 w-full text-sm" @keydown.stop />
                    </TH>
                    <TH>
                        <div class="flex items-center cursor-pointer" @click="sortBy('branch_name')">
                            Branches Awaiting Approval
                            <ArrowUp v-if="sortKey === 'branch_name' && sortDir === 'asc'" class="h-4 w-4 ml-1" />
                            <ArrowDown v-if="sortKey === 'branch_name' && sortDir === 'desc'" class="h-4 w-4 ml-1" />
                        </div>
                        <Input type="text" v-model="filterForm.branch_name" placeholder="Filter..." class="mt-1 w-full text-sm" @keydown.stop />
                    </TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-if="!schedulesAwaitingApproval.data.length">
                        <td colspan="8" class="text-center py-4">No schedules awaiting approval.</td>
                    </tr>
                    <tr v-for="schedule in schedulesAwaitingApproval.data" :key="schedule.id">
                        <TD>{{ schedule.id }}</TD>
                        <TD>{{ schedule.year }}</TD>
                        <TD>{{ getMonthName(schedule.month) }}</TD>
                        <TD>{{ formatDate(schedule.calculated_date) }}</TD>
                        <TD>
                            <Badge class="capitalize bg-purple-500 text-white">
                                Pending Level 1 Approval
                            </Badge>
                        </TD>
                        <TD>{{ schedule.creator ? `${schedule.creator.first_name} ${schedule.creator.last_name}` : 'N/A' }}</TD>
                        <TD>
                            <div v-if="schedule.branches_awaiting_approval && schedule.branches_awaiting_approval.length">
                                <Badge v-for="branch in schedule.branches_awaiting_approval" :key="branch.id" class="bg-gray-200 text-gray-800 mr-1 mb-1">
                                    {{ branch.name }}
                                </Badge>
                            </div>
                            <span v-else>N/A</span>
                        </TD>
                        <TD>
                            <div class="flex gap-2">
                                <Button v-for="branch in schedule.branches_awaiting_approval" :key="branch.id"
                                    @click="viewApproval(schedule.id, branch.id)" variant="outline" size="icon" title="View/Approve">
                                    <Eye class="h-4 w-4" />
                                </Button>
                            </div>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="schedulesAwaitingApproval" />
        </TableContainer>
    </Layout>
</template>
