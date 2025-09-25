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
const { backButton } = useBackButton(route("mass-orders.index"));

// Lucide icons for table actions - Minus and Plus removed
import { Trash2 } from "lucide-vue-next";

// Define props explicitly for <script setup>
const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
    orderedItems: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
    suppliers: {
        type: Object,
        required: true,
    },
    enabledDates: {
        type: Array,
        required: true,
    },
});


const confirm = useConfirm();
const { toast } = useToast();

const drafts = ref(null);
const previousStoreOrderNumber = ref(null);
onBeforeMount(() => {
    const previousData = localStorage.getItem("editStoreOrderDraft");
    const previoustoreOrderNumber = localStorage.getItem(
        "previoustoreOrderNumber"
    );

    if (previousData) {
        drafts.value = JSON.parse(previousData);
    }
    if (previoustoreOrderNumber) { // Check if it exists before parsing
        previousStoreOrderNumber.value = previoustoreOrderNumber;
    }
});

const isMountedAndReady = ref(false);

const orderForm = useForm({
    supplier_id: props.order.supplier.supplier_code + "",
    order_date: props.order.order_date, // Initialize with string from props.order.order_date
    branch_id: props.order.store_branch_id ? String(props.order.store_branch_id) : null,
    orders: [], // Initialize as empty, will be populated in onMounted
});

const editableOrderItems = ref([]);

// Function to fetch items for a given supplier
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
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Failed to load items for the selected supplier.",
            life: 5000,
        });
        availableProductsOptions.value = [];
    } finally {
        isLoading.value = false;
    }
};

onMounted(() => {
    if (
        drafts.value &&
        previousStoreOrderNumber.value === props.order.order_number
    ) {
        confirm.require({
            message:
                "You have an unfinished draft. Would you like to continue where you left off or discard the draft?",
            header: "Unfinished Draft Detected",
            icon: "pi pi-exclamation-triangle",
            rejectProps: {
                label: "Discard",
                severity: "danger",
            },
            acceptProps: {
                label: "Continue",
                severity: "primary",
            },
            accept: () => {
                orderForm.supplier_id = drafts.value.supplier_id;
                orderForm.branch_id = drafts.value.branch_id;

                orderForm.order_date = drafts.value.order_date !== null && drafts.value.order_date !== undefined
                                         ? drafts.value.order_date
                                         : props.order.order_date; // Fallback to prop value
                orderForm.orders = drafts.value.orders || [];
                editableOrderItems.value = orderForm.orders;
                // Fetch items for the draft's supplier
                fetchSupplierItems(orderForm.supplier_id);
            },
            reject: () => {
                populateInitialOrdersFromProps();
                 // Fetch initial items from props
                fetchSupplierItems(props.order.supplier.supplier_code);
            }
        });
    } else {
        populateInitialOrdersFromProps();
        // Fetch initial items from props
        fetchSupplierItems(props.order.supplier.supplier_code);
    }
    isMountedAndReady.value = true;
});

const populateInitialOrdersFromProps = () => {
    const initialOrders = [];
    props.orderedItems.forEach((item, index) => {
        let baseQty = 1; // Default to 1
        let baseUom = null;

        const supplierItemData = item.supplier_item; // Get the supplier_item data
        if (supplierItemData && supplierItemData.sap_master_file) {
            const sapMasterFileObject = supplierItemData.sap_master_file;

            if (Object.prototype.hasOwnProperty.call(sapMasterFileObject, 'BaseQty')) {
                const rawBaseQty = sapMasterFileObject.BaseQty; // Access directly from the object
                baseQty = Number(rawBaseQty);
                if (isNaN(baseQty) || baseQty <= 0) {
                    baseQty = 1; // Fallback if conversion results in NaN or non-positive
                }
                baseUom = sapMasterFileObject.BaseUOM;
            }
        }

        const quantityOrdered = Number(item.quantity_ordered);
        const itemCost = Number(item.cost_per_quantity); // Use cost_per_quantity from ordered item

        const calculatedBaseUomQty = parseFloat((quantityOrdered * baseQty).toFixed(2));
        const calculatedTotalCost = parseFloat((calculatedBaseUomQty * itemCost).toFixed(2));

        const product = {
            id: item.id,
            inventory_code: String(item.supplier_item.ItemCode),
            name: item.supplier_item.item_name,
            unit_of_measurement: item.supplier_item.uom, // Use 'unit_of_measurement'
            base_uom: baseUom, // Use the determined BaseUOM
            base_qty: baseQty, // Use the determined BaseQTY
            base_uom_qty: calculatedBaseUomQty,
            quantity: quantityOrdered,
            cost: itemCost,
            total_cost: calculatedTotalCost, // Use calculated total cost
            uom: item.supplier_item.uom,
        };
        initialOrders.push(product);
    });
    orderForm.orders = initialOrders;
    editableOrderItems.value = orderForm.orders; // CRITICAL FIX: Sync the ref with the initial data
};

