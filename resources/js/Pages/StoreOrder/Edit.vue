<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeMount } from 'vue';
import Select from "primevue/select";
import axios from 'axios';
import { Calendar as CalendarIcon } from 'lucide-vue-next';

import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useForm, router } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { useBackButton } from "@/Composables/useBackButton";

// Lucide icons for table actions
import { Trash2 } from "lucide-vue-next";

const props = defineProps({
    order: { type: Object, required: true },
    orderedItems: { type: Object, required: true },
    branches: { type: Object, required: true },
    suppliers: { type: Object, required: true },
    enabledDates: { type: Array, required: true },
});

const confirm = useConfirm();
const { toast } = useToast();
const { backButton } = useBackButton(route("store-orders.index"));

const drafts = ref(null);
const previousStoreOrderNumber = ref(null);
onBeforeMount(() => {
    const previousData = localStorage.getItem("editStoreOrderDraft");
    const previoustoreOrderNumber = localStorage.getItem("previoustoreOrderNumber");
    if (previousData) drafts.value = JSON.parse(previousData);
    if (previoustoreOrderNumber) previousStoreOrderNumber.value = previoustoreOrderNumber;
});

const isMountedAndReady = ref(false);

const orderForm = useForm({
    supplier_id: props.order.supplier.supplier_code + "",
    order_date: props.order.order_date,
    branch_id: props.order.store_branch_id ? String(props.order.store_branch_id) : null,
    orders: [],
});

const fetchSupplierItems = async (supplierCode) => {
    if (!supplierCode) {
        availableProductsOptions.value = [];
        return;
    }
    isLoading.value = true;
    try {
        const response = await axios.get(route('store-orders.get-supplier-items', supplierCode));
        availableProductsOptions.value = response.data.items;
    } catch (err) {
        toast.add({ severity: "error", summary: "Error", detail: "Failed to load items.", life: 5000 });
        availableProductsOptions.value = [];
    } finally {
        isLoading.value = false;
    }
};

onMounted(() => {
    if (drafts.value && previousStoreOrderNumber.value === props.order.order_number) {
        confirm.require({
            message: "You have an unfinished draft. Would you like to continue where you left off or discard it?",
            header: "Unfinished Draft Detected",
            icon: "pi pi-exclamation-triangle",
            rejectProps: { label: "Discard", severity: "danger" },
            acceptProps: { label: "Continue", severity: "primary" },
            accept: () => {
                orderForm.supplier_id = drafts.value.supplier_id;
                orderForm.order_date = drafts.value.order_date;
                orderForm.branch_id = drafts.value.branch_id;
                orderForm.orders = drafts.value.orders || [];
                if(orderForm.supplier_id) {
                    fetchSupplierItems(orderForm.supplier_id);
                    watch(() => orderForm.supplier_id, () => {}, { immediate: true });
                    if(orderForm.order_date) {
                         watch(() => orderForm.order_date, () => {}, { immediate: true });
                    }
                }
            },
            reject: () => {
                populateInitialOrdersFromProps();
                fetchSupplierItems(props.order.supplier.supplier_code);
            }
        });
    } else {
        populateInitialOrdersFromProps();
        fetchSupplierItems(props.order.supplier.supplier_code);
    }
    isMountedAndReady.value = true;
});

const populateInitialOrdersFromProps = () => {
    const initialOrders = props.orderedItems.map(item => {
        let baseQty = 1;
        let baseUom = null;
        if (item.supplier_item && item.supplier_item.sap_master_file) {
            const sap = item.supplier_item.sap_master_file;
            baseQty = Number(sap.BaseQty) || 1;
            baseUom = sap.BaseUOM;
        }
        const quantityOrdered = Number(item.quantity_ordered);
        const itemCost = Number(item.cost_per_quantity);
        const calculatedBaseUomQty = parseFloat((quantityOrdered * baseQty).toFixed(2));
        const calculatedTotalCost = parseFloat((calculatedBaseUomQty * itemCost).toFixed(2));
        return {
            id: item.id,
            inventory_code: String(item.supplier_item.ItemCode),
            name: item.supplier_item.item_name,
            unit_of_measurement: item.supplier_item.uom,
            base_uom: baseUom,
            base_qty: baseQty,
            base_uom_qty: calculatedBaseUomQty,
            quantity: quantityOrdered,
            cost: itemCost,
            total_cost: calculatedTotalCost,
            uom: item.supplier_item.uom,
        };
    });
    orderForm.orders = initialOrders;
};

const dynamicBranches = ref(props.branches);
const { options: suppliersOptions } = useSelectOptions(props.suppliers);

const branchesOptions = computed(() => Object.entries(dynamicBranches.value || {}).map(([id, name]) => ({ value: id, label: name })));

const availableProductsOptions = ref([]);
const productId = ref(null);
const isLoading = ref(false);

