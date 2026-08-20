<script setup>
import { useForm, Head, router } from '@inertiajs/vue3';
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { CalendarCheck2, Trash2, Pencil, Save, X, Plus, Loader2, Search, CalendarDays, SlidersHorizontal } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import { ref, reactive, watch, computed } from 'vue';
import { throttle } from 'lodash';
import axios from 'axios';
import Dialog from "primevue/dialog";

const props = defineProps({
    schedules: { type: Object, required: true },
    can: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    settings: { type: Object, required: true },
});

// --- Tabs ---
const activeTab = ref('schedules');

// --- Configuration form ---
const dayUnitOptions = [
    { value: 'business', label: 'Business days (skip weekends)' },
    { value: 'calendar', label: 'Calendar days' },
];

const settingsForm = useForm({
    download_lead_days: props.settings.download_lead_days,
    download_lead_unit: props.settings.download_lead_unit,
    block_on_weekends: props.settings.block_on_weekends,
    upload_start_days: props.settings.upload_start_days,
    upload_start_unit: props.settings.upload_start_unit,
    upload_cutoff_enabled: props.settings.upload_cutoff_enabled,
    upload_cutoff_days: props.settings.upload_cutoff_days,
    upload_cutoff_unit: props.settings.upload_cutoff_unit,
    // <input type="time"> expects HH:mm; the backend stores/returns HH:mm:ss.
    upload_cutoff_time: (props.settings.upload_cutoff_time || '23:59:00').slice(0, 5),
});

const saveSettings = () => {
    settingsForm.put(route('month-end-schedules.settings.update'), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({ severity: 'success', summary: 'Saved', detail: 'Month End Count configuration saved.', life: 3000 });
        },
        onError: (errors) => {
            const detail = Object.values(errors)[0] || 'Please review the highlighted fields.';
            toast.add({ severity: 'error', summary: 'Save Failed', detail, life: 5000 });
        },
    });
};

const confirm = useConfirm();
const toast = useToast();

// --- Forms ---
const generateForm = useForm({
    year: new Date().getFullYear(),
});

const filterForm = reactive({
    year: props.filters.year,
});

const editForm = useForm({
    calculated_date: '',
});

// --- State ---
const showGenerateModal = ref(false);
const showDetailsModal = ref(false);
const editingScheduleId = ref(null);
const selectedSchedule = ref(null);
const detailsResponse = ref(null);
const isLoadingDetails = ref(false);
const detailSearchQuery = ref('');

// --- Watchers ---
watch(filterForm, throttle(() => {
    router.get(route('month-end-schedules.index'), {
        year: filterForm.year
    }, {
        preserveState: true,
        replace: true,
    });
}, 300));

watch(detailSearchQuery, throttle((newValue) => {
    fetchDetails(1, newValue);
}, 300));

// --- Computed Properties ---
// Editing the count date stays available after stores have submitted, because
// moving it is the blunt way to reopen a whole month. Prefer the per-store
// reopen in the Store Progress modal, which leaves this date alone.
const isEditable = (schedule) => props.can.edit_month_end_schedules;

const isDeletable = (schedule) => {
    if (!props.can.delete_month_end_schedules) return false;
    const submittedCount = Object.values(schedule.progress || {}).reduce((a, b) => a + b, 0);
    return submittedCount === 0;
};

const isProgressClickable = (schedule) => {
    const submittedCount = Object.values(schedule.progress || {}).reduce((a, b) => a + b, 0);
    return submittedCount > 0;
};

const detailItems = computed(() => detailsResponse.value?.data || []);

// --- Methods ---
const fetchDetails = async (page = 1, search = '') => {
    if (!selectedSchedule.value) return;
    isLoadingDetails.value = true;
    try {
        const response = await axios.get(route('month-end-schedules.details', selectedSchedule.value.id), {
            params: { page, search }
        });
        detailsResponse.value = response.data;
    } catch (error) {
        console.error("Failed to fetch schedule details:", error);
    } finally {
        isLoadingDetails.value = false;
    }
};

const openDetailsModal = (schedule) => {
    selectedSchedule.value = schedule;
    detailSearchQuery.value = '';
    detailsResponse.value = null;
    selectedReopenBranches.value = [];
    reopenUntil.value = defaultReopenUntil();
    showDetailsModal.value = true;
    fetchDetails(1, '');
};

