<script setup>
import { Head, router, useForm } from "@inertiajs/vue3";
import { ref, computed, watch } from "vue";
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

// Check if a cell should be editable (has existing order data)
const isEditableCell = (itemId, storeId, date) => {
    return props.existing_orders[itemId]?.[date]?.[storeId] !== undefined;
};

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

// Get stores that have delivery schedule for a specific date
const getStoresForDate = (dateObj) => {
    return props.stores.filter(store => hasDeliverySchedule(store, dateObj));
};

// Get dates that a store has delivery schedule for
const getDatesForStore = (store) => {
    // Show all dates for all stores to maintain column alignment in Edit view
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

// --- Calculation Functions ---
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

// --- EXCEL-LIKE FEATURES (Navigation & Drag-to-Fill) ---

// 1. Flatten Columns for F&V Grid
// Maps colIndex -> { store, dateObj }
const flatColumns = computed(() => {
    if (props.variant !== 'FRUITS AND VEGETABLES') return [];
    const cols = [];
    props.stores.forEach(store => {
        getDatesForStore(store).forEach(dateObj => {
            cols.push({ store, dateObj });
        });
    });
    return cols;
});

// 2. State
const isDragging = ref(false);
const dragStart = ref(null); // { r, c }
const dragEnd = ref(null);   // { r, c }
const activeCell = ref(null);

// 3. Helpers
const getInputEl = (r, c) => {
    return document.querySelector(`input[data-r="${r}"][data-c="${c}"]`);
};

const isSelected = (r, c) => {
    if (!dragStart.value || !dragEnd.value) return false;
    const minR = Math.min(dragStart.value.r, dragEnd.value.r);
    const maxR = Math.max(dragStart.value.r, dragEnd.value.r);
    const minC = Math.min(dragStart.value.c, dragEnd.value.c);
    const maxC = Math.max(dragStart.value.c, dragEnd.value.c);
    return r >= minR && r <= maxR && c >= minC && c <= maxC;
};

const isDragEndCell = (r, c) => {
    if (!dragEnd.value) return false;
    return dragEnd.value.r === r && dragEnd.value.c === c;
};

// 4. Focus Handling
const onFocus = (r, c) => {
    if (!isDragging.value) {
        dragStart.value = { r, c };
        dragEnd.value = { r, c };
        activeCell.value = { r, c };
    }
};

// 5. Arrow Key Navigation
const handleKeyDown = (r, c, event) => {
    let nextR = r;
    let nextC = c;
    const maxR = props.supplier_items.length - 1;
    const maxC = flatColumns.value.length - 1;

    switch (event.key) {
        case 'ArrowUp': nextR--; break;
        case 'ArrowDown': nextR++; break;
        case 'ArrowLeft': nextC--; break;
        case 'ArrowRight': nextC++; break;
        case 'Enter': 
            event.preventDefault(); 
            nextR++; 
            break;
        default: return; // Allow other keys
    }

    if (nextR >= 0 && nextR <= maxR && nextC >= 0 && nextC <= maxC) {
        event.preventDefault();
        const el = getInputEl(nextR, nextC);
        if (el) {
            el.focus();
            if (!el.disabled && !el.readOnly) el.select();
        }
    }
};

// 6. Drag Logic
const startDrag = (r, c, event) => {
    event.preventDefault(); 
    event.stopPropagation();
    isDragging.value = true;
    dragStart.value = { r, c };
    dragEnd.value = { r, c };
    document.addEventListener('mouseup', endDrag);
};

const onMouseEnter = (r, c) => {
    if (isDragging.value) {
        dragEnd.value = { r, c };
    }
};

const endDrag = () => {
    if (!isDragging.value) return;
    applyFill();
    isDragging.value = false;
    document.removeEventListener('mouseup', endDrag);
};

const applyFill = () => {
    if (!dragStart.value || !dragEnd.value) return;

    const startR = Math.min(dragStart.value.r, dragEnd.value.r);
    const endR = Math.max(dragStart.value.r, dragEnd.value.r);
    const startC = Math.min(dragStart.value.c, dragEnd.value.c);
    const endC = Math.max(dragStart.value.c, dragEnd.value.c);

    const sourceColDef = flatColumns.value[dragStart.value.c];
    const sourceItem = props.supplier_items[dragStart.value.r];
    
    if (!sourceColDef || !sourceItem) return;

    // ADAPTED ACCESS PATH: item -> date -> store
    const sourceVal = orders.value[sourceItem.id][sourceColDef.dateObj.date][sourceColDef.store.id];

    for (let r = startR; r <= endR; r++) {
        for (let c = startC; c <= endC; c++) {
            const targetColDef = flatColumns.value[c];
            const targetItem = props.supplier_items[r];
            const targetEl = getInputEl(r, c);

            // Check if target is valid, not disabled by logic, and not readonly
            if (targetColDef && targetItem && targetEl && 
                !targetEl.disabled && !targetEl.readOnly && 
                isEditableCell(targetItem.id, targetColDef.store.id, targetColDef.dateObj.date)) {
                
                orders.value[targetItem.id][targetColDef.dateObj.date][targetColDef.store.id] = sourceVal;
            }
        }
    }
};

// 7. Styles for Selection Overlay
const selectionStyle = computed(() => {
    if (!dragStart.value || !dragEnd.value) return { display: 'none' };

    const startR = Math.min(dragStart.value.r, dragEnd.value.r);
    const endR = Math.max(dragStart.value.r, dragEnd.value.r);
    const startC = Math.min(dragStart.value.c, dragEnd.value.c);
    const endC = Math.max(dragStart.value.c, dragEnd.value.c);

    const tlEl = getInputEl(startR, startC);
    const brEl = getInputEl(endR, endC);

    if (!tlEl || !brEl) return { display: 'none' };

    const container = tlEl.closest('.relative-container');
    if (!container) return { display: 'none' };

    const containerRect = container.getBoundingClientRect();
    const tlRect = tlEl.getBoundingClientRect();
    const brRect = brEl.getBoundingClientRect();

    return {
        top: (tlRect.top - containerRect.top) + 'px',
        left: (tlRect.left - containerRect.left) + 'px',
        width: (brRect.right - tlRect.left) + 'px',
        height: (brRect.bottom - tlRect.top) + 'px',
        position: 'absolute',
        pointerEvents: 'none',
        border: '2px solid #3b82f6',
        zIndex: 10
    };
});

// 8. Formula Evaluation
const evaluateCellFormula = (item, store, dateObj) => {
    if (!isEditableCell(item.id, store.id, dateObj.date)) return;

    let value = orders.value[item.id][dateObj.date][store.id];
    
    if (typeof value === 'string' && value.startsWith('=')) {
        try {
            let expression = value.substring(1);
            if (/^[0-9+\-*/().\s%]+$/.test(expression)) {
                const result = new Function('return ' + expression)();
                orders.value[item.id][dateObj.date][store.id] = result;
            } else {
                toast.add({ severity: 'error', summary: 'Invalid Formula', detail: 'Only numbers and basic math operators are allowed.', life: 3000 });
            }
        } catch (e) {
            toast.add({ severity: 'error', summary: 'Formula Error', detail: 'Could not evaluate expression.', life: 3000 });
        }
    }
};

// --- END EXCEL-LIKE FEATURES ---

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
                    <div v-if="variant === 'FRUITS AND VEGETABLES'" class="relative-container relative mt-6 overflow-x-auto overflow-y-auto max-h-[80vh]">
                        
                        <!-- SELECTION OVERLAY -->
                        <div :style="selectionStyle">
                            <!-- Fill Handle -->
                             <div 
                                v-if="isEditableCell(props.supplier_items[dragEnd?.r]?.id, flatColumns[dragEnd?.c]?.store?.id, flatColumns[dragEnd?.c]?.dateObj?.date)"
                                class="absolute -bottom-1 -right-1 w-3 h-3 bg-blue-500 border border-white cursor-crosshair z-20 pointer-events-auto"
                                @mousedown="startDrag(dragEnd.r, dragEnd.c, $event)"
                             ></div>
                        </div>

                        <table class="min-w-full border-collapse border border-gray-300 text-sm frozen-pane-table select-none">
                            <thead>
                                <tr class="bg-gray-100">
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle frozen frozen-1" style="min-width: 100px;">ITEM CODE</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle frozen frozen-2" style="min-width: 200px;">ITEM NAME</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle frozen frozen-3" style="min-width: 80px;">UOM</th>
                                    <th rowspan="2" class="border border-gray-300 px-3 py-2 font-semibold text-center align-middle frozen frozen-4" style="min-width: 80px;">PRICE</th>
                                    <template v-for="store in stores" :key="`store-${store.id}`">
                                        <th
                                            :colspan="getDatesForStore(store).length"
                                            class="border border-gray-300 px-2 py-2 font-semibold text-center bg-blue-50"
                                            style="min-width: 120px;"
                                        >
                                            <div class="h-[140px] flex flex-col justify-start items-center overflow-hidden">
                                                <div class="text-xs font-bold">{{ store.name }}</div>
                                                <div v-if="store.brand_code" class="text-xs text-gray-600 mt-1 font-bold">{{ store.brand_code }}</div>
                                                <div v-if="store.complete_address" class="text-xs text-gray-500 mt-1">{{ store.complete_address }}</div>
                                            </div>
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
                                            class="border border-gray-300 px-2 py-2 font-semibold text-center h-[40px] sticky-date-header"
                                            style="position: sticky; top: 157px; z-index: 15;"
                                        >
                                            <div class="text-xs">{{ dateObj.day_of_week }}</div>
                                            <div class="text-xs">{{ dateObj.display.split('- ')[1] }}</div>
                                        </th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(item, rIndex) in supplier_items" :key="item.id" class="hover:bg-gray-50">
                                    <td class="border border-gray-300 px-3 py-2 frozen frozen-1">{{ item.item_code }}</td>
                                    <td class="border border-gray-300 px-3 py-2 frozen frozen-2">{{ item.item_name }}</td>
                                    <td class="border border-gray-300 px-3 py-2 text-center frozen frozen-3">{{ item.uom }}</td>
                                    <td class="border border-gray-300 px-3 py-2 text-right frozen frozen-4">{{ item.price.toFixed(2) }}</td>
                                    <template v-for="store in stores" :key="`body-${store.id}`">
                                        <td
                                            v-for="(dateObj, dIndex) in getDatesForStore(store)"
                                            :key="`${item.id}-${store.id}-${dateObj.date}`"
                                            :class="['border border-gray-300 px-1 py-1', !isEditableCell(item.id, store.id, dateObj.date) ? 'bg-gray-100' : '']"
                                        >
                                            <div class="relative w-full h-full">
                                                <input
                                                    v-model="orders[item.id][dateObj.date][store.id]"
                                                    type="text"
                                                    :data-r="rIndex"
                                                    :data-c="flatColumns.findIndex(c => c.store.id === store.id && c.dateObj.date === dateObj.date)"
                                                    :readonly="!isEditableCell(item.id, store.id, dateObj.date)"
                                                    :class="[
                                                        'w-full px-2 py-1 border text-center outline-none',
                                                        isEditableCell(item.id, store.id, dateObj.date)
                                                            ? (isSelected(rIndex, flatColumns.findIndex(c => c.store.id === store.id && c.dateObj.date === dateObj.date)) ? 'bg-blue-50 border-blue-500 z-10' : 'border-gray-300')
                                                            : 'border-gray-200 bg-gray-100 cursor-not-allowed text-gray-400'
                                                    ]"
                                                    @keydown.enter="handleEnterKey"
                                                    @focus="onFocus(rIndex, flatColumns.findIndex(c => c.store.id === store.id && c.dateObj.date === dateObj.date))"
                                                    @keydown="handleKeyDown(rIndex, flatColumns.findIndex(c => c.store.id === store.id && c.dateObj.date === dateObj.date), $event)"
                                                    @mouseenter="onMouseEnter(rIndex, flatColumns.findIndex(c => c.store.id === store.id && c.dateObj.date === dateObj.date))"
                                                    @change="evaluateCellFormula(item, store, dateObj)"
                                                />
                                                <!-- Fill Handle -->
                                                <div 
                                                    v-if="isDragEndCell(rIndex, flatColumns.findIndex(c => c.store.id === store.id && c.dateObj.date === dateObj.date)) && isEditableCell(item.id, store.id, dateObj.date)"
                                                    class="absolute -bottom-1 -right-1 w-3 h-3 bg-blue-600 border border-white cursor-crosshair z-20 pointer-events-auto shadow-sm"
                                                    @mousedown="startDrag(rIndex, flatColumns.findIndex(c => c.store.id === store.id && c.dateObj.date === dateObj.date), $event)"
                                                ></div>
                                            </div>
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
                                    <td colspan="4" class="border border-gray-300 px-3 py-2 text-right frozen frozen-1">TOTAL PRICE</td>
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
                                                :disabled="isEditableCell && !isEditableCell(null, store.id, dateObj.date)"
                                                class="w-full px-2 py-1 border border-gray-300 rounded focus:ring-1 focus:ring-blue-500 text-center"
                                                :class="{ 'bg-gray-100 cursor-not-allowed opacity-60': isEditableCell && !isEditableCell(null, store.id, dateObj.date) }"
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
/* --- FROZEN PANE STYLES --- */

