<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Select from 'primevue/select';
import Button from 'primevue/button';
import { ref, watch, onMounted } from 'vue';
import axios from 'axios';
import { Calendar as CalendarIcon } from 'lucide-vue-next';

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
    }
});

const form = useForm({
    supplier_code: null,
    order_date: null,
});

const submit = () => {
    // Handle form submission
};

const isDatepickerDisabled = ref(true);
const enabledDates = ref([]);

// --- Calendar Logic ---
const showCalendar = ref(false);
const currentCalendarDate = ref(new Date(props.currentDate + 'T00:00:00'));

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
// --- End Calendar Logic ---


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

</script>

<template>
    <Head title="Mass Orders" />

    <Layout heading="Mass Orders">
        <div class="flex justify-center items-center py-12">
            <div class="w-full max-w-lg p-6 mx-auto bg-white rounded-lg shadow-md">
                <form @submit.prevent="submit">
                    <div class="space-y-6">
                        <div>
                            <label for="supplier" class="block text-sm font-medium text-gray-700">Supplier</label>
                            <Select
                                v-model="form.supplier_code"
                                filter
                                :options="props.suppliers"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Select a Supplier"
                                class="w-full mt-1"
                            />
                        </div>

                        <div class="relative">
                            <label for="order_date" class="block text-sm font-medium text-gray-700">Delivery Date</label>
                            <div class="relative">
                                <input id="order_date" type="text" readonly :value="form.order_date" @click="showCalendar = !showCalendar" :disabled="isDatepickerDisabled" class="flex h-9 w-full rounded-md border border-gray-300 bg-white px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-gray-500 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-gray-400 disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer" placeholder="Select date" />
                                <CalendarIcon class="absolute right-3 top-1/2 -translate-y-1/2 size-4 text-gray-500 pointer-events-none" />
                            </div>
                            <div v-show="showCalendar" class="absolute z-50 mt-2 bg-white border border-gray-200 rounded-lg shadow-lg p-4 w-full min-w-[280px]">
                                <div class="flex justify-between items-center mb-4">
                                    <button type="button" @click.stop="goToPrevMonth()" class="p-2 rounded-full hover:bg-gray-200">&lt;</button>
                                    <h2 class="text-lg font-semibold">{{ (currentCalendarDate || new Date()).toLocaleString('default', { month: 'long', year: 'numeric' }) }}</h2>
                                    <button type="button" @click.stop="goToNextMonth()" class="p-2 rounded-full hover:bg-gray-200">&gt;</button>
                                </div>
                                <div class="grid grid-cols-7 gap-1 text-center text-xs font-medium text-gray-500 mb-2"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
                                <div class="grid grid-cols-7 gap-1">
                                    <template v-for="(day, d_idx) in getCalendarDays()" :key="d_idx">
                                        <div class="text-center py-1.5 rounded-full text-sm" :class="[ !day ? '' : (day.isDisabled ? 'text-gray-400 line-through cursor-not-allowed' : (form.order_date && day.date.toDateString() === new Date(form.order_date + 'T00:00:00').toDateString() ? 'bg-blue-500 text-white font-bold' : 'bg-green-100 text-green-800 font-semibold cursor-pointer hover:bg-green-200')) ]" @click="selectDate(day)">{{ day ? day.day : '' }}</div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-8 flex justify-end">
                        <Button type="submit" label="Next" :disabled="!form.supplier_code || !form.order_date" />
                    </div>
                </form>

                
            </div>
        </div>
    </Layout>
</template>