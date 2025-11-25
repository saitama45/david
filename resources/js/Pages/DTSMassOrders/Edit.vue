<script setup>
import { Head, router, useForm } from "@inertiajs/vue3";
import { ref, computed } from "vue";
import { useToast } from "@/Composables/useToast";
import { useConfirm } from "primevue/useconfirm";

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
    existing_orders: {
        type: Object,
        default: () => ({})
    },
    status: {
        type: String,
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

const handleUpdateOrders = () => {
    // Check if there are any orders
    let hasOrders = false;

    if (props.variant === 'FRUITS AND VEGETABLES') {
        // For FRUITS AND VEGETABLES: orders[itemId][date][store]
        for (const itemId in orders.value) {
            for (const dateKey in orders.value[itemId]) {
                for (const storeId in orders.value[itemId][dateKey]) {
                    if (orders.value[itemId][dateKey][storeId] && parseFloat(orders.value[itemId][dateKey][storeId]) > 0) {
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
            detail: 'Please enter at least one order quantity before updating orders.',
            life: 3000
        });
        return;
    }

    confirm.require({
        message: 'Are you sure you want to update these orders? This will replace all existing orders in this batch.',
        header: 'Confirm Order Update',
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
        form.orders = orders.value;
        form.supplier_items = props.supplier_items;
        form.sap_item = null;
    } else {
        form.orders = orders.value;
        form.sap_item = props.sap_item;
    }
    form.variant = props.variant;

    // Submit via Inertia
    form.put(route('dts-mass-orders.update', props.batch_number), {
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: 'Orders Updated',
                detail: 'Your mass orders have been successfully updated.',
                life: 3000
            });
        },
        onError: (errors) => {
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: errors.error || 'Failed to update orders. Please try again.',
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

// Helper function to check delivery schedule
const hasDeliverySchedule = (store, dateObj) => {
    if (!store.delivery_schedule_ids || !dateObj.delivery_schedule_id) {
        return false;
    }
    return store.delivery_schedule_ids.includes(dateObj.delivery_schedule_id);
};

// Initialize orders with existing values or empty
if (props.variant === 'FRUITS AND VEGETABLES') {
    // For each supplier item, create nested structure
    // For Edit view, show all dates for all stores (orders already exist)
    props.supplier_items.forEach(item => {
        orders.value[item.id] = {};
        props.dates.forEach(dateObj => {
            orders.value[item.id][dateObj.date] = {};
            props.stores.forEach(store => {
                // Pre-populate with existing order quantity if available
                const existingQty = props.existing_orders[item.id]?.[dateObj.date]?.[store.id] || '';
                orders.value[item.id][dateObj.date][store.id] = existingQty;
            });
        });
    });
} else{
    // Original logic for ICE CREAM and SALMON
    props.dates.forEach(dateObj => {
        orders.value[dateObj.date] = {};
        props.stores.forEach(store => {
            // Pre-populate with existing order quantity if available
            const existingQty = props.existing_orders[dateObj.date]?.[store.id] || '';
            orders.value[dateObj.date][store.id] = existingQty;
        });
    });
}

// Calculate row totals
const getRowTotal = (date) => {
    let total = 0;
    props.stores.forEach(store => {
        const value = parseFloat(orders.value[date]?.[store.id] || 0);
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
// Get stores that have delivery schedule for a specific date
const getStoresForDate = (dateObj) => {
    return props.stores.filter(store => hasDeliverySchedule(store, dateObj));
};

// Get dates that a store has delivery schedule for
// For Edit view, show ALL dates for all stores to maintain alignment
const getDatesForStore = (store) => {
    // Show all dates for all stores to maintain column alignment
    return props.dates;
};

// Check if a cell should be editable (has existing order data)
const isEditableCell = (itemId, storeId, date) => {
    return props.existing_orders[itemId]?.[date]?.[storeId] !== undefined;
};

// Get total column count for store headers (each store shows its delivery dates)
const getTotalDateColumns = computed(() => {
    let total = 0;
    props.stores.forEach(store => {
        total += getDatesForStore(store).length;
    });
    return total;
});

// Functions for FRUITS AND VEGETABLES layout
const getItemTotalOrder = (itemId) => {
    let total = 0;
    if (!orders.value[itemId]) return 0;

    Object.keys(orders.value[itemId]).forEach(date => {
        Object.keys(orders.value[itemId][date]).forEach(storeId => {
            const qty = parseFloat(orders.value[itemId][date][storeId] || 0);
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

</script>

<template>
    <Head title="Edit DTS Mass Order" />

    <Layout :heading="`Edit DTS Mass Order`">
        <TableContainer>
            <TableHeader>
                <Button @click="goBack" variant="outline" class="text-black border-black hover:bg-gray-100">
                    Back
                </Button>
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
                                <label class="text-xs font-semibold text-gray-600 uppercase">Date Range</label>
                                <p class="text-sm font-medium text-gray-700">
                                    {{ formatDisplayDate(date_from) }} - {{ formatDisplayDate(date_to) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- FRUITS AND VEGETABLES Layout -->
                    <div v-if="variant === 'FRUITS AND VEGETABLES'" class="mt-6 overflow-x-auto">
                        <table class="min-w-full border-collapse border border-gray-300 text-sm frozen-pane-table">
                            <thead>
                                <!-- First Header Row: Fixed columns + Store Names grouped -->
                                <tr class="bg-gray-100">
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle frozen frozen-1" style="min-width: 100px;">ITEM CODE</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle frozen frozen-2" style="min-width: 200px;">ITEM NAME</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle frozen frozen-3" style="min-width: 80px;">UOM</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle frozen frozen-4" style="min-width: 80px;">PRICE</th>

                                    <!-- Store Name headers - each store spans its delivery dates -->
                                    <template v-for="store in stores" :key="`store-${store.id}`">
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
                                    <template v-for="store in stores" :key="`dates-${store.id}`">
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
                                <tr v-for="item in supplier_items" :key="item.id" class="hover:bg-gray-50">
                                    <td class="border border-gray-300 px-3 py-2 frozen frozen-1">{{ item.item_code }}</td>
                                    <td class="border border-gray-300 px-3 py-2 frozen frozen-2">{{ item.item_name }}</td>
                                    <td class="border border-gray-300 px-3 py-2 text-center frozen frozen-3">{{ item.uom }}</td>
                                    <td class="border border-gray-300 px-3 py-2 text-right frozen frozen-4">{{ item.price.toFixed(2) }}</td>

                                    <!-- Input cells grouped by store, then dates for that store -->
                                    <template v-for="store in stores" :key="`body-${store.id}`">
                                        <td
                                            v-for="dateObj in getDatesForStore(store)"
                                            :key="`${item.id}-${store.id}-${dateObj.date}`"
                                            :class="['border border-gray-300 px-1 py-1', !isEditableCell(item.id, store.id, dateObj.date) ? 'bg-gray-100' : '']"
                                        >
                                            <input
                                                v-model="orders[item.id][dateObj.date][store.id]"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                :readonly="!isEditableCell(item.id, store.id, dateObj.date)"
                                                :class="[
                                                    'w-full px-2 py-1 border rounded text-center',
                                                    isEditableCell(item.id, store.id, dateObj.date)
                                                        ? 'border-gray-300 focus:ring-1 focus:ring-blue-500'
                                                        : 'border-gray-200 bg-gray-100 cursor-not-allowed text-gray-400'
                                                ]"
                                                @keydown.enter="handleEnterKey"
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
                                        <td class="border border-gray-300 px-3 py-2 font-bold text-base" colspan="3">
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
                                                <div class="font-bold">{{ store.name }}</div>
                                                <div v-if="store.brand_code" class="text-xs text-gray-600 mt-1 font-bold">{{ store.brand_code }}</div>
                                                <div v-if="store.complete_address" class="text-xs text-gray-500 mt-1">{{ store.complete_address }}</div>
                                            </div>
                                        </td>
                                        <td class="border border-gray-300 px-2 py-1">
                                            <input
                                                v-model="orders[dateObj.date][store.id]"
                                                type="number"
                                                step="0.01"
                                                :min="variant === 'ICE CREAM' ? '5' : '0'"
                                                class="w-full px-2 py-1 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 text-center"
                                                :placeholder="variant === 'ICE CREAM' ? 'Min: 5' : '0'"
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
                            @click="handleUpdateOrders"
                            class="px-4 py-2 bg-green-600 text-white font-medium rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
                        >
                            Update Orders
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