const dynamicBranches = ref(props.branches);
const { options: suppliersOptions } = useSelectOptions(props.suppliers);

// Create computed property for branch options locally instead of using composable
const branchesOptions = computed(() => {
    return Object.entries(dynamicBranches.value || {}).map(([id, name]) => ({
        value: id,
        label: name,
    }));
});

const availableProductsOptions = ref([]);

const productId = ref(null);
const isLoading = ref(false);

const skippedImportMessages = ref([]);

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
        const calendarHeight = 400; // Approximate height of the calendar
        const spaceBelow = window.innerHeight - inputRect.bottom;

        if (spaceBelow < calendarHeight && inputRect.top > calendarHeight) {
            calendarPositionClass.value = 'bottom-full mb-2'; // Flip up
        } else {
            calendarPositionClass.value = 'top-full mt-2'; // Default position
        }
    }
});

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
        orderForm.order_date = `${year}-${month}-${dayOfMonth}`;
        showCalendar.value = false;
    }
};
// --- End Calendar Logic ---

watch(orderForm, (value) => {
    if (!isMountedAndReady.value) {
        return;
    }
    const draftJson = JSON.stringify(value);
    localStorage.setItem("editStoreOrderDraft", draftJson);
    localStorage.setItem("previoustoreOrderNumber", props.order.order_number);
}, { deep: true });

const itemForm = useForm({
    item: null,
});

const productDetails = reactive({
    id: null,
    inventory_code: null,
    name: null,
    unit_of_measurement: null,
    base_uom: null,
    base_qty: null,
    quantity: null,
    cost: null,
    total_cost: null,
    uom: null,
});


const addItemQuantity = (id) => {
    const index = orderForm.orders.findIndex((item) => item.id === id);
    if (index !== -1) {
        const currentItem = orderForm.orders[index];
        currentItem.quantity = parseFloat(
            (Number(currentItem.quantity) + 0.1).toFixed(2)
        );

        const effectiveBaseQty = Number(currentItem.base_qty) > 0 ? Number(currentItem.base_qty) : 1;
        currentItem.base_uom_qty = parseFloat(
            (Number(currentItem.quantity) * effectiveBaseQty).toFixed(2)
        );
        currentItem.total_cost = parseFloat(
            Number(currentItem.base_uom_qty) * Number(currentItem.cost)
        ).toFixed(2);
    }
};

const minusItemQuantity = (id) => {
    const index = orderForm.orders.findIndex((item) => item.id === id);
    if (index !== -1) {
        const currentItem = orderForm.orders[index];
        currentItem.quantity = parseFloat(
            (Number(currentItem.quantity) - 0.1).toFixed(2)
        );
        if (Number(currentItem.quantity) < 0.1) {
            orderForm.orders = orderForm.orders.filter((item) => item.id !== id);
            return;
        }

        const effectiveBaseQty = Number(currentItem.base_qty) > 0 ? Number(currentItem.base_qty) : 1;
        currentItem.base_uom_qty = parseFloat(
            (Number(currentItem.quantity) * effectiveBaseQty).toFixed(2)
        );
        currentItem.total_cost = parseFloat(
            Number(currentItem.base_uom_qty) * Number(currentItem.cost)
        ).toFixed(2);
    }
};

