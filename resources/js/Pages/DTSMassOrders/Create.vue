<script setup>
import { Head, router, useForm } from "@inertiajs/vue3";
import { ref, computed, watch } from "vue";
import { useToast } from "@/Composables/useToast";
import { useConfirm } from "primevue/useconfirm";

const props = defineProps({
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
    }
});

const { toast } = useToast();
const confirm = useConfirm();

const goBack = () => {
    router.get(route('dts-mass-orders.index'));
};

const handlePlaceOrders = () => {
    // Check if there are any orders
    let hasOrders = false;

    if (props.variant === 'FRUITS AND VEGETABLES') {
        // For FRUITS AND VEGETABLES: orders[itemId][storeId][date]
        for (const itemId in orders.value) {
            for (const storeId in orders.value[itemId]) {
                for (const dateKey in orders.value[itemId][storeId]) {
                    if (orders.value[itemId][storeId][dateKey] && parseFloat(orders.value[itemId][storeId][dateKey]) > 0) {
                        hasOrders = true;
                        break;
                    }
                }
                if (hasOrders) break;
            }
            if (hasOrders) break;
        }
    } else {
        // For ICE CREAM/SALMON: orders[date][store]
        for (const dateKey in orders.value) {
            for (const storeId in orders.value[dateKey]) {
                if (orders.value[dateKey][storeId] && parseFloat(orders.value[dateKey][storeId]) > 0) {
                    hasOrders = true;
                    break;
                }
            }
            if (hasOrders) break;
        }
    }

    if (!hasOrders) {
        toast.add({
            severity: 'warn',
            summary: 'No Orders',
            detail: 'Please enter at least one order quantity before placing orders.',
            life: 3000
        });
        return;
    }

    confirm.require({
        message: 'Are you sure you want to place these orders? This action cannot be undone.',
        header: 'Confirm Order Placement',
        icon: 'pi pi-exclamation-triangle',
        accept: () => {
            submitOrders();
        },
        reject: () => {
            // Do nothing
        },
        acceptClass: 'p-button-success',
        rejectClass: 'p-button-danger'
    });
};

const form = useForm({
    variant: props.variant,
    orders: {},
    sap_item: props.sap_item,
    supplier_items: props.supplier_items || []
});

const submitOrders = () => {
    // Prepare the orders data
    if (props.variant === 'FRUITS AND VEGETABLES') {
        // Transform orders from [itemId][storeId][date] to [itemId][date][storeId]
        const transformedOrders = {};
        Object.keys(orders.value).forEach(itemId => {
            transformedOrders[itemId] = {};
            Object.keys(orders.value[itemId]).forEach(storeId => {
                Object.keys(orders.value[itemId][storeId]).forEach(date => {
                    if (!transformedOrders[itemId][date]) {
                        transformedOrders[itemId][date] = {};
                    }
                    transformedOrders[itemId][date][storeId] = orders.value[itemId][storeId][date];
                });
            });
        });

        form.orders = transformedOrders;
        form.supplier_items = props.supplier_items;
        form.sap_item = null;

        // Debug log
        console.log('Submitting FRUITS AND VEGETABLES orders:', {
            orders: transformedOrders,
            supplier_items: props.supplier_items,
            variant: props.variant
        });
    } else {
        // For ICE CREAM and SALMON
        form.orders = orders.value;
        form.sap_item = props.sap_item;
    }
    form.variant = props.variant;

    // Submit via Inertia
    form.post(route('dts-mass-orders.store'), {
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: 'Orders Placed',
                detail: 'Your mass orders have been successfully placed.',
                life: 3000
            });
        },
        onError: (errors) => {
            console.error('Error placing orders:', errors);
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: errors.error || JSON.stringify(errors) || 'Failed to place orders. Please try again.',
                life: 5000
            });
        }
    });
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

// Initialize orders object
// For FRUITS AND VEGETABLES: { itemId: { date: { storeId: quantity } } }
// For ICE CREAM/SALMON: { date: { storeId: quantity } }
const orders = ref({});

