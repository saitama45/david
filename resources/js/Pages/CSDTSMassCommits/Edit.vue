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
    router.get(route('cs-dts-mass-commits.index'));
};

const handleCommitOrders = () => {
    // Check if there are any orders
    let hasOrders = false;

    if (props.variant === 'FRUITS AND VEGETABLES') {
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
            detail: 'Please enter at least one order quantity before committing.',
            life: 3000
        });
        return;
    }

    confirm.require({
        message: 'Are you sure you want to commit these orders? This will finalize the quantities and update the status.',
        header: 'Confirm Order Commit',
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
    form.put(route('cs-dts-mass-commits.update', props.batch_number), {
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: 'Orders Committed',
                detail: 'Your mass orders have been successfully committed.',
                life: 3000
            });
        },
        onError: (errors) => {
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: errors.error || 'Failed to commit orders. Please try again.',
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

const orders = ref({});

if (props.variant === 'FRUITS AND VEGETABLES') {
    props.supplier_items.forEach(item => {
        orders.value[item.id] = {};
        props.dates.forEach(dateObj => {
            orders.value[item.id][dateObj.date] = {};
            props.stores.forEach(store => {
                const existingQty = props.existing_orders[item.id]?.[dateObj.date]?.[store.id] || '';
                orders.value[item.id][dateObj.date][store.id] = existingQty;
            });
        });
    });
} else {
    props.dates.forEach(dateObj => {
        orders.value[dateObj.date] = {};
        props.stores.forEach(store => {
            const existingQty = props.existing_orders[dateObj.date]?.[store.id] || '';
            orders.value[dateObj.date][store.id] = existingQty;
        });
    });
}

const getRowTotal = (date) => {
    let total = 0;
    props.stores.forEach(store => {
        const value = parseFloat(orders.value[date]?.[store.id] || 0);
        total += isNaN(value) ? 0 : value;
    });
    return total;
};

const grandTotal = computed(() => {
    let total = 0;
    props.dates.forEach(dateObj => {
        total += getRowTotal(dateObj.date);
    });
    return total;
});

const hasDeliverySchedule = (store, dateObj) => {
    if (!store.delivery_schedule_ids || !dateObj.delivery_schedule_id) {
        return false;
    }
    return store.delivery_schedule_ids.includes(dateObj.delivery_schedule_id);
};

const getStoresForDate = (dateObj) => {
    return props.stores.filter(store => hasDeliverySchedule(store, dateObj));
};

const getDatesForStore = (store) => {
    return props.dates.filter(dateObj => hasDeliverySchedule(store, dateObj));
};

const getTotalDateColumns = computed(() => {
    let total = 0;
    props.stores.forEach(store => {
        total += getDatesForStore(store).length;
    });
    return total;
});

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

const handleEnterKey = (event) => {
    const inputs = Array.from(document.querySelectorAll('input[type="number"]'));
    const currentIndex = inputs.indexOf(event.target);

    if (currentIndex > -1 && currentIndex < inputs.length - 1) {
        event.preventDefault();
        inputs[currentIndex + 1].focus();
        inputs[currentIndex + 1].select();
    }
};

const validateQuantity = (dateKey, storeId, value) => {
    if (props.variant === 'ICE CREAM') {
        const qty = parseFloat(value);

        if (value !== '' && value !== null && !isNaN(qty)) {
            if (qty > 0 && qty < 5) {
                toast.add({
                    severity: 'warn',
                    summary: 'Invalid Quantity',
                    detail: 'ICE CREAM orders must be at least 5 gallons (GAL 3.8)',
                    life: 4000
                });
                orders.value[dateKey][storeId] = '';
                return false;
            }
        }
    }
    return true;
};

</script>

<template>
    <Head title="Commit DTS Mass Order" />

    <Layout :heading="`Commit DTS Mass Order: ${batch_number}`">
        <TableContainer>
            <TableHeader>
                <Button @click="goBack" variant="outline" class="text-black border-black hover:bg-gray-100">
                    Back
                </Button>
            </TableHeader>

            <div class="bg-white border rounded-md shadow-sm p-6">
                <div class="space-y-4">
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
                        <table class="min-w-full border-collapse border border-gray-300 text-sm">
                            <thead>
                                <tr class="bg-gray-100">
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle" style="min-width: 100px;">ITEM CODE</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle" style="min-width: 200px;">ITEM NAME</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle" style="min-width: 80px;">UOM</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle" style="min-width: 80px;">PRICE</th>
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
                                <tr v-for="item in supplier_items" :key="item.id" class="hover:bg-gray-50">
                                    <td class="border border-gray-300 px-3 py-2">{{ item.item_code }}</td>
                                    <td class="border border-gray-300 px-3 py-2">{{ item.item_name }}</td>
                                    <td class="border border-gray-300 px-3 py-2 text-center">{{ item.uom }}</td>
                                    <td class="border border-gray-300 px-3 py-2 text-right">{{ item.price.toFixed(2) }}</td>
                                    <template v-for="store in stores" :key="`body-${store.id}`">
                                        <td
                                            v-for="dateObj in getDatesForStore(store)"
                                            :key="`${item.id}-${store.id}-${dateObj.date}`"
                                            class="border border-gray-300 px-1 py-1"
                                        >
                                            <input
                                                v-model="orders[item.id][dateObj.date][store.id]"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="w-full px-2 py-1 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 text-center"
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
                                <tr class="bg-gray-700 text-white font-bold">
                                    <td colspan="4" class="border border-gray-300 px-3 py-2 text-right">TOTAL PRICE</td>
                                    <td :colspan="getTotalDateColumns + 3" class="border border-gray-300 px-3 py-2"></td>
                                    <td class="border border-gray-300 px-3 py-2 text-right">{{ getGrandTotalPrice.toFixed(2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- ICE CREAM & SALMON Layout -->
                    <div v-else class="mt-6 overflow-x-auto">
                        <table class="min-w-full border-collapse border border-gray-300 text-sm">
                            <thead>
                                <tr class="bg-gray-100">
                                    <th class="border border-gray-300 px-3 py-2 font-semibold text-left" style="min-width: 120px;">ITEM CODE</th>
                                    <th class="border border-gray-300 px-3 py-2 font-semibold text-left" style="min-width: 200px;">ITEM DESCRIPTION</th>
                                    <th class="border border-gray-300 px-3 py-2 font-semibold text-center" style="min-width: 100px;">UOM</th>
                                </tr>
                                <tr class="bg-white">
                                    <td class="border border-gray-300 px-3 py-2">{{ sap_item?.item_code || '' }}</td>
                                    <td class="border border-gray-300 px-3 py-2">{{ sap_item?.item_description || '' }}</td>
                                    <td class="border border-gray-300 px-3 py-2 text-center font-semibold">
                                        <span v-if="sap_item">{{ sap_item.alt_uom }}</span>
                                    </td>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="dateObj in dates" :key="dateObj.date">
                                    <tr class="bg-gray-200">
                                        <td class="border border-gray-300 px-3 py-2 font-bold text-base" colspan="3">{{ dateObj.display }}</td>
                                    </tr>
                                    <tr class="bg-gray-50">
                                        <td class="border border-gray-300 px-3 py-2 font-semibold" colspan="2">Store Name</td>
                                        <td class="border border-gray-300 px-3 py-2 font-semibold text-center">Quantity</td>
                                    </tr>
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
                                    <tr class="bg-blue-50 font-semibold">
                                        <td class="border border-gray-300 px-3 py-2" colspan="2">TOTAL</td>
                                        <td class="border border-gray-300 px-3 py-2 text-center">{{ getRowTotal(dateObj.date) }}</td>
                                    </tr>
                                </template>
                                <tr class="bg-gray-700 text-white font-bold">
                                    <td class="border border-gray-300 px-3 py-2" colspan="2">GRAND TOTAL</td>
                                    <td class="border border-gray-300 px-3 py-2 text-center">{{ grandTotal }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex justify-end gap-3 mt-6">
                        <button
                            @click="goBack"
                            class="px-4 py-2 bg-red-600 text-white font-medium rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500"
                        >
                            Cancel
                        </button>
                        <button
                            @click="handleCommitOrders"
                            class="px-4 py-2 bg-green-600 text-white font-medium rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
                        >
                            COMMIT ALL
                        </button>
                    </div>
                </div>
            </div>
        </TableContainer>
    </Layout>
</template>