const removeItem = (id) => {
    confirm.require({
        message: "Are you sure you want to remove this item from your orders?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Remove",
            severity: "danger",
        },
        accept: () => {
            orderForm.orders = orderForm.orders.filter(
                (item) => item.id !== id
            );
            toast.add({
                severity: "success",
                summary: "Confirmed",
                detail: "Item Removed",
                life: 3000,
            });
        },
    });
};

const clearAllOrders = () => {
    confirm.require({
        message: "Are you sure you want to remove ALL items from your orders?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Remove All",
            severity: "danger",
        },
        accept: () => {
            orderForm.orders = []; // Clear the array
            toast.add({
                severity: "success",
                summary: "Confirmed",
                detail: "All items removed.",
                life: 3000,
            });
        },
    });
};


const addToOrdersButton = () => {
    itemForm.clearErrors();
    if (!itemForm.item) {
        itemForm.setError("item", "Item field is required");
        return;
    }
    if (isNaN(Number(productDetails.quantity)) || Number(productDetails.quantity) < 0.1) {
        itemForm.setError("quantity", "Quantity must be at least 0.1 and a valid number");
        return;
    }
    if (productDetails.cost === null || Number(productDetails.cost) === 0) {
        toast.add({
            severity: "error",
            summary: "Validation Error",
            detail: "Item cost cannot be zero or empty.",
            life: 5000,
        });
        return;
    }

    if (
        !productDetails.inventory_code ||
        !productDetails.name ||
        !productDetails.unit_of_measurement ||
        !productDetails.quantity ||
        productDetails.cost === null
    ) {
        toast.add({
            severity: "error",
            summary: "Validation Error",
            detail: "Please ensure all item details are loaded (name, code, UOM, quantity, cost).",
            life: 5000,
        });
        return;
    }

    const existingItemIndex = orderForm.orders.findIndex(
        (order) => order.inventory_code === productDetails.inventory_code
    );

    const effectiveBaseQtyForNewItem = Number(productDetails.base_qty) > 0 ? Number(productDetails.base_qty) : 1;
    const currentQuantity = Number(productDetails.quantity);
    const currentCost = Number(productDetails.cost);


    if (existingItemIndex !== -1) {
        const existingItem = orderForm.orders[existingItemIndex];
        const newTotalQuantity = existingItem.quantity + currentQuantity;

        const effectiveBaseQtyForExistingItem = Number(existingItem.base_qty) > 0 ? Number(existingItem.base_qty) : 1;

        const newBaseUomQty = parseFloat((newTotalQuantity * effectiveBaseQtyForExistingItem).toFixed(2));
        const newTotalCost = parseFloat((newBaseUomQty * currentCost).toFixed(2));

        existingItem.quantity = newTotalQuantity;
        existingItem.base_uom_qty = newBaseUomQty;
        existingItem.total_cost = newTotalCost;

    } else {
        productDetails.base_uom_qty = parseFloat((currentQuantity * effectiveBaseQtyForNewItem).toFixed(2));
        productDetails.total_cost = parseFloat((productDetails.base_uom_qty * currentCost).toFixed(2));

        orderForm.orders.push({
            id: null, // Explicitly set 'id' to null for imported items
            inventory_code: String(productDetails.inventory_code),
            name: productDetails.name,
            unit_of_measurement: productDetails.unit_of_measurement,
            base_uom: productDetails.base_uom,
            base_qty: productDetails.base_qty,
            base_uom_qty: productDetails.base_uom_qty,
            quantity: parseFloat(Number(productDetails.quantity).toFixed(2)),
            cost: Number(productDetails.cost),
            uom: productDetails.uom,
            total_cost: productDetails.total_cost,
        });
    }

    Object.keys(productDetails).forEach((key) => {
        productDetails[key] = null;
    });
    productId.value = null;
    toast.add({
        severity: "success",
        summary: "Success",
        detail: "Item added successfully.",
        life: 5000,
    });
    itemForm.item = null;
    itemForm.clearErrors();
};