// --- Calendar Logic ---
const isDatepickerDisabled = ref(false);
const enabledDates = ref(props.enabledDates);
const showCalendar = ref(false);
const currentCalendarDate = ref(new Date(props.order.order_date + 'T00:00:00'));
const dateInputRef = ref(null);
const calendarPositionClass = ref('top-full mt-2');

watch(showCalendar, (isShown) => {
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
    const dateRef = currentCalendarDate.value;
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
        orderForm.order_date = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        showCalendar.value = false;
    }
};
// --- End Calendar Logic ---

watch(orderForm, (value) => {
    if (isMountedAndReady.value) {
        localStorage.setItem("editStoreOrderDraft", JSON.stringify(value));
        localStorage.setItem("previoustoreOrderNumber", props.order.order_number);
    }
}, { deep: true });

const itemForm = useForm({ item: null });

const productDetails = reactive({
    id: null, inventory_code: null, name: null, unit_of_measurement: null,
    base_uom: null, base_qty: null, quantity: null, cost: null, total_cost: null, uom: null
});

const removeItem = (id) => {
    confirm.require({
        message: "Are you sure you want to remove this item?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        accept: () => {
            orderForm.orders = orderForm.orders.filter(item => item.id !== id);
            toast.add({ severity: "success", summary: "Confirmed", detail: "Item Removed", life: 3000 });
        }
    });
};

const clearAllOrders = () => {
    confirm.require({
        message: "Are you sure you want to remove ALL items?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        accept: () => {
            orderForm.orders = [];
            toast.add({ severity: "success", summary: "Confirmed", detail: "All items removed.", life: 3000 });
        }
    });
};

const addToOrdersButton = () => {
    itemForm.clearErrors();
    if (!itemForm.item) return itemForm.setError("item", "Item field is required");
    if (isNaN(Number(productDetails.quantity)) || Number(productDetails.quantity) < 0.1) return itemForm.setError("quantity", "Quantity must be at least 0.1");
    if (productDetails.cost === null || Number(productDetails.cost) === 0) return toast.add({ severity: "error", summary: "Validation Error", detail: "Item cost cannot be zero.", life: 5000 });

    const existingItemIndex = orderForm.orders.findIndex(order => order.inventory_code === productDetails.inventory_code);
    const effectiveBaseQty = Number(productDetails.base_qty) > 0 ? Number(productDetails.base_qty) : 1;
    const currentQuantity = Number(productDetails.quantity);
    const currentCost = Number(productDetails.cost);

    if (existingItemIndex !== -1) {
        const existingItem = orderForm.orders[existingItemIndex];
        const newTotalQuantity = existingItem.quantity + currentQuantity;
        const effectiveBaseQtyForExisting = Number(existingItem.base_qty) > 0 ? Number(existingItem.base_qty) : 1;
        existingItem.quantity = newTotalQuantity;
        existingItem.base_uom_qty = parseFloat((newTotalQuantity * effectiveBaseQtyForExisting).toFixed(2));
        existingItem.total_cost = parseFloat((existingItem.base_uom_qty * currentCost).toFixed(2));
    } else {
        const base_uom_qty = parseFloat((currentQuantity * effectiveBaseQty).toFixed(2));
        const total_cost = parseFloat((base_uom_qty * currentCost).toFixed(2));
        orderForm.orders.push({ ...productDetails, id: null, quantity: currentQuantity, base_uom_qty, total_cost });
    }

    Object.keys(productDetails).forEach(key => productDetails[key] = null);
    productId.value = null;
    toast.add({ severity: "success", summary: "Success", detail: "Item added successfully.", life: 5000 });
    itemForm.item = null;
};

const update = () => {
    if (orderForm.orders.length < 1) return toast.add({ severity: "error", summary: "Error", detail: "Please select at least one item.", life: 5000 });
    if (!orderForm.order_date) return orderForm.setError("order_date", "Order date is required.");

    const formatDate = (date) => {
        if (!date || typeof date === 'string') return date;
        const d = new Date(date);
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    };
    orderForm.order_date = formatDate(orderForm.order_date);

    confirm.require({
        message: "Are you sure you want to update the order details?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        accept: () => {
            orderForm.put(route("store-orders.update", props.order.id), {
                onSuccess: () => {
                    localStorage.removeItem("editStoreOrderDraft");
                    localStorage.removeItem("previoustoreOrderNumber");
                    router.visit(route('store-orders.index'));
                },
                onError: (e) => toast.add({ severity: "error", summary: "Error", detail: e.error || e.message || "Can't update the order.", life: 5000 }),
            });
        }
    });
};

