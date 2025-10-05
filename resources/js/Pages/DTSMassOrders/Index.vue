<script setup>
import { router, Head, usePage } from "@inertiajs/vue3";
import { ref, watch } from 'vue';
import { Eye, Pencil, Filter, Calendar as CalendarIcon } from 'lucide-vue-next';
import { throttle } from "lodash";
import { useAuth } from "@/Composables/useAuth";
import { useToast } from 'primevue/usetoast';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';

const props = defineProps({
    batches: {
        type: Object,
        default: () => ({ data: [] })
    },
    variants: {
        type: Array,
        default: () => []
    },
    filters: {
        type: Object,
        default: () => ({})
    },
    counts: {
        type: Object,
        default: () => ({})
    }
});

const { hasAccess } = useAuth();
const toast = useToast();

// Filter logic
let filterQuery = ref((usePage().props.filters?.filterQuery || "approved").toString());

const performFilter = throttle(() => {
    router.get(
        route("dts-mass-orders.index"),
        {
            filterQuery: filterQuery.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    );
}, 500);

watch(filterQuery, performFilter);

const changeFilter = (currentFilter) => {
    filterQuery.value = currentFilter;
};

const isFilterActive = (filter) => {
    return filterQuery.value === filter ? "bg-primary text-white" : "";
};

const statusBadgeColor = (status) => {
    switch (status?.toUpperCase()) {
        case "APPROVED": return "bg-teal-500 text-white";
        case "COMMITED": return "bg-blue-500 text-white";
        case "INCOMPLETE": return "bg-orange-500 text-white";
        case "RECEIVED": return "bg-green-500 text-white";
        case "PENDING": return "bg-yellow-500 text-white";
        case "COMPLETED": return "bg-green-500 text-white";
        case "CANCELLED": return "bg-red-500 text-white";
        default: return "bg-gray-500 text-white";
    }
};

const formatDisplayDate = (dateString) => {
    if (!dateString || !dateString.includes('-')) return 'N/A';
    try {
        const [year, month, day] = dateString.substring(0, 10).split('-');
        const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
        const monthName = monthNames[parseInt(month, 10) - 1];
        return `${monthName} ${parseInt(day, 10)}, ${year}`;
    } catch (e) {
        return dateString;
    }
};

const formatDisplayDateTime = (dateString) => {
    if (!dateString) return 'N/A';
    try {
        const [datePart, timePart] = dateString.split(' ');
        const [year, month, day] = datePart.split('-');
        const [hourStr, minute] = timePart.split(':');
        let hour = parseInt(hourStr, 10);
        let ampm = 'A.M.';
        if (hour >= 12) {
            ampm = 'P.M.';
            if (hour > 12) hour -= 12;
        }
        if (hour === 0) hour = 12;
        return `${parseInt(month, 10)}/${parseInt(day, 10)}/${year} ${hour}:${minute} ${ampm}`;
    } catch (e) {
        return dateString;
    }
};

const showBatchDetails = (batchNumber) => router.get(route('dts-mass-orders.show', batchNumber));
const editBatchDetails = (batchNumber) => router.get(route('dts-mass-orders.edit', batchNumber));

const showVariantModal = ref(false);
const selectedVariant = ref(null);
const dateFrom = ref(null);
const dateTo = ref(null);
const showFromCalendar = ref(false);
const showToCalendar = ref(false);
const currentFromCalendarDate = ref(new Date());
const currentToCalendarDate = ref(new Date());
const enabledDates = ref([]);

const navigateToCreate = () => {
    selectedVariant.value = null;
    dateFrom.value = null;
    dateTo.value = null;
    enabledDates.value = [];
    showVariantModal.value = true;
};

// Fetch enabled dates when variant is selected
watch(selectedVariant, async (newVariant) => {
    if (newVariant) {
        try {
            const response = await fetch(route('dts-mass-orders.get-available-dates', { variant: newVariant }));
            const dates = await response.json();
            enabledDates.value = dates;

            // Reset selected dates when variant changes
            dateFrom.value = null;
            dateTo.value = null;
        } catch (error) {
            console.error('Error fetching available dates:', error);
            enabledDates.value = [];
        }
    } else {
        enabledDates.value = [];
    }
});

const confirmVariantSelection = async () => {
    if (!selectedVariant.value || !dateFrom.value || !dateTo.value) {
        return;
    }

    // Validate the variant before proceeding (for ICE CREAM and SALMON only)
    try {
        const validationResponse = await fetch(route('dts-mass-orders.validate-variant', { variant: selectedVariant.value }));
        const validationResult = await validationResponse.json();

        if (!validationResult.valid) {
            // Show toast error
            toast.add({
                severity: 'error',
                summary: 'Validation Error',
                detail: validationResult.message,
                life: 5000
            });
            return;
        }

        // If validation passes, proceed to create page
        showVariantModal.value = false;
        router.get(route('dts-mass-orders.create'), {
            variant: selectedVariant.value,
            date_from: dateFrom.value,
            date_to: dateTo.value
        });
    } catch (error) {
        console.error('Error validating variant:', error);
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'Failed to validate variant. Please try again.',
            life: 5000
        });
    }
};

