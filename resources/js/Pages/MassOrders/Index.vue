<script setup>
import { router, Head, useForm, usePage } from "@inertiajs/vue3";
import Dialog from "primevue/dialog";
import Select from 'primevue/select';
import Button from 'primevue/button';
import { ref, watch, computed } from 'vue';
import axios from 'axios';
import { Calendar as CalendarIcon, Download, Upload, Eye, Pencil } from 'lucide-vue-next';
import { throttle } from "lodash";
import { useAuth } from "@/Composables/useAuth";
import { useSelectOptions } from "@/Composables/useSelectOptions";


const props = defineProps({
    massOrders: {
        type: Object,
        required: true,
    },
    suppliers: {
        type: Array,
        required: true,
    },
    ordersCutoff: {
        type: Array,
        required: true,
    },
    currentDate: {
        type: String,
        required: true,
    },
    branches: {
        type: Object,
    },
    filters: {
        type: Object,
    },
    canViewCost: {
        type: Boolean,
        default: false,
    }
});

const { hasAccess } = useAuth();
const { options: branchesOptions } = useSelectOptions(props.branches);

const canEditOrder = (order) => {
    // 1. Initial checks for status and permissions
    const supplierCode = order.supplier?.supplier_code;
    const allowedStatuses = supplierCode === 'DROPS' 
        ? ['pending', 'approved', 'committed'] 
        : ['pending', 'approved'];
    
    const initialCheck = allowedStatuses.includes(order.order_status) && hasAccess('edit mass orders');
    if (!initialCheck) {
        return false;
    }

    // 2. Find cutoff rules for the supplier
    if (!supplierCode) {
        return true; // Failsafe
    }
    const cutoffRules = props.ordersCutoff.find(c => c.ordering_template === supplierCode);
    if (!cutoffRules) {
        return true; // Failsafe
    }

    // 3. Get order placed time as a UTC Date object
    if (!order.created_at) {
        return false;
    }
    const placedAtIsoString = order.created_at.replace(' ', 'T') + 'Z';
    const placedAtUTC = new Date(placedAtIsoString);

    // 4. Parse all available cutoffs for the supplier and sort them
    const cutoffs = [];
    if (cutoffRules.cutoff_1_day !== null && cutoffRules.cutoff_1_time) {
        const [h, m] = cutoffRules.cutoff_1_time.split(':').map(Number);
        cutoffs.push({ day: cutoffRules.cutoff_1_day, timeInMinutes: h * 60 + m });
    }
    if (cutoffRules.cutoff_2_day !== null && cutoffRules.cutoff_2_time) {
        const [h, m] = cutoffRules.cutoff_2_time.split(':').map(Number);
        cutoffs.push({ day: cutoffRules.cutoff_2_day, timeInMinutes: h * 60 + m });
    }

    if (cutoffs.length === 0) {
        return true; // No cutoffs defined
    }

    cutoffs.sort((a, b) => {
        if (a.day !== b.day) return a.day - b.day;
        return a.timeInMinutes - b.timeInMinutes;
    });

    // 5. Find the next cutoff rule relative to when the order was placed (in Manila time)
    const manilaOffsetHours = 8;
    const manilaOffset = manilaOffsetHours * 60 * 60 * 1000;
    const placedAtManila = new Date(placedAtUTC.getTime() + manilaOffset);
    const placedAtDay = placedAtManila.getUTCDay();
    const placedAtTimeInMinutes = placedAtManila.getUTCHours() * 60 + placedAtManila.getUTCMinutes();

    let nextCutoffRule = cutoffs.find(c => c.day > placedAtDay || (c.day === placedAtDay && c.timeInMinutes > placedAtTimeInMinutes));
    
    let daysToAdd;
    if (nextCutoffRule) {
        // Found a cutoff later in the same week
        daysToAdd = (nextCutoffRule.day - placedAtDay + 7) % 7;
    } else {
        // It's the first one of the next week
        nextCutoffRule = cutoffs[0];
        daysToAdd = (7 - placedAtDay) + nextCutoffRule.day;
    }

    // 6. Construct deadline in "Manila-as-UTC" time
    const deadlineManila = new Date(placedAtManila);
    deadlineManila.setUTCDate(deadlineManila.getUTCDate() + daysToAdd);
    
    const deadlineHourManila = Math.floor(nextCutoffRule.timeInMinutes / 60);
    const deadlineMinuteManila = nextCutoffRule.timeInMinutes % 60;
    deadlineManila.setUTCHours(deadlineHourManila, deadlineMinuteManila, 0, 0);

    // 7. Convert the Manila deadline to a true UTC deadline
    const finalDeadlineUTC = new Date(deadlineManila.getTime() - manilaOffset);

    // 8. Compare with the current time, conditionally based on environment
    let nowUTC;
    // Use server time in production, local time otherwise for testing flexibility
    if (import.meta.env.MODE === 'production') {
        if (!props.currentDate) {
            return false; // Failsafe if prop is missing in prod
        }
        const nowIsoString = props.currentDate.replace(' ', 'T') + 'Z';
        nowUTC = new Date(nowIsoString);
    } else {
        nowUTC = new Date();
    }
    
    return nowUTC < finalDeadlineUTC;
};