const update = () => {
    if (orderForm.orders.length < 1) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Please select at least one item before proceeding.",
            life: 5000,
        });
        return;
    }

    const formatDate = (date) => {
        if (!date) return null;
        if (typeof date === 'string') {
            return date;
        }
        const d = new Date(date);
        const month = String(d.getMonth() + 1).padStart(2, "0");
        const day = String(d.getDate()).padStart(2, "0");
        const year = d.getFullYear();
        return `${year}-${month}-${day}`;
    };
    orderForm.order_date = formatDate(orderForm.order_date);


    try {
        confirm.require({
            message: "Are you sure you want to update the order details?",
            header: "Confirmation",
            icon: "pi pi-exclamation-triangle",
            rejectProps: {
                label: "Cancel",
                severity: "secondary",
                outlined: true,
            },
            acceptProps: {
                label: "Confirm",
                severity: "info",
            },
            accept: () => {
                orderForm.put(route("mass-orders.update", props.order.order_number), {
                    onSuccess: () => {
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: "Order Updated Successfully.",
                            life: 5000,
                        });

                        localStorage.removeItem("editStoreOrderDraft");
                        localStorage.removeItem("previoustoreOrderNumber");
                        router.visit(route('mass-orders.index'));
                    },
                    onError: (e) => {
                        toast.add({
                            severity: "error",
                            summary: "Error",
                            detail: e.error || e.message || "Can't place update the order.",
                            life: 5000,
                        });
                    },
                });
            },
        });
    } catch (error) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "An unexpected error occurred with the confirmation dialog.",
            life: 5000,
        });
    }
};

watch(productId, async (itemCode) => {
    if (itemCode) {
        isLoading.value = true;
        itemForm.item = itemCode;

        try {
            const supplierCode = orderForm.supplier_id;

            if (!supplierCode) {
                toast.add({
                    severity: "error",
                    summary: "Error",
                    detail: "Failed to determine supplier code.",
                    life: 5000,
                });
                isLoading.value = false;
                return;
            }

            const response = await axios.get(route("SupplierItems.get-details-by-code", {
                itemCode: itemCode,
                supplierCode: supplierCode
            }));
            const result = response.data.item;

            if (result) {
                productDetails.inventory_code = String(result.ItemCode);
                productDetails.name = result.item_name;
                productDetails.unit_of_measurement = result.uom;
                productDetails.cost = Number(result.cost);
                productDetails.uom = result.uom;

                let foundBaseUom = null;
                let foundBaseQty = 1;

                const apiResultSapMasterFile = result.sap_master_file;
                if (apiResultSapMasterFile) {
                    if (Object.prototype.hasOwnProperty.call(apiResultSapMasterFile, 'BaseQty')) {
                        const rawFoundBaseQty = apiResultSapMasterFile.BaseQty;
                        foundBaseQty = Number(rawFoundBaseQty);
                        if (isNaN(foundBaseQty) || foundBaseQty <= 0) {
                            foundBaseQty = 1;
                        }
                        foundBaseUom = apiResultSapMasterFile.BaseUOM;
                    }
                }

                productDetails.base_uom = foundBaseUom;
                productDetails.base_qty = foundBaseQty;

            } else {
                toast.add({
                    severity: "error",
                    summary: "Error",
                    detail: "Item details not found.",
                    life: 5000,
                });
            }
        } catch (err) {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "Failed to load item details.",
                life: 5000,
            });
        } finally {
            isLoading.value = false;
        }
    } else {
        Object.keys(productDetails).forEach((key) => {
            productDetails[key] = null;
        });
    }
}, { deep: true });

const computeOverallTotal = computed(() => {
    return orderForm.orders
        .reduce((total, order) => total + parseFloat(order.total_cost), 0)
        .toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
});

watch(
    () => orderForm.supplier_id,
    async (supplierCode) => {
        if (!isMountedAndReady.value) return;

        // Clear dependent fields
        orderForm.order_date = null;
        orderForm.branch_id = null;
        dynamicBranches.value = {};
        productId.value = null;
        Object.keys(productDetails).forEach((key) => {
            productDetails[key] = null;
        });

        // Fetch new data
        fetchSupplierItems(supplierCode);

        enabledDates.value = [];
        if (!supplierCode) {
            isDatepickerDisabled.value = true;
            return;
        }

        isDatepickerDisabled.value = true;
        try {
            const datesResponse = await axios.get(route('mass-orders.available-dates', { supplier_code: supplierCode }));
            enabledDates.value = datesResponse.data;
            isDatepickerDisabled.value = false;
        } catch (error) {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "Failed to load available dates.",
                life: 5000,
            });
        }
    }
);