const getCalendarDays = (currentDate) => {
    const days = [];
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    const firstDayOfMonth = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    for (let i = 0; i < firstDayOfMonth; i++) days.push(null);
    for (let i = 1; i <= daysInMonth; i++) {
        const date = new Date(year, month, i);
        const dateString = `${year}-${String(month + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
        const isDisabled = selectedVariant.value && !enabledDates.value.includes(dateString);
        days.push({ day: i, date, isDisabled });
    }
    return days;
};

const goToPrevMonth = (isFrom) => {
    if (isFrom) {
        currentFromCalendarDate.value = new Date(currentFromCalendarDate.value.getFullYear(), currentFromCalendarDate.value.getMonth() - 1, 1);
    } else {
        currentToCalendarDate.value = new Date(currentToCalendarDate.value.getFullYear(), currentToCalendarDate.value.getMonth() - 1, 1);
    }
};

const goToNextMonth = (isFrom) => {
    if (isFrom) {
        currentFromCalendarDate.value = new Date(currentFromCalendarDate.value.getFullYear(), currentFromCalendarDate.value.getMonth() + 1, 1);
    } else {
        currentToCalendarDate.value = new Date(currentToCalendarDate.value.getFullYear(), currentToCalendarDate.value.getMonth() + 1, 1);
    }
};

const selectDate = (day, isFrom) => {
    if (day && !day.isDisabled) {
        const d = day.date;
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const dayOfMonth = String(d.getDate()).padStart(2, '0');
        const dateString = `${year}-${month}-${dayOfMonth}`;

        if (isFrom) {
            dateFrom.value = dateString;
            showFromCalendar.value = false;
        } else {
            dateTo.value = dateString;
            showToCalendar.value = false;
        }
    }
};

</script>

<template>
    <Head title="DTS Mass Orders" />

    <Layout
        heading="DTS Mass Orders"
        :hasButton="hasAccess('create dts mass orders')"
        buttonName="Create DTS Mass Order"
        :handleClick="navigateToCreate"
    >

        <FilterTab>
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('all')"
                @click="changeFilter('all')"
            >
                ALL
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('all')"
                >{{ counts.all || 0 }}</Badge>
            </Button>

            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('approved')"
                @click="changeFilter('approved')"
            >
                APPROVED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('approved')"
                >{{ counts.approved || 0 }}</Badge>
            </Button>

            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('commited')"
                @click="changeFilter('commited')"
            >
                COMMITED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('commited')"
                >{{ counts.commited || 0 }}</Badge>
            </Button>

            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('incomplete')"
                @click="changeFilter('incomplete')"
            >
                INCOMPLETE
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('incomplete')"
                >{{ counts.incomplete || 0 }}</Badge>
            </Button>

            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('received')"
                @click="changeFilter('received')"
            >
                RECEIVED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('received')"
                >{{ counts.received || 0 }}</Badge>
            </Button>
        </FilterTab>

        <TableContainer>
            <div class="hidden md:block">
                <Table>
                    <TableHead>
                        <TH>Batch #</TH>
                        <TH>Variant</TH>
                        <TH>Date Range</TH>
                        <TH>Total Orders</TH>
                        <TH>Total Quantity</TH>
                        <TH>Status</TH>
                        <TH>Created By</TH>
                        <TH>Created At</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-if="!batches.data || batches.data.length === 0">
                            <td colspan="9" class="text-center py-4">No mass orders found.</td>
                        </tr>
                        <tr v-for="batch in batches.data" :key="batch.id">
                            <TD>{{ batch.batch_number }}</TD>
                            <TD><span class="font-semibold text-blue-600">{{ batch.variant }}</span></TD>
                            <TD>{{ formatDisplayDate(batch.date_from) }} - {{ formatDisplayDate(batch.date_to) }}</TD>
                            <TD>{{ batch.total_orders }}</TD>
                            <TD>{{ batch.total_quantity }}</TD>
                            <TD>
                                <Badge :class="statusBadgeColor(batch.status)" class="font-bold">{{ batch.status ? batch.status.toUpperCase() : 'N/A' }}</Badge>
                            </TD>
                            <TD>{{ batch.encoder?.first_name }} {{ batch.encoder?.last_name }}</TD>
                            <TD>{{ formatDisplayDateTime(batch.created_at) }}</TD>
                            <TD>
                                <DivFlexCenter class="gap-3">
                                    <button v-if="hasAccess('view dts mass orders')" @click="showBatchDetails(batch.batch_number)">
                                        <Eye class="size-5" />
                                    </button>
                                    <button v-if="hasAccess('edit dts mass orders') && batch.can_edit" class="text-blue-500" @click="editBatchDetails(batch.batch_number)">
                                        <Pencil class="size-5" />
                                    </button>
                                </DivFlexCenter>
                            </TD>
                        </tr>
                    </TableBody>
                </Table>
            </div>

            <MobileTableContainer class="md:hidden">
                <MobileTableRow v-for="batch in batches.data" :key="batch.id">
                    <MobileTableHeading :title="batch.batch_number">
                        <button v-if="hasAccess('view dts mass orders')" @click="showBatchDetails(batch.batch_number)">
                            <Eye class="size-5" />
                        </button>
                        <button v-if="hasAccess('edit dts mass orders') && batch.can_edit" class="text-blue-500" @click="editBatchDetails(batch.batch_number)">
                            <Pencil class="size-5" />
                        </button>
                    </MobileTableHeading>
                    <LabelXS>Variant: <span class="font-semibold text-blue-600">{{ batch.variant }}</span></LabelXS>
                    <LabelXS>Date Range: {{ formatDisplayDate(batch.date_from) }} - {{ formatDisplayDate(batch.date_to) }}</LabelXS>
                    <LabelXS>Total Orders: {{ batch.total_orders }}</LabelXS>
                    <LabelXS>Total Quantity: {{ batch.total_quantity }}</LabelXS>
                    <LabelXS>Status: <span :class="statusBadgeColor(batch.status)" class="font-semibold p-1 rounded text-white">{{ batch.status ? batch.status.toUpperCase() : 'N/A' }}</span></LabelXS>
                    <LabelXS>Created: {{ formatDisplayDateTime(batch.created_at) }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>

            <Pagination :data="batches" />
        </TableContainer>

        <!-- Variant Selection Modal -->
        <Dialog v-model:visible="showVariantModal" modal header="Create DTS Mass Order" :style="{ width: '60rem', height: 'auto' }" :contentStyle="{ padding: '1.5rem' }">
            <div class="space-y-6">
                <!-- Variant Selection -->
                <div>
                    <label for="variant" class="block text-sm font-medium text-gray-700 mb-2">Choose a variant:</label>
                    <Select
                        id="variant"
                        v-model="selectedVariant"
                        :options="props.variants"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select a variant"
                        class="w-full"
                    />
                </div>

                <!-- Date From (only show when variant is selected) -->
                <div v-if="selectedVariant" class="relative">
                    <label for="date_from" class="block text-sm font-medium text-gray-700 mb-2">Date From:</label>
                    <div class="relative">
                        <input
                            id="date_from"
                            type="text"
                            readonly
                            :value="dateFrom"
                            @click="showFromCalendar = !showFromCalendar"
                            class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm transition-colors placeholder:text-gray-500 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-gray-400 cursor-pointer"
                            placeholder="Select start date"
                        />
                        <CalendarIcon class="absolute right-3 top-1/2 -translate-y-1/2 size-4 text-gray-500 pointer-events-none" />
                    </div>
                    <!-- Calendar Popup for From -->
                    <div v-show="showFromCalendar" class="absolute z-[100] bg-white border border-gray-200 rounded-lg shadow-2xl p-4 w-full min-w-[320px] mt-2">
                        <div class="flex justify-between items-center mb-4">
                            <button type="button" @click.stop="goToPrevMonth(true)" class="p-2 rounded-full hover:bg-gray-100">&lt;</button>
                            <h2 class="text-lg font-semibold">{{ currentFromCalendarDate.toLocaleString('default', { month: 'long', year: 'numeric' }) }}</h2>
                            <button type="button" @click.stop="goToNextMonth(true)" class="p-2 rounded-full hover:bg-gray-100">&gt;</button>
                        </div>
                        <div class="grid grid-cols-7 gap-1 text-center text-xs font-medium text-gray-500 mb-2">
                            <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                        </div>
                        <div class="grid grid-cols-7 gap-2">
                            <template v-for="(day, idx) in getCalendarDays(currentFromCalendarDate)" :key="idx">
                                <div
                                    class="text-center py-1.5 rounded-full text-sm"
                                    :class="[
                                        !day ? '' :
                                        day.isDisabled ? 'bg-gray-200 text-gray-400 cursor-not-allowed' :
                                        (dateFrom && day.date.toDateString() === new Date(dateFrom + 'T00:00:00').toDateString()
                                            ? 'bg-blue-600 text-white font-bold shadow-md cursor-pointer'
                                            : 'bg-gray-100 text-gray-800 font-semibold hover:bg-blue-100 cursor-pointer')
                                    ]"
                                    @click="selectDate(day, true)"
                                >
                                    {{ day ? day.day : '' }}
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Date To (only show when variant is selected) -->
                <div v-if="selectedVariant" class="relative">
                    <label for="date_to" class="block text-sm font-medium text-gray-700 mb-2">Date To:</label>
                    <div class="relative">
                        <input
                            id="date_to"
                            type="text"
                            readonly
                            :value="dateTo"
                            @click="showToCalendar = !showToCalendar"
                            class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm transition-colors placeholder:text-gray-500 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-gray-400 cursor-pointer"
                            placeholder="Select end date"
                        />
                        <CalendarIcon class="absolute right-3 top-1/2 -translate-y-1/2 size-4 text-gray-500 pointer-events-none" />
                    </div>
                    <!-- Calendar Popup for To -->
                    <div v-show="showToCalendar" class="absolute z-[100] bg-white border border-gray-200 rounded-lg shadow-2xl p-4 w-full min-w-[320px] mt-2">
                        <div class="flex justify-between items-center mb-4">
                            <button type="button" @click.stop="goToPrevMonth(false)" class="p-2 rounded-full hover:bg-gray-100">&lt;</button>
                            <h2 class="text-lg font-semibold">{{ currentToCalendarDate.toLocaleString('default', { month: 'long', year: 'numeric' }) }}</h2>
                            <button type="button" @click.stop="goToNextMonth(false)" class="p-2 rounded-full hover:bg-gray-100">&gt;</button>
                        </div>
                        <div class="grid grid-cols-7 gap-1 text-center text-xs font-medium text-gray-500 mb-2">
                            <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                        </div>
                        <div class="grid grid-cols-7 gap-2">
                            <template v-for="(day, idx) in getCalendarDays(currentToCalendarDate)" :key="idx">
                                <div
                                    class="text-center py-1.5 rounded-full text-sm"
                                    :class="[
                                        !day ? '' :
                                        day.isDisabled ? 'bg-gray-200 text-gray-400 cursor-not-allowed' :
                                        (dateTo && day.date.toDateString() === new Date(dateTo + 'T00:00:00').toDateString()
                                            ? 'bg-blue-600 text-white font-bold shadow-md cursor-pointer'
                                            : 'bg-gray-100 text-gray-800 font-semibold hover:bg-blue-100 cursor-pointer')
                                    ]"
                                    @click="selectDate(day, false)"
                                >
                                    {{ day ? day.day : '' }}
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
            <template #footer>
                <div class="flex gap-2 justify-end">
                    <Button
                        variant="destructive"
                        @click="showVariantModal = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        class="bg-green-600 hover:bg-green-700 text-white"
                        @click="confirmVariantSelection"
                        :disabled="!selectedVariant || !dateFrom || !dateTo"
                    >
                        Confirm
                    </Button>
                </div>
            </template>
        </Dialog>
    </Layout>
</template>