const goToDetailsPage = (page) => {
    if (page > 0 && page <= detailsResponse.value.last_page) {
        fetchDetails(page, detailSearchQuery.value);
    }
};

const startEditing = (schedule) => {
    if (!isEditable(schedule)) return;
    editingScheduleId.value = schedule.id;

    const date = new Date(schedule.calculated_date);
    // The 'en-CA' locale formats dates as YYYY-MM-DD, which is what the date input expects.
    // We specify the Manila timezone to ensure we get the correct date parts for that zone.
    const formattedDate = new Intl.DateTimeFormat('en-CA', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        timeZone: 'Asia/Manila'
    }).format(date);

    editForm.calculated_date = formattedDate;
};

const cancelEditing = () => {
    editingScheduleId.value = null;
    editForm.reset();
    editForm.clearErrors();
};

const updateSchedule = (scheduleId) => {
    router.put(route('month-end-schedules.update', scheduleId), {
        calculated_date: editForm.calculated_date,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            cancelEditing();
            toast.add({ severity: 'success', summary: 'Success', detail: 'MEC Schedule Date updated successfully', life: 3000 });
        },
    });
};

const submitGenerate = () => {
    generateForm.post(route('month-end-schedules.store'), {
        onSuccess: () => {
            generateForm.reset();
            showGenerateModal.value = false;
        },
    });
};

// --- Reopening the upload window for specific stores ---
// Support grants this after their approver signs off. It targets only the
// stores selected and leaves the schedule's count date alone.
const selectedReopenBranches = ref([]);
const reopenUntil = ref('');
const isReopening = ref(false);

const reopenableItems = computed(() => detailItems.value.filter((i) => i.can_reopen));

const allReopenableSelected = computed(
    () => reopenableItems.value.length > 0
        && reopenableItems.value.every((i) => selectedReopenBranches.value.includes(i.id)),
);

const toggleAllReopenable = () => {
    selectedReopenBranches.value = allReopenableSelected.value
        ? []
        : reopenableItems.value.map((i) => i.id);
};

// Default the deadline to 3 days out at 11:59 PM — a working grace period that
// support can shorten or extend.
const defaultReopenUntil = () => {
    const d = new Date();
    d.setDate(d.getDate() + 3);
    d.setHours(23, 59, 0, 0);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const submitReopen = () => {
    if (!selectedReopenBranches.value.length || !reopenUntil.value) return;

    isReopening.value = true;
    router.post(
        route('month-end-schedules.reopen', selectedSchedule.value.id),
        {
            branch_ids: selectedReopenBranches.value,
            reopened_until: reopenUntil.value.replace('T', ' ') + ':00',
        },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                selectedReopenBranches.value = [];
                fetchDetails();
                toast.add({
                    severity: 'success',
                    summary: 'Upload reopened',
                    detail: page?.props?.flash?.success || 'The selected stores can upload again.',
                    life: 6000,
                });
            },
            onError: (errors) => {
                toast.add({
                    severity: 'error',
                    summary: 'Reopen failed',
                    detail: errors.error || 'Could not reopen the upload window.',
                    life: 6000,
                });
            },
            onFinish: () => { isReopening.value = false; },
        },
    );
};

const deleteSchedule = (scheduleId) => {
    confirm.require({
        message: 'Are you sure you want to delete this schedule entry? This action cannot be undone.',
        header: 'Confirmation',
        icon: 'pi pi-exclamation-triangle',
        accept: () => {
            router.delete(route('month-end-schedules.destroy', scheduleId), { preserveScroll: true });
        },
    });
};

// --- Formatting & Display ---
const formatDisplayDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'Asia/Manila' });
};

const getMonthName = (monthNumber) => {
    if (!monthNumber) return '';
    const date = new Date();
    date.setMonth(monthNumber - 1);
    return date.toLocaleString('en-US', { month: 'long' });
};

const progressSummary = (schedule) => {
    const totalStores = schedule.total_stores || 0;
    const submittedCount = Object.values(schedule.progress || {}).reduce((a, b) => a + b, 0);
    return `${submittedCount} / ${totalStores}`;
};

