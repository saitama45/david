<script setup>
import { useForm, Head, router } from '@inertiajs/vue3';
import { Download, Upload, Eye } from 'lucide-vue-next'; // Added Eye icon
import { ref, computed } from 'vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    downloadSchedule: { type: Object, default: null },
    uploadSchedule: { type: Object, default: null },
    message: { type: String, required: true },
    userBranches: { type: Object, required: true },
    branchesAwaitingUpload: { type: Object, required: true }, // New prop
    uploadedCountsAwaitingSubmission: { type: Array, required: true }, // New prop
});

const selectedBranchId = ref(null);

const uploadForm = useForm({
    schedule_id: props.uploadSchedule ? props.uploadSchedule.id : null,
    branch_id: null,
    file: null,
});

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
    date.setMonth(monthNumber - 1); // Month is 0-indexed
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
    </Layout>
</template>