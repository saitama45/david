<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Select from 'primevue/select';
import Button from 'primevue/button';
import { ref, watch, onMounted, computed, reactive } from 'vue';
import axios from 'axios';
import { Calendar as CalendarIcon, Download, Upload } from 'lucide-vue-next';

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

const uploadForm = useForm({
    mass_order_file: null,
    supplier_code: null,
    order_date: null,
});

const uploadResult = reactive({
    success: false,
    message: ''
});

const submitUpload = () => {
    if (!form.supplier_code || !form.order_date) {
        alert('Please select a supplier and a delivery date first.');
        return;
    }
    uploadForm.supplier_code = form.supplier_code;
    uploadForm.order_date = form.order_date;

    uploadForm.post(route('mass-orders.upload'), {
        onSuccess: (page) => {
            uploadResult.success = page.props.flash.success;
            uploadResult.message = page.props.flash.message;
            uploadForm.reset('mass_order_file');
        },
        onError: (errors) => {
            uploadResult.success = false;
            uploadResult.message = 'An error occurred during upload. Please check the file and try again.';
        }
    });
};

const selectedDayInfo = computed(() => {
    if (!form.order_date) return '';
    const date = new Date(form.order_date + 'T00:00:00');
    const dayName = date.toLocaleDateString('en-US', { weekday: 'long' });
    const dayNumber = date.getDay();
    return `${dayName} = ${dayNumber}`;
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
        <div class="w-full max-w-2xl mx-auto p-4 sm:p-6 lg:p-8">
            
            <!-- Step 1: Select Order Details -->
            <div class="bg-white p-6 rounded-xl shadow-lg mb-8 border border-gray-200">
                <div class="flex items-center mb-4">
                    <span class="flex items-center justify-center size-8 rounded-full bg-blue-600 text-white font-bold text-lg mr-4">1</span>
                    <h2 class="text-xl font-semibold text-gray-800">Select Order Details</h2>
                </div>
                <div class="space-y-6">
                    <!-- Supplier -->
                    <div>
                        <label for="supplier" class="block text-sm font-medium text-gray-700 mb-1">Supplier</label>
                        <Select
                            v-model="form.supplier_code"
                            filter
                            :options="props.suppliers"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Select a Supplier"
                            class="w-full"
                        />
                    </div>

                    <!-- Delivery Date -->
                    <div class="relative">
                        <label for="order_date" class="block text-sm font-medium text-gray-700 mb-1">Delivery Date</label>
                        <div class="relative">
                            <input id="order_date" type="text" readonly :value="form.order_date" @click="showCalendar = !showCalendar" :disabled="isDatepickerDisabled" class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm transition-colors placeholder:text-gray-500 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-gray-400 disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer" placeholder="Select a date" />
                            <CalendarIcon class="absolute right-3 top-1/2 -translate-y-1/2 size-4 text-gray-500 pointer-events-none" />
                        </div>
                        <div v-if="form.order_date" class="mt-2 text-sm text-gray-500">
                            Selected Day: <span class="font-semibold">{{ selectedDayInfo }}</span>
                        </div>
                        <!-- Calendar Popup -->
                        <div v-show="showCalendar" class="absolute z-50 mt-2 bg-white border border-gray-200 rounded-lg shadow-lg p-4 w-full min-w-[300px]">
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
                    <a :href="route('mass-orders.download-template', { supplier_code: form.supplier_code, order_date: form.order_date })" 
                       class="inline-flex items-center justify-center w-full px-4 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all transform hover:scale-105">
                        <Download class="mr-2 size-5" />
                        Download Order Template
                    </a>
                </div>

                <!-- Step 3: Upload -->
                <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
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
                    
                    <div v-if="uploadResult.message" class="mt-4 p-4 rounded-md" :class="uploadResult.success ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'">
                        <p class="font-semibold">{{ uploadResult.message }}</p>
                        <ul v-if="uploadResult.skipped_stores && uploadResult.skipped_stores.length" class="mt-2 list-disc list-inside text-sm">
                            <li v-for="skipped in uploadResult.skipped_stores" :key="skipped.brand_code">
                                <strong>{{ skipped.brand_code }}:</strong> {{ skipped.reason }}
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </Layout>
</template>