watch(() => orderForm.order_date, async (newDate) => {
    if (!isMountedAndReady.value) return;

    orderForm.branch_id = null;

    if (newDate && orderForm.supplier_id) {
        try {
            const response = await axios.get(route('mass-orders.get-branches', {
                order_date: newDate,
                supplier_code: orderForm.supplier_id
            }));
            dynamicBranches.value = response.data;
        } catch (error) {
            console.error('Error fetching branches:', error);
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "Failed to load store branches for the selected date.",
                life: 5000,
            });
        }
    } else {
        dynamicBranches.value = {};
    }
});

const isSupplierSelected = computed(() => {
    return orderForm.supplier_id !== null && orderForm.supplier_id !== '';
});

const shouldLockDropdowns = computed(() => {
    return orderForm.orders.length > 0;
});

const showImportOrdersButton = computed(() => {
    return (
        orderForm.orders.length === 0 &&
        orderForm.supplier_id !== null &&
        orderForm.supplier_id !== '' &&
        orderForm.branch_id !== null &&
        orderForm.branch_id !== '' &&
        orderForm.order_date !== null
    );
});


import { useEditQuantity } from "@/Composables/useEditQuantity";
const {
    isEditQuantityModalOpen,
    formQuantity,
    openEditQuantityModal,
} = useEditQuantity(orderForm, editableOrderItems, props.order);

const editQuantity = () => {
    const itemIndex = orderForm.orders.findIndex(item => item.id === formQuantity.id);

    if (itemIndex !== -1) {
        const newQuantity = Number(formQuantity.quantity);
        const currentItem = orderForm.orders[itemIndex];

        if (isNaN(newQuantity) || newQuantity <= 0) {
            formQuantity.errors.quantity = "Quantity must be a positive number.";
            toast.add({ severity: "error", summary: "Validation Error", detail: "Quantity must be a positive number.", life: 3000 });
            return;
        }

        const itemCost = Number(currentItem.cost);
        if (isNaN(itemCost)) {
            toast.add({ severity: "error", summary: "Calculation Error", detail: "Item cost is invalid. Cannot update total cost.", life: 3000 });
            return;
        }

        const effectiveBaseQty = Number(currentItem.base_qty) > 0 ? Number(currentItem.base_qty) : 1;

        currentItem.quantity = parseFloat(newQuantity.toFixed(2));
        currentItem.base_uom_qty = parseFloat((newQuantity * effectiveBaseQty).toFixed(2));
        currentItem.total_cost = parseFloat(
            Number(currentItem.base_uom_qty) * Number(currentItem.cost)
        ).toFixed(2);

        orderForm.orders = [...orderForm.orders];

        toast.add({ severity: "success", summary: "Success", detail: "Quantity Updated.", life: 3000 });
        isEditQuantityModalOpen.value = false;
    } else {
        toast.add({ severity: "error", summary: "Error", detail: "Item not found in order list.", life: 3000 });
    }
};


const heading = `Edit Order #${props.order.order_number}`;

const excelFileForm = useForm({
    orders_file: null,
});
const visible = ref(false);

watch(visible, (newValue) => {
    if (!newValue) {
        excelFileForm.reset();
        excelFileForm.clearErrors();
    }
});


const importOrdersButton = () => {
    visible.value = true;
    skippedImportMessages.value = [];
};

const downloadDynamicTemplate = async () => {
    const supplierCode = orderForm.supplier_id;
    if (!supplierCode) {
        toast.add({
            severity: "warn",
            summary: "Warning",
            detail: "Please select a supplier first to download the template.",
            life: 5000,
        });
        return;
    }

    try {
        const response = await axios.get(route('store-orders.download-supplier-order-template', { supplierCode: supplierCode }), {
            responseType: 'blob',
        });

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `supplier_order_template_${supplierCode}.xlsx`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);

        toast.add({
            severity: "success",
            summary: "Success",
            detail: "Template download started.",
            life: 3000,
        });

    } catch (error) {
        console.error("Error downloading template:", error);
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Failed to download template. Please try again.",
            life: 5000,
        });
    }
};