watch(productId, async (itemCode) => {
    if (itemCode) {
        isLoading.value = true;
        itemForm.item = itemCode;
        try {
            const response = await axios.get(route("SupplierItems.get-details-by-code", { itemCode, supplierCode: orderForm.supplier_id }));
            const result = response.data.item;
            if (result) {
                productDetails.inventory_code = String(result.ItemCode);
                productDetails.name = result.item_name;
                productDetails.unit_of_measurement = result.uom;
                productDetails.cost = Number(result.cost);
                productDetails.uom = result.uom;
                let foundBaseUom = null;
                let foundBaseQty = 1;
                if (result.sap_master_file) {
                    foundBaseUom = result.sap_master_file.BaseUOM;
                    foundBaseQty = Number(result.sap_master_file.BaseQty) || 1;
                }
                productDetails.base_uom = foundBaseUom;
                productDetails.base_qty = foundBaseQty;
            } else {
                toast.add({ severity: "error", summary: "Error", detail: "Item details not found.", life: 5000 });
            }
        } catch (err) {
            toast.add({ severity: "error", summary: "Error", detail: "Failed to load item details.", life: 5000 });
        } finally {
            isLoading.value = false;
        }
    } else {
        Object.keys(productDetails).forEach(key => productDetails[key] = null);
    }
}, { deep: true });

watch(() => orderForm.supplier_id, async (supplierCode) => {
    if (!isMountedAndReady.value) return;

    orderForm.order_date = null;
    orderForm.branch_id = null;
    dynamicBranches.value = {};
    productId.value = null;
    Object.keys(productDetails).forEach(key => productDetails[key] = null);

    fetchSupplierItems(supplierCode);

    enabledDates.value = [];
    if (!supplierCode) {
        isDatepickerDisabled.value = true;
        return;
    }

    isDatepickerDisabled.value = true;
    try {
        const datesResponse = await axios.get(route('store-orders.available-dates', { supplier_code: supplierCode }));
        enabledDates.value = datesResponse.data;
        isDatepickerDisabled.value = false;
    } catch (error) {
        toast.add({ severity: "error", summary: "Error", detail: "Failed to load available dates.", life: 5000 });
    }
});

watch(() => orderForm.order_date, async (newDate) => {
    if (!isMountedAndReady.value) return;

    orderForm.branch_id = null;
    if (newDate && orderForm.supplier_id) {
        try {
            const response = await axios.get(route('store-orders.get-branches', { order_date: newDate, supplier_code: orderForm.supplier_id }));
            dynamicBranches.value = response.data;
        } catch (error) {
            console.error('Error fetching branches:', error);
            toast.add({ severity: "error", summary: "Error", detail: "Failed to load store branches.", life: 5000 });
        }
    } else {
        dynamicBranches.value = {};
    }
});

