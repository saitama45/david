<script setup>
import { useForm, Head, router } from '@inertiajs/vue3';
import { Download, Upload, Eye, ArrowUp, ArrowDown } from 'lucide-vue-next';
import { ref, computed, watch } from 'vue';
import { throttle } from 'lodash';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    downloadSchedule: { type: Object, default: null },
    uploadSchedule: { type: Object, default: null },
    message: { type: String, required: true },
    userBranches: { type: Object, required: true },
    branchesAwaitingUpload: { type: Object, required: true },
    uploadedCountsAwaitingSubmission: { type: Array, required: true },
    transactions: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const selectedBranchId = ref(null);

const uploadForm = useForm({
    schedule_id: props.uploadSchedule ? props.uploadSchedule.id : null,
    branch_id: null,
    file: null,
});

const sortKey = ref(props.filters.sort || 'calculated_date');
const sortDir = ref(props.filters.direction || 'desc');

const filterForm = ref({
    year: props.filters.year || null,
    month: props.filters.month || null,
    calculated_date: props.filters.calculated_date || null,
    status: props.filters.status || null,
    uploader_name: props.filters.uploader_name || null,
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
    router.get(route('month-end-count.index'), {
        ...filterForm.value,
        sort: sortKey.value,
        direction: sortDir.value,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300), { deep: true });

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

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    if (isNaN(date.getTime())) {
        return dateString;
    }
    const userTimezoneOffset = date.getTimezoneOffset() * 60000;
    const correctedDate = new Date(date.getTime() + userTimezoneOffset);
    return new Intl.DateTimeFormat('en-US', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(correctedDate);
};

const submitUpload = () => {
    console.log('submitUpload called');
    if (!uploadForm.branch_id) {
        alert('Please select a branch.');
        return;
    }
    if (!uploadForm.file) {
        alert('Please select a file to upload.');
        return;
    }
    console.log('Uploading file:', uploadForm.file);
    uploadForm.post(route('month-end-count.upload'), {
        onSuccess: () => {
            console.log('Upload successful!');
            uploadForm.reset();
            selectedBranchId.value = null;
        },
        onError: (errors) => {
            console.error('Upload Error:', errors);
            alert('Upload failed. Check console for details.');
        }
    });
};

const getMonthName = (monthNumber) => {
    const date = new Date();
    date.setMonth(monthNumber - 1);
    return date.toLocaleString('en-US', { month: 'long' });
};

const hasBranchesToUpload = computed(() => {
    return Object.keys(props.branchesAwaitingUpload).length > 0;
});

const viewReviewPage = (scheduleId, branchId) => {
    console.log('Review button clicked for schedule:', scheduleId, 'branch:', branchId);
    router.get(route('month-end-count.review', { schedule: scheduleId, branch: branchId }));
};

</script>

<template>
    <Head title="Month End Count" />

    <Layout heading="Month End Count">
        <div class="max-w-2xl mx-auto bg-white p-6 rounded-lg shadow-md">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Month End Count Process</h2>

            <!-- Download Section -->
            <div v-if="downloadSchedule" class="mb-6 p-4 border border-blue-300 bg-blue-50 rounded-md text-blue-800">
                <p class="font-medium">{{ message }}</p>
                <p class="text-sm mt-1">Scheduled for: {{ getMonthName(downloadSchedule.month) }} {{ downloadSchedule.year }} (Date: {{ downloadSchedule.calculated_date }})</p>

                <div class="mt-4">
                    <Label for="download_branch">Select Branch for Download</Label>
                    <Select
                        id="download_branch"
                        v-model="selectedBranchId"
                        :options="Object.entries(userBranches).map(([id, name]) => ({ value: id, label: name }))"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select a Branch"
                        filter
                        class="w-full"
                    />
                </div>

                <a v-if="selectedBranchId" :href="route('month-end-count.download', { schedule_id: downloadSchedule.id, branch_id: selectedBranchId })"
                   class="mt-4 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <Download class="-ml-1 mr-2 h-5 w-5" />
                    Download Count Template
                </a>
                <p v-else class="mt-4 text-sm text-gray-600">Please select a branch to enable download.</p>
            </div>

            <!-- Upload Section -->
            <div v-else-if="uploadSchedule && hasBranchesToUpload" class="mb-6 p-4 border border-green-300 bg-green-50 rounded-md text-green-800">
                <p class="font-medium">{{ message }}</p>
                <p class="text-sm mt-1">Scheduled for: {{ getMonthName(uploadSchedule.month) }} {{ uploadSchedule.year }} (Date: {{ uploadSchedule.calculated_date }})</p>
                
                <form @submit.prevent="submitUpload" class="mt-4 space-y-4">
                    <div>
                        <Label for="upload_branch">Select Branch for Upload</Label>
                        <Select
                            id="upload_branch"
                            v-model="uploadForm.branch_id"
                            :options="Object.entries(branchesAwaitingUpload).map(([id, name]) => ({ value: id, label: name }))"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Select a Branch"
                            filter
                            class="w-full"
                        />
                        <InputError :message="uploadForm.errors.branch_id" />
                    </div>
                    <div>
                        <label for="file" class="block text-sm font-medium text-gray-700">Upload Completed Count Sheet</label>
                        <input type="file" @input="uploadForm.file = $event.target.files[0]" id="file" class="mt-1 block w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200"/>
                        <InputError :message="uploadForm.errors.file" />
                    </div>
                    <div class="flex justify-end">
                        <Button type="submit" :disabled="uploadForm.processing || !uploadForm.file || !uploadForm.branch_id">
                            <Upload class="-ml-1 mr-2 h-5 w-5" />
                            Upload and Process Count
                        </Button>
                    </div>
                </form>
            </div>

            <!-- Uploaded Counts Awaiting Submission -->
            <div v-if="uploadedCountsAwaitingSubmission.length" class="mt-6 p-4 border border-purple-300 bg-purple-50 rounded-md text-purple-800">
                <p class="font-medium mb-2">Your Uploaded Counts Awaiting Submission:</p>
                <ul class="list-disc pl-5 space-y-1">
                    <li v-for="item in uploadedCountsAwaitingSubmission" :key="`${item.schedule_id}-${item.branch_id}`">
                        Count for {{ getMonthName(item.schedule_month) }} {{ item.schedule_year }} (Branch: {{ item.branch_name }})
                        <Button @click="viewReviewPage(item.schedule_id, item.branch_id)" variant="link" size="sm" class="ml-2 p-0 h-auto">
                            <Eye class="h-4 w-4 mr-1" /> Review
                        </Button>
                    </li>
                </ul>
            </div>

            <div v-else class="mb-6 p-4 border border-gray-300 bg-gray-50 rounded-md text-gray-800">
                <p class="font-medium">{{ message }}</p>
            </div>
        </div>

        <div class="mt-8">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Transaction History</h2>
            <TableContainer>
                <Table>
                    <TableHead>
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
                            <div class="flex items-center cursor-pointer" @click="sortBy('branch_name')">
                                Branch
                                <ArrowUp v-if="sortKey === 'branch_name' && sortDir === 'asc'" class="h-4 w-4 ml-1" />
                                <ArrowDown v-if="sortKey === 'branch_name' && sortDir === 'desc'" class="h-4 w-4 ml-1" />
                            </div>
                            <Input type="text" v-model="filterForm.branch_name" placeholder="Filter..." class="mt-1 w-full text-sm" @keydown.stop />
                        </TH>
                        <TH>
                            <div class="flex items-center cursor-pointer" @click="sortBy('uploader_name')">
                                Uploader
                                <ArrowUp v-if="sortKey === 'uploader_name' && sortDir === 'asc'" class="h-4 w-4 ml-1" />
                                <ArrowDown v-if="sortKey === 'uploader_name' && sortDir === 'desc'" class="h-4 w-4 ml-1" />
                            </div>
                            <Input type="text" v-model="filterForm.uploader_name" placeholder="Filter..." class="mt-1 w-full text-sm" @keydown.stop />
                        </TH>
                        <TH>
                            <div class="flex items-center cursor-pointer" @click="sortBy('statuses')">
                                Status
                                <ArrowUp v-if="sortKey === 'statuses' && sortDir === 'asc'" class="h-4 w-4 ml-1" />
                                <ArrowDown v-if="sortKey === 'statuses' && sortDir === 'desc'" class="h-4 w-4 ml-1" />
                            </div>
                            <Input type="text" v-model="filterForm.status" placeholder="Filter..." class="mt-1 w-full text-sm" @keydown.stop />
                        </TH>
                    </TableHead>
                    <TableBody>
                        <tr v-if="!transactions.data.length">
                            <td colspan="6" class="text-center py-4">No transactions found.</td>
                        </tr>
                        <tr v-for="transaction in transactions.data" :key="`${transaction.schedule_id}-${transaction.branch_id}`">
                            <TD>{{ transaction.year }}</TD>
                            <TD>{{ getMonthName(transaction.month) }}</TD>
                            <TD>{{ formatDate(transaction.calculated_date) }}</TD>
                            <TD>{{ transaction.branch_name }}</TD>
                            <TD>{{ transaction.uploader_name }}</TD>
                            <TD>
                                <div class="flex flex-wrap gap-1">
                                    <Badge v-for="status in [...new Set(transaction.statuses.split(', '))]" :key="status" class="capitalize" :class="{
                                        'bg-yellow-500 text-white': status === 'pending' || status === 'uploaded',
                                        'bg-blue-500 text-white': status === 'level1_approved',
                                        'bg-green-500 text-white': status === 'level2_approved',
                                        'bg-red-500 text-white': status === 'rejected' || status === 'expired',
                                        'bg-purple-500 text-white': status === 'pending_level1_approval',
                                    }">{{ status.replace(/_/g, ' ') }}</Badge>
                                </div>
                            </TD>
                        </tr>
                    </TableBody>
                </Table>
                <Pagination :data="transactions" />
            </TableContainer>
        </div>
    </Layout>
</template>