// --- Flash Notification Logic ---
const flash = computed(() => usePage().props.flash);
const flashMessageVisible = ref(false);

watch(flash, (newFlash) => {
    if (newFlash && newFlash.message) {
        flashMessageVisible.value = true;
        setTimeout(() => {
            flashMessageVisible.value = false;
        }, 30000); // 30 seconds
    }
}, { deep: true, immediate: true });

const notificationType = computed(() => {
    if (!flash.value?.message) return null;
    if (flash.value.success === false) return 'error';
    if (flash.value.skipped_stores?.length > 0) return 'warning';
    if (flash.value.success) return 'success';
    return 'warning'; // Fallback for messages without a clear success/error status
});
// --- End Flash Notification Logic ---

// Modal logic
const isModalVisible = ref(false);
const openCreateModal = () => {
    isModalVisible.value = true;
};

const resetModalState = () => {
    form.reset();
    uploadForm.reset();
    showUploadStep.value = false;
    isDatepickerDisabled.value = true;
    enabledDates.value = [];
};

watch(isModalVisible, (newValue) => {
    if (newValue === false) {
        setTimeout(() => resetModalState(), 200);
    }
});

const form = useForm({
    supplier_code: null,
    order_date: null,
});

const uploadForm = useForm({
    mass_order_file: null,
    supplier_code: null,
    order_date: null,
});

const showUploadStep = ref(false);

const submitUpload = () => {
    if (!form.supplier_code || !form.order_date) {
        alert('Please select a supplier and a delivery date first.');
        return;
    }
    uploadForm.supplier_code = form.supplier_code;
    uploadForm.order_date = form.order_date;

    uploadForm.post(route('mass-orders.upload'), {
        onSuccess: () => {
            isModalVisible.value = false;
        },
    });
};


const selectedDayInfo = computed(() => {
    if (!form.order_date) return '';
    const date = new Date(form.order_date + 'T00:00:00');
    const dayName = date.toLocaleDateString('en-US', { weekday: 'long' });
    const dayNumber = date.getDay();
    return `${dayName} = ${dayNumber}`;
});

const isDatepickerDisabled = ref(true);
const enabledDates = ref([]);

const showCalendar = ref(false);
const currentCalendarDate = ref(new Date(props.currentDate + 'T00:00:00'));

// --- Calendar Positioning & Flip Logic ---
const dateInputRef = ref(null);
const calendarPositionClass = ref('top-full mt-2');

watch(showCalendar, (isShown) => {
    const dialogContent = document.querySelector('.mass-order-dialog .p-dialog-content');
    if (dialogContent) {
        dialogContent.style.overflow = isShown ? 'visible' : 'auto';
    }

    if (isShown && dateInputRef.value) {
        const inputRect = dateInputRef.value.getBoundingClientRect();
        const calendarHeight = 400;
        const spaceBelow = window.innerHeight - inputRect.bottom;

        if (spaceBelow < calendarHeight && inputRect.top > calendarHeight) {
            calendarPositionClass.value = 'bottom-full mb-2';
        } else {
            calendarPositionClass.value = 'top-full mt-2';
        }
    }
});

