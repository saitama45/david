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
    supplier_items: {
        type: Array,
        default: () => []
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
        const value = parseFloat(props.orders[date]?.[store.id]?.approved || 0);
        total += isNaN(value) ? 0 : value;
    });
    return total;
};

const getRowTotalCommitted = (date) => {
    if (props.status === 'approved') {
        return 0;
    }
    let total = 0;
    props.stores.forEach(store => {
        const value = parseFloat(props.orders[date]?.[store.id]?.committed || 0);
        total += isNaN(value) ? 0 : value;
    });
    return total;
};

const getRowTotalVariance = (date) => {
    return getRowTotalCommitted(date) - getRowTotal(date);
};

// Calculate grand total
const grandTotalApproved = computed(() => {
    let total = 0;
    props.dates.forEach(dateObj => {
        total += getRowTotal(dateObj.date);
    });
    return total;
});

const grandTotalCommitted = computed(() => {
    let total = 0;
    props.dates.forEach(dateObj => {
        total += getRowTotalCommitted(dateObj.date);
    });
    return total;
});

const grandTotalVariance = computed(() => {
    return grandTotalApproved.value - grandTotalCommitted.value;
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

// FRUITS AND VEGETABLES helper functions
// For Show view, show ALL dates for all stores to maintain alignment
const getDatesForStore = (store) => {
    // Show all dates for all stores to maintain column alignment
    return props.dates;
};

const getTotalDateColumns = computed(() => {
    return props.stores.reduce((total, store) => {
        return total + getDatesForStore(store).length;
    }, 0);
});

const getItemTotalApproved = (itemId) => {
    let total = 0;
    if (!props.orders[itemId]) return 0;
    Object.keys(props.orders[itemId]).forEach(date => {
        Object.keys(props.orders[itemId][date]).forEach(storeId => {
            const qty = parseFloat(props.orders[itemId][date][storeId]?.approved || 0);
            total += isNaN(qty) ? 0 : qty;
        });
    });
    return total;
};

const getItemTotalCommitted = (itemId) => {
    if (props.status === 'approved') return 0;
    let total = 0;
    if (!props.orders[itemId]) return 0;
    Object.keys(props.orders[itemId]).forEach(date => {
        Object.keys(props.orders[itemId][date]).forEach(storeId => {
            const qty = parseFloat(props.orders[itemId][date][storeId]?.committed || 0);
            total += isNaN(qty) ? 0 : qty;
        });
    });
    return total;
};

const getItemVariance = (itemId) => {
    return getItemTotalCommitted(itemId) - getItemTotalApproved(itemId);
};

const getItemBuffer = () => {
    return 10;
};

const getItemTotalPO = (itemId) => {
    const totalOrder = getItemTotalApproved(itemId);
    return totalOrder * 1.1; // Total Order * 1.1
};

const getItemTotalPrice = (itemId, price) => {
    const totalPO = getItemTotalPO(itemId);
    return totalPO * price; // Price * Total PO
};

const getGrandTotalPrice = computed(() => {
    let total = 0;
    props.supplier_items.forEach(item => {
        total += getItemTotalPrice(item.id, item.price);
    });
    return total;
});

</script>

<template>
    <Head title="View DTS Mass Order" />

    <Layout :heading="`View DTS Mass Order`">
        <TableContainer>
            <TableHeader>
                <div class="flex justify-between w-full">
                    <Button @click="goBack" variant="outline" class="text-black border-black hover:bg-gray-100">
                        Back
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

                    <!-- FRUITS AND VEGETABLES Layout -->
                    <div v-if="variant === 'FRUITS AND VEGETABLES'" class="mt-6 overflow-x-auto">
                        <table class="min-w-full border-collapse border border-gray-300 text-sm">
                            <thead>
                                <tr class="bg-gray-100">
                                    <th rowspan="3" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle" style="min-width: 100px;">ITEM CODE</th>
                                    <th rowspan="3" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle" style="min-width: 200px;">ITEM NAME</th>
                                    <th rowspan="3" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle" style="min-width: 80px;">UOM</th>
                                    <th rowspan="3" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle" style="min-width: 80px;">PRICE</th>

                                    <!-- Store Name headers -->
                                    <template v-for="store in stores" :key="`store-${store.id}`">
                                        <th
                                            :colspan="getDatesForStore(store).length * 2"
                                            class="border border-gray-300 px-2 py-2 font-semibold text-center bg-blue-50"
                                            style="min-width: 120px;"
                                        >
                                            <div class="text-xs font-bold">{{ store.name }}</div>
                                            <div v-if="store.brand_code" class="text-xs text-gray-600 mt-1 font-bold">{{ store.brand_code }}</div>
                                            <div v-if="store.complete_address" class="text-xs text-gray-500 mt-1">{{ store.complete_address }}</div>
                                        </th>
                                    </template>

                                    <th rowspan="3" class="border border-gray-300 px-3 py-2 font-semibold text-center bg-yellow-100 align-middle">TOTAL ORDER</th>
                                    <th rowspan="3" class="border border-gray-300 px-3 py-2 font-semibold text-center bg-yellow-100 align-middle">BUFFER</th>
                                    <th rowspan="3" class="border border-gray-300 px-3 py-2 font-semibold text-center bg-yellow-100 align-middle">TOTAL PO</th>
                                    <th rowspan="3" class="border border-gray-300 px-3 py-2 font-semibold text-center bg-green-100 align-middle">TOTAL PRICE</th>
                                    <th rowspan="3" class="border border-gray-300 px-3 py-2 font-semibold text-center bg-red-100 align-middle">Variance</th>
                                </tr>

                                <!-- Second Header Row: Day and Date for each store -->
                                <tr class="bg-gray-200">
                                    <template v-for="store in stores" :key="`dates-${store.id}`">
                                        <th
                                            v-for="dateObj in getDatesForStore(store)"
                                            :key="`date-${store.id}-${dateObj.date}`"
                                            :colspan="2"
                                            class="border border-gray-300 px-2 py-2 font-semibold text-center"
                                        >
                                            <div class="text-xs">{{ dateObj.day_of_week }}</div>
                                            <div class="text-xs">{{ dateObj.display.split('- ')[1] }}</div>
                                        </th>
                                    </template>
                                </tr>

                                <!-- Third Header Row: QTY and COMMITTED -->
                                <tr class="bg-gray-300">
                                    <template v-for="store in stores" :key="`sub-dates-${store.id}`">
                                        <template v-for="dateObj in getDatesForStore(store)" :key="`sub-date-${store.id}-${dateObj.date}`">
                                            <th class="border border-gray-300 px-2 py-1 font-semibold text-center text-xs">QTY</th>
                                            <th class="border border-gray-300 px-2 py-1 font-semibold text-center text-xs">COMMITTED</th>
                                        </template>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Row for each supplier item -->
                                <tr v-for="item in supplier_items" :key="item.id" class="hover:bg-gray-50">
                                    <td class="border border-gray-300 px-3 py-2">{{ item.item_code }}</td>
                                    <td class="border border-gray-300 px-3 py-2">{{ item.item_name }}</td>
                                    <td class="border border-gray-300 px-3 py-2 text-center">{{ item.uom }}</td>
                                    <td class="border border-gray-300 px-3 py-2 text-right">{{ item.price.toFixed(2) }}</td>

                                    <!-- Quantity cells grouped by store, then dates for that store -->
                                    <template v-for="store in stores" :key="`body-${store.id}`">
                                        <template v-for="dateObj in getDatesForStore(store)" :key="`${item.id}-${store.id}-${dateObj.date}`">
                                            <td class="border border-gray-300 px-3 py-2 text-center">
                                                <span class="font-semibold text-blue-700">
                                                    {{ orders[item.id]?.[dateObj.date]?.[store.id]?.approved || 0 }}
                                                </span>
                                            </td>
                                            <td class="border border-gray-300 px-3 py-2 text-center">
                                                <span class="font-semibold text-green-700">
                                                    {{ props.status === 'approved' ? 0 : (orders[item.id]?.[dateObj.date]?.[store.id]?.committed || 0) }}
                                                </span>
                                            </td>
                                        </template>
                                    </template>

                                    <td class="border border-gray-300 px-3 py-2 text-center font-semibold bg-yellow-50">
                                        {{ getItemTotalApproved(item.id).toFixed(2) }}
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-center font-semibold bg-yellow-50">
                                        {{ getItemBuffer() }}%
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-center font-semibold bg-yellow-50">
                                        {{ getItemTotalPO(item.id).toFixed(2) }}
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-right font-semibold bg-green-50">
                                        {{ getItemTotalPrice(item.id, item.price).toFixed(2) }}
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-right font-semibold bg-red-50">
                                        {{ getItemVariance(item.id).toFixed(2) }}
                                    </td>
                                </tr>

                                <!-- Grand Total Row -->
                                <tr class="bg-gray-700 text-white font-bold">
                                    <td colspan="4" class="border border-gray-300 px-3 py-2 text-right">TOTAL PRICE & VARIANCE</td>
                                    <td :colspan="getTotalDateColumns * 2 + 3" class="border border-gray-300 px-3 py-2"></td>
                                    <td class="border border-gray-300 px-3 py-2 text-right">{{ getGrandTotalPrice.toFixed(2) }}</td>
                                    <td class="border border-gray-300 px-3 py-2 text-right">{{ grandTotalVariance.toFixed(2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Order Details Table (ICE CREAM & SALMON) -->
                    <div v-else class="mt-6 overflow-x-auto">
                        <table class="min-w-full border-collapse border border-gray-300 text-sm">
                            <!-- Header Rows -->
                            <thead>
                                <!-- First Row: ITEM CODE + ITEM DESCRIPTION + UOM -->
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
                                        <td class="border border-gray-300 px-3 py-2 font-semibold">
                                            Store Name
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 font-semibold text-center">
                                            Quantity
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 font-semibold text-center">
                                            COMMITTED
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 font-semibold text-center">
                                            Variance (Ordered vs Committed)
                                        </td>
                                    </tr>

                                    <!-- Each store that has delivery on this day -->
                                    <tr v-for="store in getStoresForDate(dateObj)" :key="`${dateObj.date}-${store.id}`" class="hover:bg-gray-50">
                                        <td class="border border-gray-300 px-3 py-2" colspan="1">
                                            <div>
                                                <div class="font-bold">{{ store.name }}</div>
                                                <div v-if="store.brand_code" class="text-xs text-gray-600 mt-1 font-bold">{{ store.brand_code }}</div>
                                                <div v-if="store.complete_address" class="text-xs text-gray-500 mt-1">{{ store.complete_address }}</div>
                                            </div>
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 text-center">
                                            <span class="font-semibold text-blue-700">
                                                {{ orders[dateObj.date]?.[store.id]?.approved || 0 }}
                                            </span>
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 text-center">
                                            <span class="font-semibold text-green-700">
                                                {{ props.status === 'approved' ? 0 : (orders[dateObj.date]?.[store.id]?.committed || 0) }}
                                            </span>
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 text-center">
                                            <span class="font-semibold text-red-700">
                                                {{ (props.status === 'approved' ? 0 : (orders[dateObj.date]?.[store.id]?.committed || 0)) - (orders[dateObj.date]?.[store.id]?.approved || 0) }}
                                            </span>
                                        </td>
                                    </tr>

                                    <!-- Day Total Row -->
                                    <tr class="bg-blue-50 font-semibold">
                                        <td class="border border-gray-300 px-3 py-2">
                                            TOTAL
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 text-center">
                                            {{ getRowTotal(dateObj.date) }}
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 text-center">
                                            {{ getRowTotalCommitted(dateObj.date) }}
                                        </td>
                                        <td class="border border-gray-300 px-3 py-2 text-center">
                                            {{ getRowTotalVariance(dateObj.date) }}
                                        </td>
                                    </tr>
                                </template>

                                <!-- Grand Total Row -->
                                <tr class="bg-gray-700 text-white font-bold">
                                    <td class="border border-gray-300 px-3 py-2">
                                        GRAND TOTAL
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-center">
                                        {{ grandTotalApproved }}
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-center">
                                        {{ grandTotalCommitted }}
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-center">
                                        {{ grandTotalVariance }}
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