const statusColors = {
    'pending': '#f59e0b', // amber-500
    'uploaded': '#f59e0b', // amber-500
    'pending_level1_approval': '#8b5cf6', // violet-500
    'level1_approved': '#3b82f6', // blue-500
    'level2_approved': '#22c55e', // green-500
    'rejected': '#ef4444', // red-500
    'expired': '#ef4444', // red-500
    'not_started': '#e5e7eb', // gray-200
};

const getProgressSegments = (schedule) => {
    const statuses = schedule.progress || {};
    const totalStores = schedule.total_stores || 0;
    if (totalStores === 0) return [];

    const segments = [];
    let submittedCount = 0;
    const statusOrder = ['level2_approved', 'level1_approved', 'pending_level1_approval', 'uploaded', 'pending', 'rejected', 'expired'];

    statusOrder.forEach(status => {
        if (statuses[status]) {
            const count = statuses[status];
            segments.push({ percentage: (count / totalStores) * 100, color: statusColors[status] || '#6b7280', label: status, count });
            submittedCount += count;
        }
    });

    const notStarted = totalStores - submittedCount;
    if (notStarted > 0) {
        segments.push({ percentage: (notStarted / totalStores) * 100, color: statusColors['not_started'], label: 'Not Started', count: notStarted });
    }
    return segments.reverse();
};

const getProgressTooltip = (segments) => {
    if (!segments || !segments.length) return 'No store data.';
    return [...segments].reverse().map(s => `${s.label.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}: ${s.count}`).join('\n');
};

const getStatusColor = (status) => {
    const normalizedStatus = status.toLowerCase().replace(/ /g, '_');
    return statusColors[normalizedStatus] || statusColors['not_started'];
};

</script>