const getCalendarDays = () => {
    const days = [];
    const dateRef = currentCalendarDate.value || new Date();
    const year = dateRef.getFullYear();
    const month = dateRef.getMonth();
    const firstDayOfMonth = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const enabledDatesSet = new Set(enabledDates.value);

    for (let i = 0; i < firstDayOfMonth; i++) days.push(null);
    for (let i = 1; i <= daysInMonth; i++) {
        const date = new Date(year, month, i);
        const dateString = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        const isDisabled = !enabledDatesSet.has(dateString);
        days.push({ day: i, date, isDisabled });
    }
    return days;
};
const goToPrevMonth = () => currentCalendarDate.value = new Date(currentCalendarDate.value.getFullYear(), currentCalendarDate.value.getMonth() - 1, 1);
const goToNextMonth = () => currentCalendarDate.value = new Date(currentCalendarDate.value.getFullYear(), currentCalendarDate.value.getMonth() + 1, 1);
const selectDate = (day) => {
    if (day && !day.isDisabled) {
        const d = day.date;
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const dayOfMonth = String(d.getDate()).padStart(2, '0');
        form.order_date = `${year}-${month}-${dayOfMonth}`;
        showCalendar.value = false;
    }
};

watch(() => form.supplier_code, async (newSupplierCode) => {
    form.order_date = null;
    enabledDates.value = [];
    if (newSupplierCode) {
        isDatepickerDisabled.value = true;
        try {
            const response = await axios.get(route('mass-orders.available-dates', { supplier_code: newSupplierCode }));
            enabledDates.value = response.data;
            isDatepickerDisabled.value = false;
        } catch (error) {
            console.error('Error fetching available dates:', error);
            isDatepickerDisabled.value = true;
        }
    } else {
        isDatepickerDisabled.value = true;
    }
});

watch(() => form.order_date, (newDate) => {
    showUploadStep.value = !!newDate;
});


// Table and filter logic
let filterQuery = ref((usePage().props.filters.filterQuery || "all").toString());
let from = ref(usePage().props.filters.from);
let to = ref(usePage().props.filters.to);
let branchId = ref(usePage().props.filters.branchId);
let search = ref(usePage().props.filters.search);