const isSupplierSelected = computed(() => !!orderForm.supplier_id);
const shouldLockDropdowns = computed(() => orderForm.orders.length > 0);
const computeOverallTotal = computed(() => orderForm.orders.reduce((total, order) => total + parseFloat(order.total_cost), 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const heading = `Edit Order #${props.order.order_number}`;

</script>

<template>
    <Layout :heading="heading">
        <div class="grid sm:grid-cols-3 gap-5 grid-cols-1">
            <section class="grid gap-5">
                <Card>
                    <CardHeader>
                        <CardTitle>Order Details</CardTitle>
                        <CardDescription>Please input all the fields</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex flex-col space-y-1">
                            <InputLabel label="Supplier" />
                            <Select filter placeholder="Select a Supplier" v-model="orderForm.supplier_id" :options="suppliersOptions" optionLabel="label" optionValue="value" :disabled="shouldLockDropdowns" />
                            <FormError>{{ orderForm.errors.supplier_id }}</FormError>
                        </div>

                        <div class="flex flex-col space-y-1">
                            <InputLabel label="Order Date" />
                            <div class="relative" ref="dateInputRef">
                                <div class="relative">
                                    <input id="order_date" type="text" readonly :value="orderForm.order_date" @click="showCalendar = !showCalendar" :disabled="isDatepickerDisabled || shouldLockDropdowns" class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm transition-colors placeholder:text-gray-500 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-gray-400 disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer" placeholder="Select a date" />
                                    <CalendarIcon class="absolute right-3 top-1/2 -translate-y-1/2 size-4 text-gray-500 pointer-events-none" />
                                </div>
                                <div v-show="showCalendar" :class="['absolute z-50 bg-white border border-gray-200 rounded-lg shadow-lg p-4 w-full min-w-[300px]', calendarPositionClass]">
                                    <div class="flex justify-between items-center mb-4">
                                        <button type="button" @click.stop="goToPrevMonth()" class="p-2 rounded-full hover:bg-gray-100">&lt;</button>
                                        <h2 class="text-lg font-semibold">{{ currentCalendarDate.toLocaleString('default', { month: 'long', year: 'numeric' }) }}</h2>
                                        <button type="button" @click.stop="goToNextMonth()" class="p-2 rounded-full hover:bg-gray-100">&gt;</button>
                                    </div>
                                    <div class="grid grid-cols-7 gap-1 text-center text-xs font-medium text-gray-500 mb-2"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
                                    <div class="grid grid-cols-7 gap-2">
                                        <template v-for="(day, d_idx) in getCalendarDays()" :key="d_idx">
                                            <div class="text-center py-1.5 rounded-full text-sm" :class="[ !day ? '' : (day.isDisabled ? 'text-gray-300 line-through cursor-not-allowed' : (orderForm.order_date && day.date.toDateString() === new Date(orderForm.order_date + 'T00:00:00').toDateString() ? 'bg-blue-600 text-white font-bold shadow-md' : 'bg-gray-100 text-gray-800 font-semibold cursor-pointer hover:bg-blue-100')) ]" @click="selectDate(day)">{{ day ? day.day : '' }}</div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <FormError>{{ orderForm.errors.order_date }}</FormError>
                        </div>

                        <div class="flex flex-col space-y-1">
                            <InputLabel label="Store Branch" />
                            <Select filter placeholder="Select a Store" v-model="orderForm.branch_id" :options="branchesOptions" optionLabel="label" optionValue="value" :disabled="shouldLockDropdowns" />
                            <FormError>{{ orderForm.errors.branch_id }}</FormError>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Add Item</CardTitle>
                        <CardDescription>Please input all the fields</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex flex-col space-y-1">
                            <Label>Item</Label>
                            <Select filter placeholder="Select an Item" v-model="productId" :options="availableProductsOptions" optionLabel="label" optionValue="value" :disabled="!isSupplierSelected || isLoading">
                                <template #empty>
                                    <div v-if="isLoading" class="p-4 text-center text-gray-500">Loading items...</div>
                                    <div v-else class="p-4 text-center text-gray-500">No items available for this supplier.</div>
                                </template>
                            </Select>
                            <FormError>{{ itemForm.errors.item }}</FormError>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <Label>Unit Of Measurement (UOM)</Label>
                            <Input type="text" disabled v-model="productDetails.unit_of_measurement" />
                        </div>
                        <div class="flex flex-col space-y-1">
                            <Label>Cost</Label>
                            <Input type="text" disabled v-model="productDetails.cost" />
                        </div>
                        <div class="flex flex-col space-y-1">
                            <Label>Quantity</Label>
                            <Input type="number" v-model="productDetails.quantity" />
                            <FormError>{{ itemForm.errors.quantity }}</FormError>
                        </div>
                    </CardContent>
                    <CardFooter class="flex justify-end">
                        <Button @click="addToOrdersButton" :disabled="isLoading">Add to Orders</Button>
                    </CardFooter>
                </Card>
            </section>

            <Card class="col-span-2 flex flex-col">
                <CardHeader class="flex justify-between">
                    <DivFlexCenter class="justify-between">
                        <CardTitle>Items List</CardTitle>
                        <DivFlexCenter class="gap-2">
                            <LabelXS> Overall Total:</LabelXS>
                            <SpanBold>{{ computeOverallTotal }}</SpanBold>
                            <Button @click="clearAllOrders" variant="outline" class="text-red-500" :disabled="orderForm.orders.length === 0">
                                <Trash2 class="size-4 mr-1" /> Delete All
                            </Button>
                        </DivFlexCenter>
                    </DivFlexCenter>
                </CardHeader>
                <CardContent class="flex-1">
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHead>
                                <TH>Name</TH> <TH>Code</TH> <TH>Ordered Qty</TH> <TH>Unit</TH> <TH>BaseUOM Qty</TH> <TH>Base UOM</TH> <TH>Cost</TH> <TH>Total Cost</TH> <TH>Action</TH>
                            </TableHead>
                            <TableBody>
                                <tr v-for="order in orderForm.orders" :key="order.id">
                                    <TD>{{ order.name }}</TD>
                                    <TD>{{ order.inventory_code }}</TD>
                                    <TD>{{ order.quantity }}</TD>
                                    <TD>{{ order.unit_of_measurement }}</TD>
                                    <TD>{{ order.base_uom_qty }}</TD>
                                    <TD>{{ order.base_uom }}</TD>
                                    <TD>{{ Number(order.cost).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</TD>
                                    <TD>{{ Number(order.total_cost).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</TD>
                                    <TD class="flex gap-3">
                                        <button @click="removeItem(order.id)" variant="outline" class="text-red-500 size-5"><Trash2 /></button>
                                    </TD>
                                </tr>
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
                <CardFooter class="flex justify-end">
                    <Button @click="update">Update Order</Button>
                </CardFooter>
            </Card>
        </div>
    </Layout>
</template>