<template>
    <Head title="Month End Schedules" />

    <Layout heading="Month End Schedules Management">
        <!-- Tabs -->
        <div class="border-b border-gray-200 mb-6">
            <nav class="-mb-px flex gap-6">
                <button
                    type="button"
                    @click="activeTab = 'schedules'"
                    class="inline-flex items-center gap-2 py-3 px-1 border-b-2 text-sm font-medium transition-colors"
                    :class="activeTab === 'schedules' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                >
                    <CalendarDays class="h-4 w-4" />
                    Schedules
                </button>
                <button
                    v-if="can.manage_month_end_count_settings"
                    type="button"
                    @click="activeTab = 'configuration'"
                    class="inline-flex items-center gap-2 py-3 px-1 border-b-2 text-sm font-medium transition-colors"
                    :class="activeTab === 'configuration' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                >
                    <SlidersHorizontal class="h-4 w-4" />
                    Configuration
                </button>
            </nav>
        </div>

        <!-- Schedules Tab -->
        <div v-show="activeTab === 'schedules'">
        <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
            <div class="flex justify-between items-center">
                <div class="w-56">
                    <Label for="year_filter">Filter by Year</Label>
                    <Input
                        id="year_filter"
                        type="number"
                        v-model="filterForm.year"
                        min="2000"
                        max="2099"
                        class="w-full mt-1"
                        placeholder="Enter Year"
                    />
                </div>
                <Button v-if="can.create_month_end_schedules" @click="showGenerateModal = true" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">
                    <Plus class="-ml-1 mr-2 h-5 w-5" />
                    Generate Schedules
                </Button>
            </div>
        </div>

        <div v-if="schedules.data.length > 0" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">
            <div v-for="schedule in schedules.data" :key="schedule.id" class="bg-white rounded-lg shadow-md flex flex-col justify-between transition-transform duration-300 ease-in-out hover:-translate-y-1 border-t-4 border-indigo-400">
                <div class="p-4 flex justify-between items-start">
                    <div>
                        <h3 class="text-xl font-bold text-indigo-800">{{ getMonthName(schedule.month) }}</h3>
                        <p class="text-sm text-gray-500">{{ schedule.year }}</p>
                    </div>
                    <Button v-if="isDeletable(schedule)" @click.stop="deleteSchedule(schedule.id)" variant="ghost" size="icon" class="text-gray-400 hover:text-red-500 hover:bg-red-50 -mt-1 -mr-2">
                        <Trash2 class="h-5 w-5" />
                    </Button>
                </div>

                <div class="px-4 pb-4 space-y-5 flex-grow">
                    <div>
                        <Label class="text-xs font-semibold text-gray-500 uppercase tracking-wider">MEC Schedule Date</Label>
                        <div v-if="editingScheduleId === schedule.id" class="flex items-center gap-2 mt-1">
                            <Input type="date" v-model="editForm.calculated_date" class="w-full py-1" @click.stop />
                            <Button @click.stop="updateSchedule(schedule.id)" size="icon" variant="ghost" class="text-green-600 hover:bg-green-100 h-8 w-8 shrink-0"><Save class="h-4 w-4" /></Button>
                            <Button @click.stop="cancelEditing" size="icon" variant="ghost" class="text-red-600 hover:bg-red-100 h-8 w-8 shrink-0"><X class="h-4 w-4" /></Button>
                        </div>
                        <div v-else @click="startEditing(schedule)" class="p-2 -ml-2 rounded min-h-[32px] flex items-center justify-between group w-full transition-all duration-150" :class="{ 'cursor-pointer bg-blue-50 hover:bg-blue-100': isEditable(schedule), 'cursor-not-allowed': !isEditable(schedule) }">
                            <span class="font-medium text-gray-800 text-lg">{{ formatDisplayDate(schedule.calculated_date) }}</span>
                            <Pencil v-if="isEditable(schedule)" class="h-4 w-4 text-blue-400 opacity-0 group-hover:opacity-100" />
                        </div>
                    </div>

                    <div
                        @click="isProgressClickable(schedule) ? openDetailsModal(schedule) : null"
                        :data-testid="isProgressClickable(schedule) ? 'schedule-progress' : null"
                        class="group"
                        :class="{ 'cursor-pointer': isProgressClickable(schedule), 'cursor-default': !isProgressClickable(schedule) }"
                    >
                        <div class="flex justify-between items-baseline mb-1">
                            <Label
                                class="text-xs font-semibold text-gray-500 uppercase tracking-wider"
                                :class="{ 'group-hover:text-indigo-600': isProgressClickable(schedule) }"
                            >Progress</Label>
                            <span class="text-sm font-semibold text-gray-800">{{ progressSummary(schedule) }}</span>
                        </div>
                        <div v-if="schedule.total_stores > 0" class="w-full bg-gray-200 rounded-full h-3" :title="getProgressTooltip(getProgressSegments(schedule))">
                            <div class="flex h-3 rounded-full">
                                <div v-for="(segment, index) in getProgressSegments(schedule)" :key="index" class="transition-all duration-300" :style="{ width: segment.percentage + '%', backgroundColor: segment.color }"></div>
                            </div>
                        </div>
                        <div v-else class="text-sm text-gray-500 mt-1">No active stores.</div>
                    </div>
                </div>

                <div class="p-3 bg-gray-50 border-t border-gray-200 text-xs text-gray-500">
                    Created by: <span class="font-medium text-gray-700">{{ schedule.creator ? `${schedule.creator.first_name} ${schedule.creator.last_name}` : 'N/A' }}</span>
                </div>
            </div>
        </div>

        <div v-else class="text-center py-16 text-gray-500 bg-white rounded-lg shadow-sm">
            <CalendarCheck2 class="mx-auto h-12 w-12 text-gray-400" />
            <h3 class="mt-4 text-lg font-medium text-gray-800">No Schedules Found</h3>
            <p class="mt-1 text-sm">No schedules have been generated for the selected year.</p>
        </div>
        <Pagination v-if="schedules.data.length > 0" :data="schedules" class="mt-6" />
        </div>

        <!-- Configuration Tab -->
        <div v-show="activeTab === 'configuration'" v-if="can.manage_month_end_count_settings">
            <form @submit.prevent="saveSettings" class="max-w-3xl space-y-6">
                <!-- Download Window -->
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-800">Download Window</h3>
                    <p class="text-sm text-gray-500 mt-1">Controls when the count template becomes downloadable, relative to the MEC Schedule Date.</p>
                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <Label for="download_lead_days">Open download this many days before</Label>
                            <Input id="download_lead_days" type="number" min="0" max="90" v-model="settingsForm.download_lead_days" class="w-full mt-1" />
                            <InputError :message="settingsForm.errors.download_lead_days" class="mt-1" />
                        </div>
                        <div>
                            <Label for="download_lead_unit">Counted as</Label>
                            <Select id="download_lead_unit" v-model="settingsForm.download_lead_unit" :options="dayUnitOptions" optionLabel="label" optionValue="value" class="w-full mt-1" />
                            <InputError :message="settingsForm.errors.download_lead_unit" class="mt-1" />
                        </div>
                    </div>
                    <label class="mt-4 flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" v-model="settingsForm.block_on_weekends" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                        <span class="text-sm text-gray-700">Block template download on weekends</span>
                    </label>
                    <InputError :message="settingsForm.errors.block_on_weekends" class="mt-1" />
                </div>

                <!-- Upload Window -->
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-800">Upload Window</h3>
                    <p class="text-sm text-gray-500 mt-1">Controls when completed counts can be uploaded, relative to the MEC Schedule Date.</p>
                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <Label for="upload_start_days">Open upload this many days after</Label>
                            <Input id="upload_start_days" type="number" min="0" max="90" v-model="settingsForm.upload_start_days" class="w-full mt-1" />
                            <InputError :message="settingsForm.errors.upload_start_days" class="mt-1" />
                        </div>
                        <div>
                            <Label for="upload_start_unit">Counted as</Label>
                            <Select id="upload_start_unit" v-model="settingsForm.upload_start_unit" :options="dayUnitOptions" optionLabel="label" optionValue="value" class="w-full mt-1" />
                            <InputError :message="settingsForm.errors.upload_start_unit" class="mt-1" />
                        </div>
                    </div>

                    <div class="mt-6 pt-4 border-t border-gray-100">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" v-model="settingsForm.upload_cutoff_enabled" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            <span class="text-sm font-medium text-gray-700">Enforce an upload cutoff (deadline)</span>
                        </label>
                        <div v-if="settingsForm.upload_cutoff_enabled" class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <Label for="upload_cutoff_days">Cutoff this many days after</Label>
                                <Input id="upload_cutoff_days" type="number" min="0" max="90" v-model="settingsForm.upload_cutoff_days" class="w-full mt-1" />
                                <InputError :message="settingsForm.errors.upload_cutoff_days" class="mt-1" />
                            </div>
                            <div>
                                <Label for="upload_cutoff_unit">Counted as</Label>
                                <Select id="upload_cutoff_unit" v-model="settingsForm.upload_cutoff_unit" :options="dayUnitOptions" optionLabel="label" optionValue="value" class="w-full mt-1" />
                                <InputError :message="settingsForm.errors.upload_cutoff_unit" class="mt-1" />
                            </div>
                            <div>
                                <Label for="upload_cutoff_time">At time</Label>
                                <Input id="upload_cutoff_time" type="time" v-model="settingsForm.upload_cutoff_time" class="w-full mt-1" />
                                <InputError :message="settingsForm.errors.upload_cutoff_time" class="mt-1" />
                            </div>
                        </div>
                        <p v-else class="mt-2 text-sm text-gray-500">No deadline — uploads stay open indefinitely after the start date.</p>
                    </div>
                </div>

                <div class="flex justify-end">
                    <Button type="submit" :disabled="settingsForm.processing" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">
                        <Save class="-ml-1 mr-2 h-5 w-5" />
                        Save Configuration
                    </Button>
                </div>
            </form>
        </div>
    </Layout>

    <!-- Generate Modal -->
    <Dialog v-model:visible="showGenerateModal" modal header="Generate Schedules" :style="{ width: '32rem' }">
        <form @submit.prevent="submitGenerate" class="mt-4">
            <div v-if="generateForm.errors.error" class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                <p class="font-bold">Error</p>
                <p>{{ generateForm.errors.error }}</p>
            </div>
            <p class="text-sm text-gray-600 mb-6">Generate schedules for an entire year. This will not override existing schedules for the selected year.</p>
            <Label for="generate_year">Year</Label>
            <Input id="generate_year" type="number" v-model="generateForm.year" min="2000" max="2099" class="w-full mt-1" placeholder="Enter Year" />
            <InputError :message="generateForm.errors.year" class="mt-2" />

            <div class="flex justify-end gap-3 mt-8">
                <Button type="button" variant="outline" @click="showGenerateModal = false">Cancel</Button>
                <Button type="submit" :disabled="generateForm.processing" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">Generate</Button>
            </div>
        </form>
    </Dialog>

    <!-- Details Modal -->
    <Dialog v-model:visible="showDetailsModal" modal :header="`Store Progress for ${getMonthName(selectedSchedule?.month)} ${selectedSchedule?.year}`" :style="{ width: '40rem' }">
        <div class="mt-4 space-y-4">
            <div class="relative group">
                <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400 transition-colors group-focus-within:text-indigo-500" />
                <Input
                    v-model="detailSearchQuery"
                    placeholder="Search for a store..."
                    class="w-full pl-10 py-2 bg-gray-100 border-transparent rounded-lg focus:outline-none focus:bg-white"
                />
            </div>

            <div class="border rounded-lg">
                <div v-if="isLoadingDetails && !detailItems.length" class="flex justify-center items-center h-48">
                    <Loader2 class="h-8 w-8 text-indigo-400 animate-spin" />
                </div>
                <ul v-else-if="detailItems.length > 0" class="divide-y max-h-[50vh] overflow-y-auto">
                    <li v-for="item in detailItems" :key="item.id" class="flex items-center justify-between p-3 hover:bg-gray-50 gap-3">
                        <label class="flex items-center gap-3 min-w-0 flex-1" :class="{ 'cursor-pointer': can.reopen_month_end_count && item.can_reopen }">
                            <input
                                v-if="can.reopen_month_end_count"
                                type="checkbox"
                                :value="item.id"
                                v-model="selectedReopenBranches"
                                :disabled="!item.can_reopen"
                                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-40 shrink-0"
                            />
                            <span class="min-w-0">
                                <span class="text-gray-800 font-medium truncate block">{{ item.name }}</span>
                                <span v-if="item.reopen_active" class="text-xs text-amber-700">
                                    Reopened until {{ item.reopened_until }}
                                </span>
                            </span>
                        </label>
                        <span class="px-2.5 py-0.5 text-xs font-semibold rounded-full shrink-0"
                                :style="{ backgroundColor: getStatusColor(item.status) + '20', color: getStatusColor(item.status) }">
                            {{ item.status }}
                        </span>
                    </li>
                </ul>
                <div v-else class="text-center py-12 text-gray-500">
                    <p>No stores found for your search.</p>
                </div>
            </div>

            <!-- Reopen the upload window for the stores selected above -->
            <div v-if="can.reopen_month_end_count" class="border rounded-lg p-4 bg-amber-50 border-amber-200 space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold text-amber-900">Reopen upload for selected stores</p>
                        <p class="text-xs text-amber-800 mt-0.5">
                            Only stores that have not submitted can be reopened. This does not change the MEC Schedule Date.
                        </p>
                    </div>
                    <Button type="button" variant="outline" size="sm" :disabled="!reopenableItems.length" @click="toggleAllReopenable">
                        {{ allReopenableSelected ? 'Clear' : 'Select all' }}
                    </Button>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[14rem]">
                        <label for="reopen_until" class="block text-xs font-medium text-amber-900 mb-1">Allow uploads until</label>
                        <Input id="reopen_until" v-model="reopenUntil" type="datetime-local" class="w-full bg-white" />
                    </div>
                    <Button
                        type="button"
                        :disabled="!selectedReopenBranches.length || !reopenUntil || isReopening"
                        class="bg-amber-600 hover:bg-amber-700 text-white font-semibold"
                        @click="submitReopen"
                    >
                        <Loader2 v-if="isReopening" class="h-4 w-4 mr-2 animate-spin" />
                        Reopen {{ selectedReopenBranches.length ? `(${selectedReopenBranches.length})` : '' }}
                    </Button>
                </div>
            </div>

            <div v-if="detailsResponse && detailsResponse.last_page > 1" class="flex justify-between items-center">
                <span class="text-sm text-gray-600">Page {{ detailsResponse.current_page }} of {{ detailsResponse.last_page }}</span>
                <div class="flex gap-2">
                    <Button @click="goToDetailsPage(detailsResponse.current_page - 1)" :disabled="!detailsResponse.prev_page_url" variant="outline">Previous</Button>
                    <Button @click="goToDetailsPage(detailsResponse.current_page + 1)" :disabled="!detailsResponse.next_page_url" variant="outline">Next</Button>
                </div>
            </div>
        </div>
    </Dialog>
</template>