const performFilter = throttle(() => {
    router.get(
        route("mass-orders.index"),
        {
            search: search.value,
            filterQuery: filterQuery.value,
            from: from.value,
            to: to.value,
            branchId: branchId.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    );
}, 500);

watch([from, to, branchId, search, filterQuery], performFilter);

const changeFilter = (currentFilter) => {
    filterQuery.value = currentFilter;
};

const statusBadgeColor = (status) => {
    switch (status?.toUpperCase()) {
        case "RECEIVED": return "bg-green-500 text-white";
        case "APPROVED": return "bg-teal-500 text-white";
        case "INCOMPLETE": return "bg-orange-500 text-white";
        case "PENDING": return "bg-yellow-500 text-white";
        case "COMMITTED": return "bg-blue-500 text-white";
        case "PARTIAL_COMMITTED": return "bg-indigo-500 text-white";
        case "REJECTED": return "bg-red-500 text-white";
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

const showOrderDetails = (id) => router.get(route('mass-orders.show', id));
const editOrderDetails = (id) => router.get(route('mass-orders.edit', id));

const getSupplierDisplayName = (supplier, variant) => {
    if (!supplier?.name) return 'N/A';
    return supplier.name === 'DROPSHIPPING' && variant === 'mass regular' ? 'FRUITS AND VEGETABLES' : supplier.name;
};

const filteredSuppliers = computed(() => {
    return props.suppliers.map(supplier => {
        if (supplier.value === 'DROPS') {
            return {
                ...supplier,
                label: 'FRUITS AND VEGETABLES'
            };
        }
        return supplier;
    });
});

const downloadFileName = computed(() => {
    if (!form.supplier_code || !form.order_date) {
        return 'MassOrderTemplate';
    }
    const date = new Date(form.order_date + 'T00:00:00');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const supplierName = form.supplier_code === 'DROPS' ? 'FRUITS AND VEGETABLES' : form.supplier_code;
    return `${supplierName}_${month}-${day}`;
});

</script>

<template>
    <Head title="Mass Orders" />

    <Layout heading="Mass Orders" :hasButton="true" buttonName="Create New Mass Order" :handleClick="openCreateModal">
        
        <!-- Flash Notification Area -->
        <div v-if="flash.message && flashMessageVisible" class="mb-4 p-4 rounded-md" :class="{
            'bg-green-100 text-green-800': notificationType === 'success',
            'bg-yellow-100 text-yellow-800': notificationType === 'warning',
            'bg-red-100 text-red-800': notificationType === 'error',
        }">
            <p class="font-semibold">{{ flash.message }}</p>
            <ul v-if="flash.skipped_stores && flash.skipped_stores.length" class="mt-2 list-disc list-inside text-sm">
                <li v-for="skipped in flash.skipped_stores" :key="skipped.brand_code">
                    <strong>{{ skipped.brand_code }}:</strong> {{ skipped.reason }}
                </li>
            </ul>
        </div>

        <Dialog v-model:visible="isModalVisible" modal header="Create New Mass Order" :style="{ width: '65rem', height: '90vh' }" class="mass-order-dialog">
            <div class="w-full p-4 sm:p-6 lg:p-8">
                
                <!-- Step 1: Select Order Details -->
                <div class="bg-white p-6 rounded-xl shadow-lg mb-8 border border-gray-200">
                    <div class="flex items-center mb-4">
                        <span class="flex items-center justify-center size-8 rounded-full bg-blue-600 text-white font-bold text-lg mr-4">1</span>
                        <h2 class="text-xl font-semibold text-gray-800">Select Order Details</h2>
                    </div>
                    <div class="space-y-6">
                        <!-- Supplier -->
                        <div>
                            <label for="supplier" class="block text-sm font-medium text-gray-700 mb-1">Ordering Template</label>
                            <Select
                                v-model="form.supplier_code"
                                filter
                                :options="filteredSuppliers"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Select an Ordering Template"
                                class="w-full"
                            />
                        </div>

                        <!-- Delivery Date -->
                        <div class="relative" ref="dateInputRef">
                            <label for="order_date" class="block text-sm font-medium text-gray-700 mb-1">Delivery Date</label>
                            <div class="relative">
                                <input id="order_date" type="text" readonly :value="form.order_date" @click="showCalendar = !showCalendar" :disabled="isDatepickerDisabled" class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm transition-colors placeholder:text-gray-500 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-gray-400 disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer" placeholder="Select a date" />
                                <CalendarIcon class="absolute right-3 top-1/2 -translate-y-1/2 size-4 text-gray-500 pointer-events-none" />
                            </div>
                            <div v-if="form.order_date" class="mt-2 text-sm text-gray-500">
                                Selected Day: <span class="font-semibold">{{ selectedDayInfo }}</span>
                            </div>
                            <!-- Calendar Popup -->
                            <div v-show="showCalendar" :class="['absolute z-50 bg-white border border-gray-200 rounded-lg shadow-lg p-4 w-full min-w-[300px]', calendarPositionClass]">
                                <div class="flex justify-between items-center mb-4">
                                    <button type="button" @click.stop="goToPrevMonth()" class="p-2 rounded-full hover:bg-gray-100">&lt;</button>
                                    <h2 class="text-lg font-semibold">{{ (currentCalendarDate || new Date()).toLocaleString('default', { month: 'long', year: 'numeric' }) }}</h2>
                                    <button type="button" @click.stop="goToNextMonth()" class="p-2 rounded-full hover:bg-gray-100">&gt;</button>
                                </div>
                                <div class="grid grid-cols-7 gap-1 text-center text-xs font-medium text-gray-500 mb-2"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
                                <div class="grid grid-cols-7 gap-2">
                                    <template v-for="(day, d_idx) in getCalendarDays()" :key="d_idx">
                                        <div class="text-center py-1.5 rounded-full text-sm" :class="[ !day ? '' : (day.isDisabled ? 'text-gray-300 line-through cursor-not-allowed' : (form.order_date && day.date.toDateString() === new Date(form.order_date + 'T00:00:00').toDateString() ? 'bg-blue-600 text-white font-bold shadow-md' : 'bg-gray-100 text-gray-800 font-semibold cursor-pointer hover:bg-blue-100')) ]" @click="selectDate(day)">{{ day ? day.day : '' }}</div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Steps 2 & 3 -->
                <div v-if="form.order_date && form.supplier_code" class="space-y-8 transition-opacity duration-500">
                    <!-- Step 2: Download -->
                    <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                        <div class="flex items-center mb-4">
                            <span class="flex items-center justify-center size-8 rounded-full bg-blue-600 text-white font-bold text-lg mr-4">2</span>
                            <h2 class="text-xl font-semibold text-gray-800">Download Template</h2>
                        </div>
                        <p class="text-gray-600 mb-5">Download the Excel template for the selected supplier. This file is pre-filled with the correct items and store columns for your order.</p>
                        <a :href="route('mass-orders.download-template', { supplier_code: form.supplier_code, order_date: form.order_date, filename: downloadFileName })" 
                           class="inline-flex items-center justify-center w-full px-4 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all transform hover:scale-105">
                            <Download class="mr-2 size-5" />
                            Download Order Template
                        </a>
                    </div>

                    <!-- Step 3: Upload -->
                    <div v-if="showUploadStep" class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                        <div class="flex items-center mb-4">
                            <span class="flex items-center justify-center size-8 rounded-full bg-blue-600 text-white font-bold text-lg mr-4">3</span>
                            <h2 class="text-xl font-semibold text-gray-800">Upload Completed File</h2>
                        </div>
                        <form @submit.prevent="submitUpload" class="mt-4 space-y-4">
                            <div>
                                <label for="mass_order_file" class="block text-sm font-medium text-gray-700">Excel File</label>
                                <input type="file" @input="uploadForm.mass_order_file = $event.target.files[0]" id="mass_order_file" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200"/>
                                <p v-if="uploadForm.errors.mass_order_file" class="mt-2 text-sm text-red-600">{{ uploadForm.errors.mass_order_file }}</p>
                            </div>

                            <div class="flex justify-end">
                                <Button type="submit" :disabled="!uploadForm.mass_order_file || uploadForm.processing">
                                    <Upload class="mr-2 size-5" />
                                    Upload and Process
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Dialog>

        <FilterTab>
            <FilterTabButton label="All" filter="all" :currentFilter="filterQuery" @click="changeFilter('all')" />
            <FilterTabButton label="Pending" filter="pending" :currentFilter="filterQuery" @click="changeFilter('pending')" />
            <FilterTabButton label="Approved" filter="approved" :currentFilter="filterQuery" @click="changeFilter('approved')" />
            <FilterTabButton label="Commited" filter="committed" :currentFilter="filterQuery" @click="changeFilter('committed')" />
            <FilterTabButton label="Partial Committed" filter="partial_committed" :currentFilter="filterQuery" @click="changeFilter('partial_committed')" />
            <FilterTabButton label="Received" filter="received" :currentFilter="filterQuery" @click="changeFilter('received')" />
            <FilterTabButton label="Rejected" filter="rejected" :currentFilter="filterQuery" @click="changeFilter('rejected')" />
        </FilterTab>

        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input v-model="search" id="search" type="text" placeholder="Search for order or SO number" class="pl-10 sm:max-w-full max-w-64" />
                </SearchBar>
                <DivFlexCenter class="gap-5">
                    <Popover>
                        <PopoverTrigger> <Filter /> </PopoverTrigger>
                        <PopoverContent>
                            <div class="flex justify-end">
                                <Button @click="() => { from = null; to = null; branchId = null; search = null; }" variant="link" class="text-end text-red-500 text-xs">
                                    Reset Filter
                                </Button>
                            </div>
                            <label class="text-xs">From</label>
                            <Input type="date" v-model="from" />
                            <label class="text-xs">To</label>
                            <Input type="date" v-model="to" />
                            <label class="text-xs">Store</label>
                            <SelectShad v-model="branchId">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a store" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectLabel>Stores</SelectLabel>
                                        <SelectItem v-for="(value, key) in branches" :value="key">
                                            {{ value }}
                                        </SelectItem>
                                    </SelectGroup>
                                </SelectContent>
                            </SelectShad>
                        </PopoverContent>
                    </Popover>
                </DivFlexCenter>
            </TableHeader>

            <div class="hidden md:block">
                <Table>
                    <TableHead>
                        <TH>Id</TH>
                        <TH>Supplier</TH>
                        <TH>Store</TH>
                        <TH>Order #</TH>
                        <TH>SO Number</TH>
                        <TH>Delivery Date</TH>
                        <TH>Order Placed Date</TH>
                        <TH>Order Status</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-if="!massOrders.data || massOrders.data.length === 0">
                            <td colspan="9" class="text-center py-4">No orders found.</td>
                        </tr>
                        <tr v-for="order in massOrders.data" :key="order.id">
                            <TD>{{ order.id }}</TD>
                            <TD>{{ getSupplierDisplayName(order.supplier, order.variant) }}</TD>
                            <TD>{{ order.store_branch?.name ?? "N/A" }}</TD>
                            <TD>{{ order.order_number }}</TD>
                            <TD>{{ order.delivery_receipts && order.delivery_receipts.length > 0 ? order.delivery_receipts[0].sap_so_number : "N/A" }}</TD>
                            <TD>{{ formatDisplayDate(order.order_date) }}</TD>
                            <TD>{{ formatDisplayDateTime(order.created_at) }}</TD>
                            <TD>
                                <Badge :class="statusBadgeColor(order.order_status)" class="font-bold">{{ order.order_status ? order.order_status.toUpperCase() : 'N/A' }}</Badge>
                            </TD>
                            <TD>
                                <DivFlexCenter class="gap-3">
                                    <button v-if="hasAccess('show mass orders')" @click="showOrderDetails(order.order_number)">
                                        <Eye class="size-5" />
                                    </button>
                                    <button v-if="canEditOrder(order)" class="text-blue-500" @click="editOrderDetails(order.order_number)">
                                        <Pencil class="size-5" />
                                    </button>
                                </DivFlexCenter>
                            </TD>
                        </tr>
                    </TableBody>
                </Table>
            </div>

            <MobileTableContainer class="md:hidden">
                <MobileTableRow v-for="order in massOrders.data" :key="order.id">
                    <MobileTableHeading :title="order.order_number">
                        <button v-if="hasAccess('show mass orders')" @click="showOrderDetails(order.order_number)">
                            <Eye class="size-5" />
                        </button>
                        <button v-if="canEditOrder(order)" class="text-blue-500" @click="editOrderDetails(order.order_number)">
                            <Pencil class="size-5" />
                        </button>
                    </MobileTableHeading>
                    <LabelXS>SO Number: {{ order.delivery_receipts && order.delivery_receipts.length > 0 ? order.delivery_receipts[0].sap_so_number : "N/A" }}</LabelXS>
                    <LabelXS>Status: <span :class="statusBadgeColor(order.order_status)" class="font-semibold p-1 rounded text-white">{{ order.order_status ? order.order_status.toUpperCase() : 'N/A' }}</span></LabelXS>
                    <LabelXS>Store: {{ order.store_branch?.name ?? "N/A" }}</LabelXS>
                    <LabelXS>Supplier: {{ getSupplierDisplayName(order.supplier, order.variant) }}</LabelXS>
                    <LabelXS>Delivery Date: {{ formatDisplayDate(order.order_date) }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>

            <Pagination :data="massOrders" />
        </TableContainer>
    </Layout>
</template>