watch(() => [props.variant, props.supplier_items, props.dates, props.stores], () => {
    if (props.variant === 'FRUITS AND VEGETABLES') {
        const newOrders = {};
        props.supplier_items.forEach(item => {
            newOrders[item.id] = {};
            props.stores.forEach(store => {
                newOrders[item.id][store.id] = {};
                props.dates.forEach(dateObj => {
                    newOrders[item.id][store.id][dateObj.date] = '';
                });
            });
        });
        orders.value = newOrders;
    } else {
        const newOrders = {};
        props.dates.forEach(dateObj => {
            newOrders[dateObj.date] = {};
            props.stores.forEach(store => {
                newOrders[dateObj.date][store.id] = '';
            });
        });
        orders.value = newOrders;
    }
}, { immediate: true });

// Calculate row totals
const getRowTotal = (date) => {
    let total = 0;
    props.stores.forEach(store => {
        const value = parseFloat(orders.value[date]?.[store.id] || 0);
        total += isNaN(value) ? 0 : value;
    });
    return total;
};

// Calculate column totals
const getColumnTotal = (storeId) => {
    let total = 0;
    props.dates.forEach(dateObj => {
        const value = parseFloat(orders.value[dateObj.date]?.[storeId] || 0);
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

// Get dates that a store has delivery schedule for
const getDatesForStore = (store) => {
    return props.dates;
};

// Get total column count for store headers (each store shows its delivery dates)
const getTotalDateColumns = computed(() => {
    let total = 0;
    props.stores.forEach(store => {
        total += getDatesForStore(store).length;
    });
    return total;
});

// Get maximum number of stores for any single day (for colspan calculation)
const maxStoresPerDay = computed(() => {
    let max = 0;
    props.dates.forEach(dateObj => {
        const storeCount = getStoresForDate(dateObj).length;
        if (storeCount > max) max = storeCount;
    });
    return max || 1; // At least 1 to avoid 0
});

// Validate quantity input for ICE CREAM variant
const validateQuantity = (dateKey, storeId, value) => {
    // Only validate for ICE CREAM variant
    if (props.variant === 'ICE CREAM') {
        const qty = parseFloat(value);

        // Check if value is entered and less than 5
        if (value !== '' && value !== null && !isNaN(qty)) {
            if (qty > 0 && qty < 5) {
                // Show toast message
                toast.add({
                    severity: 'warn',
                    summary: 'Invalid Quantity',
                    detail: 'ICE CREAM orders must be at least 5 gallons (GAL 3.8)',
                    life: 4000
                });

                // Reset the value
                orders.value[dateKey][storeId] = '';
                return false;
            }
        }
    }
    return true;
};

// Handle Enter key to move to next input
const handleEnterKey = (event) => {
    const inputs = Array.from(document.querySelectorAll('input[type="number"]'));
    const currentIndex = inputs.indexOf(event.target);

    if (currentIndex > -1 && currentIndex < inputs.length - 1) {
        event.preventDefault();
        inputs[currentIndex + 1].focus();
        inputs[currentIndex + 1].select();
    }
};

// Handle click on disabled input to show tooltip
const handleDisabledInputClick = (store, dateObj, event) => {
    if (!hasDeliverySchedule(store, dateObj)) {
        toast.add({
            severity: 'warn',
            summary: 'No Delivery Schedule',
            detail: `${store.name} does not have a delivery schedule for ${dateObj.day_of_week}.`,
            life: 4000
        });
    }
};

// Functions for FRUITS AND VEGETABLES layout
const getItemTotalOrder = (itemId) => {
    let total = 0;
    if (!orders.value[itemId]) return 0;

    Object.keys(orders.value[itemId]).forEach(storeId => {
        Object.keys(orders.value[itemId][storeId]).forEach(date => {
            const qty = parseFloat(orders.value[itemId][storeId][date] || 0);
            total += isNaN(qty) ? 0 : qty;
        });
    });
    return total;
};

const getItemBuffer = () => {
    return 10; // Fixed 10%
};

const getItemTotalPO = (itemId) => {
    const totalOrder = getItemTotalOrder(itemId);
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
    <Head title="Create DTS Mass Order" />

    <Layout :heading="`Create DTS Mass Order`">
        <TableContainer>
            <TableHeader>
                <Button @click="goBack" variant="outline" class="text-black border-black hover:bg-gray-100">
                    Back
                </Button>
            </TableHeader>

            <div class="bg-white border rounded-md shadow-sm p-6">
                <div class="space-y-4">
                    <div class="text-center">
                        <p class="text-lg font-medium text-blue-600">Variant: {{ props.variant || 'Not Selected' }}</p>
                        <p class="text-md font-medium text-gray-700 mt-1">
                            Date Range: {{ formatDisplayDate(props.date_from) }} - {{ formatDisplayDate(props.date_to) }}
                        </p>
                    </div>

                    <!-- FRUITS AND VEGETABLES Layout -->
                    <div v-if="props.variant === 'FRUITS AND VEGETABLES'" class="mt-6 overflow-x-auto">
                        <table class="min-w-full border-collapse border border-gray-300 text-sm frozen-pane-table">
                            <thead>
                                <!-- First Header Row: Fixed columns + Store Names grouped -->
                                <tr class="bg-gray-100">
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle frozen frozen-1" style="min-width: 100px;">ITEM CODE</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle frozen frozen-2" style="min-width: 200px;">ITEM NAME</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle frozen frozen-3" style="min-width: 80px;">UOM</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle frozen frozen-4" style="min-width: 80px;">PRICE</th>

                                    <!-- Store Name headers - each store spans its delivery dates -->
                                    <template v-for="store in props.stores" :key="`store-${store.id}`">
                                        <th
                                            :colspan="getDatesForStore(store).length"
                                            class="border border-gray-300 px-2 py-2 font-semibold text-center bg-blue-50"
                                            style="min-width: 120px;"
                                        >
                                            <div class="text-xs font-bold">{{ store.name }}</div>
                                            <div v-if="store.brand_code" class="text-xs text-gray-600 mt-1 font-bold">{{ store.brand_code }}</div>
                                            <div v-if="store.complete_address" class="text-xs text-gray-500 mt-1">{{ store.complete_address }}</div>
                                        </th>
                                    </template>

                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center bg-yellow-100 align-middle">TOTAL ORDER</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center bg-yellow-100 align-middle">BUFFER</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center bg-yellow-100 align-middle">TOTAL PO</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center bg-green-100 align-middle">TOTAL PRICE</th>
                                </tr>

                                <!-- Second Header Row: Day and Date for each store -->
                                <tr class="bg-gray-200">
                                    <template v-for="store in props.stores" :key="`dates-${store.id}`">
                                        <th
                                            v-for="dateObj in getDatesForStore(store)"
                                            :key="`date-${store.id}-${dateObj.date}`"
                                            class="border border-gray-300 px-2 py-2 font-semibold text-center"
                                        >
                                            <div class="text-xs">{{ dateObj.day_of_week }}</div>
                                            <div class="text-xs">{{ dateObj.display.split('- ')[1] }}</div>
                                        </th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Row for each supplier item -->
                                <tr v-for="item in props.supplier_items" :key="item.id" class="hover:bg-gray-50">
                                    <td class="border border-gray-300 px-3 py-2 frozen frozen-1">{{ item.item_code }}</td>
                                    <td class="border border-gray-300 px-3 py-2 frozen frozen-2">{{ item.item_name }}</td>
                                    <td class="border border-gray-300 px-3 py-2 text-center frozen frozen-3">{{ item.uom }}</td>
                                    <td class="border border-gray-300 px-3 py-2 text-right frozen frozen-4">{{ item.price.toFixed(2) }}</td>

                                    <!-- Input cells grouped by store, then dates for that store -->
                                    <template v-for="store in props.stores" :key="`body-${store.id}`">
                                        <td
                                            v-for="dateObj in getDatesForStore(store)"
                                            :key="`${item.id}-${store.id}-${dateObj.date}`"
                                            :class="['border border-gray-300 px-1 py-1', !hasDeliverySchedule(store, dateObj) ? 'bg-gray-100' : '']"
                                        >
                                            <input
                                                v-model="orders[item.id][store.id][dateObj.date]"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                :disabled="!hasDeliverySchedule(store, dateObj)"
                                                :class="[
                                                    'w-full px-2 py-1 border rounded text-center',
                                                    hasDeliverySchedule(store, dateObj)
                                                        ? 'border-gray-300 focus:ring-1 focus:ring-blue-500'
                                                        : 'border-gray-200 bg-gray-100 cursor-not-allowed text-gray-400'
                                                ]"
                                                :title="hasDeliverySchedule(store, dateObj) ? '' : 'No delivery schedule for this store on this day'"
                                                @keydown.enter="handleEnterKey"
                                                @click="handleDisabledInputClick(store, dateObj, $event)"
                                            />
                                        </td>
                                    </template>

                                    <td class="border border-gray-300 px-3 py-2 text-center font-semibold bg-yellow-50">
                                        {{ getItemTotalOrder(item.id).toFixed(2) }}
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
                                </tr>

                                <!-- Grand Total Row -->
                                <tr class="bg-gray-700 text-white font-bold">
                                    <td colspan="4" class="border border-gray-300 px-3 py-2 text-right frozen frozen-1">TOTAL PRICE</td>
                                    <td :colspan="getTotalDateColumns + 3" class="border border-gray-300 px-3 py-2"></td>
                                    <td class="border border-gray-300 px-3 py-2 text-right">{{ getGrandTotalPrice.toFixed(2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Excel-like Table (ICE CREAM & SALMON) -->
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
                                        {{ props.sap_item?.item_code || '' }}
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2">
                                        {{ props.sap_item?.item_description || '' }}
                                    </td>
                                    <td class="border border-gray-300 px-3 py-2 text-center font-semibold">
                                        <span v-if="props.sap_item">{{ props.sap_item.alt_uom }}</span>
                                    </td>
                                </tr>
                            </thead>

                            <!-- Body: Dates as rows with dynamic store columns -->
                            <tbody>
                                <template v-for="dateObj in props.dates" :key="dateObj.date">
                                    <!-- Date Header Row -->
                                    <tr class="bg-gray-200">
                                        <td class="border border-gray-300 px-3 py-2 font-bold" colspan="3">
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
                                    </tr>

                                    <!-- Each store that has delivery on this day -->
                                    <tr v-for="store in getStoresForDate(dateObj)" :key="`${dateObj.date}-${store.id}`" class="hover:bg-gray-50">
                                        <td class="border border-gray-300 px-3 py-2" colspan="2">
                                            <div>
                                                <div class="font-medium">{{ store.name }}</div>
                                                <div v-if="store.brand_code" class="text-xs text-gray-600 mt-1">{{ store.brand_code }}</div>
                                                <div v-if="store.complete_address" class="text-xs text-gray-500 mt-1">{{ store.complete_address }}</div>
                                            </div>
                                        </td>
                                        <td class="border border-gray-300 px-2 py-1">
                                            <input
                                                v-model="orders[dateObj.date][store.id]"
                                                type="number"
                                                step="0.01"
                                                :min="props.variant === 'ICE CREAM' ? '5' : '0'"
                                                class="w-full px-2 py-1 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 text-center"
                                                :placeholder="props.variant === 'ICE CREAM' ? 'Min: 5' : '0'"
                                                @blur="validateQuantity(dateObj.date, store.id, orders[dateObj.date][store.id])"
                                                @keydown.enter="handleEnterKey"
                                            />
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
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex justify-end gap-3 mt-6">
                        <button
                            @click="goBack"
                            class="px-4 py-2 bg-red-600 text-white font-medium rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500"
                        >
                            Cancel
                        </button>
                        <button
                            @click="handlePlaceOrders"
                            class="px-4 py-2 bg-green-600 text-white font-medium rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
                        >
                            Place Orders
                        </button>
                    </div>
                </div>
            </div>
        </TableContainer>
    </Layout>
</template>

<style>
.frozen-pane-table .frozen {
    position: -webkit-sticky; /* for Safari */
    position: sticky;
    z-index: 1;
}

/* We need to set background colors for sticky cells to prevent content from showing through */
.frozen-pane-table thead .frozen {
    background-color: #f3f4f6; /* Corresponds to bg-gray-100 */
}

.frozen-pane-table tbody tr:hover .frozen {
    /* To handle hover state on rows */
    background-color: #f9fafb; /* Corresponds to hover:bg-gray-50 */
}

.frozen-pane-table tbody .frozen {
    /* Default for body rows */
    background-color: #ffffff;
}

.frozen-pane-table tbody tr.bg-gray-700 .frozen {
    background-color: #374151; /* for total row */
}


.frozen-pane-table .frozen-1 {
    left: 0;
}
.frozen-pane-table .frozen-2 {
    left: 100px; /* Based on min-width of the first column */
}
.frozen-pane-table .frozen-3 {
    left: 300px; /* 100 + 200 */
}
.frozen-pane-table .frozen-4 {
    left: 380px; /* 300 + 80 */
}
</style>
