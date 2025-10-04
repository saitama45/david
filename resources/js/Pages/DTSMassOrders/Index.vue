<script setup>
import { router, Head } from "@inertiajs/vue3";
import { ref, watch } from 'vue';
import { Eye, Pencil, Filter, Calendar as CalendarIcon } from 'lucide-vue-next';
import { throttle } from "lodash";
import { useAuth } from "@/Composables/useAuth";
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';

const props = defineProps({
    dtsOrders: {
        type: Object,
        default: () => ({ data: [] })
    },
    branches: {
        type: Object,
        default: () => ({})
    },
    filters: {
        type: Object,
        default: () => ({})
    },
    variants: {
        type: Array,
        default: () => []
    }
});

const { hasAccess } = useAuth();

// Table and filter logic
let filterQuery = ref(props.filters?.filterQuery || "all");
let from = ref(props.filters?.from || null);
let to = ref(props.filters?.to || null);
let branchId = ref(props.filters?.branchId || null);
let search = ref(props.filters?.search || null);

const performFilter = throttle(() => {
    router.get(
        route("dts-mass-orders.index"),
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
        case "COMMITED": return "bg-blue-500 text-white";
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

const showOrderDetails = (id) => router.get(route('dts-mass-orders.show', id));
const editOrderDetails = (id) => router.get(route('dts-mass-orders.edit', id));

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

const confirmVariantSelection = () => {
    if (!selectedVariant.value || !dateFrom.value || !dateTo.value) {
        return;
    }
    showVariantModal.value = false;
    router.get(route('dts-mass-orders.create'), {
        variant: selectedVariant.value,
        date_from: dateFrom.value,
        date_to: dateTo.value
    });
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
        const isDisabled = enabledDates.value.length > 0 && !enabledDates.value.includes(dateString);
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
            <FilterTabButton label="All" filter="all" :currentFilter="filterQuery" @click="changeFilter('all')" />
            <FilterTabButton label="Pending" filter="pending" :currentFilter="filterQuery" @click="changeFilter('pending')" />
            <FilterTabButton label="Approved" filter="approved" :currentFilter="filterQuery" @click="changeFilter('approved')" />
            <FilterTabButton label="Commited" filter="committed" :currentFilter="filterQuery" @click="changeFilter('committed')" />
            <FilterTabButton label="Received" filter="received" :currentFilter="filterQuery" @click="changeFilter('received')" />
            <FilterTabButton label="Rejected" filter="rejected" :currentFilter="filterQuery" @click="changeFilter('rejected')" />
        </FilterTab>

        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input v-model="search" id="search" type="text" placeholder="Search for order number" class="pl-10 sm:max-w-full max-w-64" />
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
                        <TH>Delivery Date</TH>
                        <TH>Order Placed Date</TH>
                        <TH>Order Status</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-if="!dtsOrders.data || dtsOrders.data.length === 0">
                            <td colspan="8" class="text-center py-4">No orders found.</td>
                        </tr>
                        <tr v-for="order in dtsOrders.data" :key="order.id">
                            <TD>{{ order.id }}</TD>
                            <TD>{{ order.supplier?.name ?? "N/A" }}</TD>
                            <TD>{{ order.store_branch?.name ?? "N/A" }}</TD>
                            <TD>{{ order.order_number }}</TD>
                            <TD>{{ formatDisplayDate(order.order_date) }}</TD>
                            <TD>{{ formatDisplayDateTime(order.created_at) }}</TD>
                            <TD>
                                <Badge :class="statusBadgeColor(order.order_status)" class="font-bold">{{ order.order_status ? order.order_status.toUpperCase() : 'N/A' }}</Badge>
                            </TD>
                            <TD>
                                <DivFlexCenter class="gap-3">
                                    <button v-if="hasAccess('view dts mass orders')" @click="showOrderDetails(order.order_number)">
                                        <Eye class="size-5" />
                                    </button>
                                    <button v-if="hasAccess('edit dts mass orders')" class="text-blue-500" @click="editOrderDetails(order.order_number)">
                                        <Pencil class="size-5" />
                                    </button>
                                </DivFlexCenter>
                            </TD>
                        </tr>
                    </TableBody>
                </Table>
            </div>

            <MobileTableContainer class="md:hidden">
                <MobileTableRow v-for="order in dtsOrders.data" :key="order.id">
                    <MobileTableHeading :title="order.order_number">
                        <button v-if="hasAccess('view dts mass orders')" @click="showOrderDetails(order.order_number)">
                            <Eye class="size-5" />
                        </button>
                        <button v-if="hasAccess('edit dts mass orders')" class="text-blue-500" @click="editOrderDetails(order.order_number)">
                            <Pencil class="size-5" />
                        </button>
                    </MobileTableHeading>
                    <LabelXS>Status: <span :class="statusBadgeColor(order.order_status)" class="font-semibold p-1 rounded text-white">{{ order.order_status ? order.order_status.toUpperCase() : 'N/A' }}</span></LabelXS>
                    <LabelXS>Store: {{ order.store_branch?.name ?? "N/A" }}</LabelXS>
                    <LabelXS>Supplier: {{ order.supplier?.name ?? "N/A" }}</LabelXS>
                    <LabelXS>Delivery Date: {{ formatDisplayDate(order.order_date) }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>

            <Pagination :data="dtsOrders" />
        </TableContainer>

        <!-- Variant Selection Modal -->
        <Dialog v-model:visible="showVariantModal" modal header="Create DTS Mass Order" :style="{ width: '55rem', height: 'auto' }" :contentStyle="{ maxHeight: '85vh', overflowY: 'auto', padding: '1.5rem' }">
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