const addImportedItemsToOrderList = () => {
    isLoading.value = true;
    skippedImportMessages.value = [];
    const formData = new FormData();
    formData.append("orders_file", excelFileForm.orders_file);
    formData.append("supplier_id", orderForm.supplier_id);

    axios
        .post(route("store-orders.imported-file"), formData, {
            headers: {
                "Content-Type": "multipart/form-data",
            },
        })
        .then((response) => {
            response.data.orders.forEach((importedOrder) => {
                const itemCodeString = importedOrder.item_code || importedOrder.ItemCode || importedOrder.inventory_code;
                const itemName = importedOrder.item_name || importedOrder.ItemName || importedOrder.name;

                const importedItemId = itemCodeString;

                const rawQuantityValue = importedOrder.qty || importedOrder.Qty || importedOrder.quantity;
                const quantity = Number(rawQuantityValue);

                const cost = Number(importedOrder.cost || importedOrder.Cost);
                const unit = importedOrder.unit || importedOrder.UOM || importedOrder.unit_of_measurement;
                const baseQty = Number(importedOrder.base_qty || importedOrder.BaseQTY);

                if (isNaN(cost) || cost === 0) {
                    toast.add({
                        severity: "error",
                        summary: "Validation Error",
                        detail: `Imported item '${itemName || itemCodeString || 'Unknown Item'}' has a cost of zero or is invalid and will be skipped.`,
                        life: 7000,
                    });
                    return;
                }

                if (isNaN(quantity) || quantity < 0.1) {
                    return;
                }

                if (isNaN(baseQty) || baseQty <= 0) {
                    toast.add({
                        severity: "error",
                        summary: "Validation Error",
                        detail: `Imported item '${itemName || itemCodeString || 'Unknown Item'}' has an invalid BaseQTY and will be skipped. BaseQTY must be a positive number.`,
                        life: 7000,
                    });
                    return;
                }

                const importedBaseUomQty = parseFloat((quantity * baseQty).toFixed(2));
                const importedTotalCost = parseFloat((importedBaseUomQty * cost).toFixed(2));

                const existingItemIndex = orderForm.orders.findIndex(
                    (order) => order.inventory_code === itemCodeString
                );

                if (existingItemIndex !== -1) {
                    const updatedQuantity =
                        Number(orderForm.orders[existingItemIndex].quantity) + quantity;
                    const updatedBaseUomQty = parseFloat((updatedQuantity * baseQty).toFixed(2));

                    orderForm.orders[existingItemIndex].quantity = updatedQuantity;
                    orderForm.orders[existingItemIndex].base_uom_qty = updatedBaseUomQty;
                    orderForm.orders[existingItemIndex].total_cost = parseFloat(
                        updatedBaseUomQty * cost
                    ).toFixed(2);
                } else {
                    const newItem = {
                        id: null,
                        inventory_code: String(itemCodeString),
                        name: itemName,
                        unit_of_measurement: unit,
                        base_uom: importedOrder.base_uom || null,
                        base_qty: baseQty,
                        base_uom_qty: importedBaseUomQty,
                        quantity: parseFloat(quantity.toFixed(2)),
                        cost: cost,
                        uom: unit,
                        total_cost: importedTotalCost,
                    };
                    orderForm.orders.push(newItem);
                }
            });

            visible.value = false;
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Items added successfully.",
                life: 5000,
            });
            excelFileForm.orders_file = null;

            if (response.data.skipped_items && response.data.skipped_items.length > 0) {
                skippedImportMessages.value = response.data.skipped_items.map(skippedItem =>
                    `Item '${skippedItem.item_name || skippedItem.item_code || 'Unknown'}' was skipped: ${skippedItem.reason}`
                );
            }
        })
        .catch((error) => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: error.response.data.message || "An error occurred while trying to get the imported orders. Please make sure that you are using the correct format.",
                life: 5000,
            });
            excelFileForm.setError("orders_file", error.response.data.message || "Unknown error during import.");
        })
        .finally(() => (isLoading.value = false));
};