/* Base for all sticky elements */
.frozen-pane-table .frozen,
.frozen-pane-table thead th {
    position: -webkit-sticky;
    position: sticky;
}

/* HORIZONTAL FREEZE (COLUMNS) */
.frozen-pane-table .frozen-1 { left: 0; }
.frozen-pane-table .frozen-2 { left: 100px; }
.frozen-pane-table .frozen-3 { left: 300px; }
.frozen-pane-table .frozen-4 { left: 380px; }

/* VERTICAL FREEZE (HEADER) */
.frozen-pane-table thead tr:first-of-type th {
    top: 0;
    z-index: 20;
}
.frozen-pane-table th.sticky-date-header {
    top: 140px !important; /* Matched to fixed height of first row */
    z-index: 15;
    position: sticky !important;
}

/* Z-INDEX LAYERING */
.frozen-pane-table thead th.frozen { z-index: 50 !important; } /* Top-Left Intersection (Highest) */
.frozen-pane-table thead th { z-index: 20; } /* Standard Headers */
.frozen-pane-table th.sticky-date-header { z-index: 20 !important; } /* Date Row */
.frozen-pane-table tbody .frozen { z-index: 10; } /* Left Fixed Columns (Body) */
.frozen-pane-table tbody td { z-index: 1; } /* Standard Cells */

/* BACKGROUNDS for sticky elements to avoid transparency */
.frozen-pane-table thead th {
    background-color: #f3f4f6; /* Default: bg-gray-100 */
}
.frozen-pane-table thead tr:nth-of-type(2) th {
    background-color: #e5e7eb; /* Default: bg-gray-200 */
}
/* Override for specific header cells to maintain original color */
.frozen-pane-table thead th.bg-blue-50 { background-color: #eff6ff !important; }
.frozen-pane-table thead th.bg-yellow-100 { background-color: #fef9c3 !important; }
.frozen-pane-table thead th.bg-green-100 { background-color: #dcfce7 !important; }

/* Background for body's frozen columns */
.frozen-pane-table tbody .frozen {
    background-color: #ffffff;
}
.frozen-pane-table tbody tr:hover .frozen {
    background-color: #f9fafb; /* hover:bg-gray-50 */
}
.frozen-pane-table tbody tr.bg-gray-700 .frozen {
    background-color: #374151; /* for total row */
}
</style>