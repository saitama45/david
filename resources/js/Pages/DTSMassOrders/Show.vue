<script setup>
import { Head, router } from "@inertiajs/vue3";
import { ref, computed } from "vue";

const props = defineProps({
    batch_number: {
        type: String,
        required: true
    },
    variant: {
        type: String,
        default: null
    },
    date_from: {
        type: String,
        default: null
    },
    date_to: {
        type: String,
        default: null
    },
    stores: {
        type: Array,
        default: () => []
    },
    dates: {
        type: Array,
        default: () => []
    },
    sap_item: {
        type: Object,
        default: null
    },
    orders: {
        type: Object,
        default: () => ({})
    },
    status: {
        type: String,
        default: null
    },
    created_at: {
        type: String,
        default: null
    },
    encoder: {
        type: Object,
        default: null
    }
});

const goBack = () => {
    router.get(route('dts-mass-orders.index'));
};

const formatDisplayDate = (dateString) => {
    if (!dateString) return 'Not Selected';
    try {
        const [year, month, day] = dateString.split('-');
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

// Calculate row totals
const getRowTotal = (date) => {
    let total = 0;
    props.stores.forEach(store => {
        const value = parseFloat(props.orders[date]?.[store.id] || 0);
        total += isNaN(value) ? 0 : value;
    });
    return total;
};

// Calculate grand total
const grandTotal = computed(() => {
    let total = 0;
    props.dates.forEach(dateObj => {
        total += getRowTotal(dateObj.date);
    });
    return total;
});

// Check if a store has delivery schedule for a specific date
const hasDeliverySchedule = (store, dateObj) => {
    if (!store.delivery_schedule_ids || !dateObj.delivery_schedule_id) {
        return false;
    }
    return store.delivery_schedule_ids.includes(dateObj.delivery_schedule_id);
};

// Get stores that have delivery schedule for a specific date
const getStoresForDate = (dateObj) => {
    return props.stores.filter(store => hasDeliverySchedule(store, dateObj));
};

const statusBadgeColor = (status) => {
    switch (status?.toUpperCase()) {
        case "APPROVED": return "bg-teal-500 text-white";
        case "PENDING": return "bg-yellow-500 text-white";
        case "COMPLETED": return "bg-green-500 text-white";
        case "CANCELLED": return "bg-red-500 text-white";
        default: return "bg-gray-500 text-white";
    }
};

const exportToExcel = () => {
    window.open(route('dts-mass-orders.export', props.batch_number), '_blank');
};

</script>

<template>
    <Head title="View DTS Mass Order" />

    <Layout :heading="`View DTS Mass Order`">
        <TableContainer>
            <TableHeader>
                <div class="flex gap-3">
                    <Button @click="goBack" variant="outline">
                        Back to DTS Mass Orders
                    </Button>
                    <Button @click="exportToExcel" class="bg-green-600 hover:bg-green-700 text-white">
                        Export to Excel
                    </Button>
                </div>
            </TableHeader>

            <div class="bg-white border rounded-md shadow-sm p-6">
                <div class="space-y-4">
                    <!-- Batch Info Header -->
                    <div class="bg-gradient-to-r from-blue-50 to-blue-100 border border-blue-200 rounded-lg p-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="text-xs font-semibold text-gray-600 uppercase">Batch Number</label>
                                <p class="text-lg font-bold text-blue-700">{{ batch_number }}</p>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-600 uppercase">Variant</label>
                                <p class="text-lg font-bold text-blue-600">{{ variant }}</p>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-600 uppercase block mb-2">Status</label>
                                <Badge :class="statusBadgeColor(status)" class="font-bold text-sm">
                                    {{ status ? status.toUpperCase() : 'N/A' }}
                                </Badge>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                            <div>
                                <label class="text-xs font-semibold text-gray-600 uppercase">Date Range</label>
                                <p class="text-sm font-medium text-gray-700">
                                    {{ formatDisplayDate(date_from) }} - {{ formatDisplayDate(date_to) }}
                                </p>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-600 uppercase">Created By</label>
                                <p class="text-sm font-medium text-gray-700">
                                    {{ encoder?.first_name }} {{ encoder?.last_name }}
                                </p>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-gray-600 uppercase">Created At</label>
                                <p class="text-sm font-medium text-gray-700">{{ formatDisplayDateTime(created_at) }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Order Details Table -->
                    <div class="mt-6 overflow-x-auto">
                        <table class="min-w-full border-collapse border border-gray-300 text-sm">
                            <!-- Header Rows -->
                            <thead>
                                <!-- First Row: ITEM CODE + ITEM DESCRIPTION + UOM + TOTAL -->
                                <tr class="bg-gray-100">
                                    <th class="border border-gray-300 px-3 py-2 font-semibold text-left" style="min-width: 120px;">
                                        ITEM CODE
                                    </th>
                                    <th class="border border-gray-300 px-3 py-2 font-semibold text-left" style="min-width: 200px;">
                                        ITEM DESCRIPTION
                                    </th>
                                    <th class="border border-gray-300 px-3 py-2 font-semibold text-center" style="min-width: 100px;">
                                        UOM
                                    </th>
                                    <th class="border border-gray-300 px-3 py-2 font-semibold text-center bg-red-100" style="min-width: 100px;">
                                        TOTAL
                                    </th>
                                </tr>

                                <!-- Second Row: Item Code value + Description value + UOM value -->
                                <tr class="bg-white">
                                    <td class="border border-gray-300 px-3 py-2">
                                        {{ sap_item?.item_code || '' }}
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2">
                                        {{ sap_item?.item_description || '' }}
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-center font-semibold">
                                        <span v-if="sap_item">{{ sap_item.alt_uom }}</span>
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-center font-semibold bg-red-100">
                                        <!-- Empty -->
                                    </td>
                                </tr>
                            </thead>

                            <!-- Body: Dates as rows with dynamic store columns -->
                            <tbody>
                                <template v-for="dateObj in dates" :key="dateObj.date">
                                    <!-- Date Header Row -->
                                    <tr class="bg-gray-200">
                                        <td class="border border-gray-300 px-3 py-2 font-bold text-base" colspan="4">
                                            {{ dateObj.display }}
                                        </td>
                                    </tr>

                                    <!-- Store Names Row for this date -->
                                    <tr class="bg-gray-50">
                                        <td class="border border-gray-300 px-3 py-2 font-semibold" colspan="2">
                                            Store Name
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 font-semibold text-center">
                                            Quantity
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 font-semibold text-center bg-red-50">
                                            Subtotal
                                        </td>
                                    </tr>

                                    <!-- Each store that has delivery on this day -->
                                    <tr v-for="store in getStoresForDate(dateObj)" :key="`${dateObj.date}-${store.id}`" class="hover:bg-gray-50">
                                        <td class="border border-gray-300 px-3 py-2" colspan="2">
                                            <div>
                                                <div class="font-bold">{{ store.name }}</div>
                                                <div v-if="store.brand_code" class="text-xs text-gray-600 mt-1 font-bold">{{ store.brand_code }}</div>
                                                <div v-if="store.complete_address" class="text-xs text-gray-500 mt-1">{{ store.complete_address }}</div>
                                            </div>
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 text-center">
                                            <span class="font-semibold text-blue-700">
                                                {{ orders[dateObj.date]?.[store.id] || 0 }}
                                            </span>
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 text-center bg-red-50">
                                            <span class="font-semibold">
                                                {{ parseFloat(orders[dateObj.date]?.[store.id] || 0) }}
                                            </span>
                                        </td>
                                    </tr>

                                    <!-- Day Total Row -->
                                    <tr class="bg-blue-50 font-semibold">
                                        <td class="border border-gray-300 px-3 py-2" colspan="2">
                                            TOTAL
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 text-center">
                                            {{ getRowTotal(dateObj.date) }}
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 text-center bg-red-100">
                                            {{ getRowTotal(dateObj.date) }}
                                        </td>
                                    </tr>
                                </template>

                                <!-- Grand Total Row -->
                                <tr class="bg-gray-700 text-white font-bold">
                                    <td class="border border-gray-300 px-3 py-2" colspan="2">
                                        GRAND TOTAL
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-center">
                                        {{ grandTotal }}
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-center bg-red-900">
                                        {{ grandTotal }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </TableContainer>
    </Layout>
</template>