</script>
<template>
    <Layout
        :heading="heading"
        :hasButton="showImportOrdersButton"
        buttonName="Import Orders"
        :handleClick="importOrdersButton"
    >
        <div v-if="skippedImportMessages.length > 0" class="mb-4 p-4 rounded-lg bg-yellow-100 text-yellow-800 border border-yellow-200">
            <p class="font-bold mb-2">Skipped Items:</p>
            <ul class="list-disc list-inside">
                <li v-for="(message, index) in skippedImportMessages" :key="index">
                    {{ message }}
                </li>
            </ul>
        </div>

        <div class="grid sm:grid-cols-3 gap-5 grid-cols-1">
            <section class="grid gap-5">
                <Card>
                    <CardHeader>
                        <CardTitle>Order Details</CardTitle>
                        <CardDescription
                            >Please input all the fields</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex flex-col space-y-1">
                            <InputLabel label="Supplier" />
                            <Select
                                filter
                                placeholder="Select a Supplier"
                                v-model="orderForm.supplier_id"
                                :options="suppliersOptions"
                                optionLabel="label"
                                optionValue="value"
                                :disabled="shouldLockDropdowns"
                            >
                            </Select>
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
                                        <h2 class="text-lg font-semibold">{{ (currentCalendarDate || new Date()).toLocaleString('default', { month: 'long', year: 'numeric' }) }}</h2>
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
                            <Select
                                filter
                                placeholder="Select a Store"
                                v-model="orderForm.branch_id"
                                :options="branchesOptions"
                                optionLabel="label"
                                optionValue="value"
                                :disabled="shouldLockDropdowns"
                            >
                            </Select>
                            <FormError>{{ orderForm.errors.branch_id }}</FormError>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Add Item</CardTitle>
                        <CardDescription
                            >Please input all the fields</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="flex flex-col space-y-1">
                            <Label>Item</Label>
                            <Select
                                filter
                                placeholder="Select an Item"
                                v-model="productId"
                                :options="availableProductsOptions"
                                optionLabel="label"
                                optionValue="value"
                                :disabled="!isSupplierSelected || isLoading"
                            >
                                <template #empty>
                                    <div v-if="isLoading" class="p-4 text-center text-gray-500">
                                        Loading items...
                                    </div>
                                    <div v-else class="p-4 text-center text-gray-500">
                                        No items available for this supplier.
                                    </div>
                                </template>
                            </Select>
                            <FormError>{{ itemForm.errors.item }}</FormError>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <Label>Unit Of Measurement (UOM)</Label>
                            <Input
                                type="text"
                                disabled
                                v-model="productDetails.unit_of_measurement"
                            />
                        </div>
                        <div class="flex flex-col space-y-1">
                            <Label>Cost</Label>
                            <Input
                                type="text"
                                disabled
                                v-model="productDetails.cost"
                            />
                        </div>
                        <div class="flex flex-col space-y-1">
                            <Label>Quantity</Label>
                            <Input
                                type="number"
                                v-model="productDetails.quantity"
                            />
                            <FormError>{{ itemForm.errors.quantity }}</FormError>
                        </div>
                    </CardContent>

                    <CardFooter class="flex justify-end">
                        <Button @click="addToOrdersButton" :disabled="isLoading">
                            Add to Orders
                        </Button>
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
                            <Button
                                @click="clearAllOrders"
                                variant="outline"
                                class="text-red-500"
                                :disabled="orderForm.orders.length === 0"
                            >
                                <Trash2 class="size-4 mr-1" /> Delete All
                            </Button>
                        </DivFlexCenter>
                    </DivFlexCenter>
                </CardHeader>
                <CardContent class="flex-1">
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHead>
                                <TH> Name </TH>
                                <TH> Code </TH>
                                <TH> Ordered Qty </TH>
                                <TH> Unit </TH>
                                <TH> BaseUOM Qty </TH>
                                <TH> Base UOM </TH>
                                <TH> Cost </TH>
                                <TH> Total Cost </TH>
                                <TH> Action </TH>
                            </TableHead>

                            <TableBody>
                                <tr
                                    v-for="order in orderForm.orders"
                                    :key="order.id"
                                >
                                    <TD>
                                        {{ order.name }}
                                    </TD>
                                    <TD>
                                        {{ order.inventory_code }}
                                    </TD>
                                    <TD>
                                        {{ order.quantity }}
                                    </TD>
                                    <TD>
                                        {{ order.unit_of_measurement }}
                                    </TD>
                                    <TD>
                                        {{ order.base_uom_qty }}
                                    </TD>
                                    <TD>
                                        {{ order.base_uom }}
                                    </TD>
                                    <TD>
                                        {{ Number(order.cost).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                                    </TD>
                                    <TD>
                                        {{ Number(order.total_cost).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                                    </TD>
                                    <TD class="flex gap-3">
                                        <LinkButton
                                            @click="
                                                openEditQuantityModal(
                                                    order.id,
                                                    order.quantity
                                                )
                                            "
                                        >
                                            Edit Quantity
                                        </LinkButton>
                                        <button
                                            @click="removeItem(order.id)"
                                            variant="outline"
                                            class="text-red-500 size-5"
                                        >
                                            <Trash2 />
                                        </button>
                                    </TD>
                                </tr>
                            </TableBody>
                        </Table>
                    </div>

                    <MobileTableContainer>
                        <MobileTableRow
                            v-for="order in orderForm.orders"
                            :key="order.id"
                        >
                            <MobileTableHeading
                                :title="`${order.name} (${order.inventory_code})`"
                            >
                                <button
                                    class="text-red-500 size-5"
                                    @click="removeItem(order.id)"
                                    variant="outline"
                                >
                                    <Trash2 />
                                </button>
                            </MobileTableHeading>
                            <LabelXS
                                >UOM: {{ order.unit_of_measurement }}</LabelXS
                            >
                            <LabelXS>Ordered Qty: {{ order.quantity }}</LabelXS>
                            <LabelXS>BaseUOM Qty: {{ order.base_uom_qty }}</LabelXS>
                            <LabelXS>Cost: {{ Number(order.cost).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</LabelXS>
                            <LabelXS>Total Cost: {{ Number(order.total_cost).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</LabelXS>
                            <LinkButton
                                @click="
                                    openEditQuantityModal(
                                        order.id,
                                        order.quantity
                                    )
                                "
                            >
                                Edit Quantity
                            </LinkButton>
                        </MobileTableRow>
                    </MobileTableContainer>
                </CardContent>

                <CardFooter class="flex justify-end">
                    <Button @click="update">Update Order</Button>
                </CardFooter>
            </Card>
        </div>

        <Dialog v-model:open="visible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Import Orders</DialogTitle>
                    <DialogDescription>
                        Upload an Excel file to import orders.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-5">
                    <div class="flex flex-col space-y-1">
                        <Input
                            type="file"
                            @input="
                                (event) =>
                                    (excelFileForm.orders_file =
                                        event.target.files[0])
                            "
                        />
                        <FormError>{{ excelFileForm.errors.orders_file }}</FormError>
                    </div>
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs">Accepted Orders File Format</Label>
                        <ul>
                            <li class="text-xs">
                                <a
                                    class="text-blue-500 underline cursor-pointer"
                                    @click.prevent="downloadDynamicTemplate"
                                    >Click to download template</a
                                >
                            </li>
                        </ul>
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        :disabled="isLoading"
                        @click="addImportedItemsToOrderList"
                        type="submit"
                        class="gap-2"
                    >
                        Import
                        <span><Loading v-if="isLoading" /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isEditQuantityModalOpen">
            <DialogContent class="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Edit Quantity</DialogTitle>
                    <DialogDescription
                        >Make changes to the quantity here. Click save when
                        you're done.</DialogDescription
                    >
                </DialogHeader>
                <div class="grid gap-4 py-4">
                    <div class="grid grid-cols-4 items-center gap-4">
                        <Label for="quantity" class="text-right"> Quantity </Label>
                        <Input
                            id="quantity"
                            type="number"
                            class="col-span-3"
                            v-model="formQuantity.quantity"
                        />
                    </div>
                </div>
                <DialogFooter>
                    <Button @click="editQuantity">Save changes</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>