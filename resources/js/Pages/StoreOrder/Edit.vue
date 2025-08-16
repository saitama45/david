<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeMount } from 'vue';
import Select from "primevue/select";
import DatePicker from "primevue/datepicker";
import axios from 'axios';

import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { useBackButton } from "@/Composables/useBackButton";
const { backButton } = useBackButton(route("store-orders.index"));

import { Trash2, Minus, Plus } from "lucide-vue-next"; // Keep Lucide icons as they are used

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
    // CRITICAL FIX: Initialize supplier_id with the supplier_code from the order prop
    supplier_id: props.order.supplier.supplier_code + "", 
    branch_id: props.order.store_branch_id + "",
    order_date: props.order.order_date, // Initialize with string from props.order.order_date
    orders: [], // Initialize as empty, will be populated in onMounted
});

// CRITICAL FIX: Create a ref that will hold the reactive array from orderForm.orders
const editableOrderItems = ref([]);

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
                
                // Prioritize props.order.order_date if draft's order_date is null or undefined
                orderForm.order_date = drafts.value.order_date !== null && drafts.value.order_date !== undefined 
                                        ? drafts.value.order_date 
                                        : props.order.order_date; // Fallback to prop value
                // Restore orders from draft
                orderForm.orders = drafts.value.orders || [];
                editableOrderItems.value = orderForm.orders;
            },
            reject: () => {
                // If discarded, populate from props.orderedItems
                populateInitialOrdersFromProps();
            }
        });
    } else {
        // If no draft or draft is for a different order, populate from props.orderedItems
        populateInitialOrdersFromProps();
    }

    // The initial supplier selection logic is now handled by the orderForm.supplier_id initialization below
    // and the watch for orderForm.supplier_id.

    // Set the flag after initial setup is complete
    isMountedAndReady.value = true;
});

const populateInitialOrdersFromProps = () => {
    const initialOrders = [];
    props.orderedItems.forEach((item, index) => {
        console.log(`Processing initial item ${index}:`, JSON.parse(JSON.stringify(item))); // Log raw item data

        let baseQty = 1; // Default to 1
        let baseUom = null;
        
        // CRITICAL FIX: Always use the singular accessor 'sap_master_file'
        // as this is designed to return the correct matching record.
        const supplierItemData = item.supplier_item; // Get the supplier_item data
        if (supplierItemData && supplierItemData.sap_master_file) {
            const sapMasterFileObject = supplierItemData.sap_master_file;
            console.log('    Full sap_master_file object (onMounted):', JSON.parse(JSON.stringify(sapMasterFileObject))); // Retained log
            
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
            unit_of_measurement: item.supplier_item.uom,
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


const { options: branchesOptions } = useSelectOptions(props.branches);
// Suppliers options should return ItemCode as value, not ID, to match backend's 'item_code'
const { options: suppliersOptions } = useSelectOptions(props.suppliers); 

const availableProductsOptions = ref([]);

// productId will now hold the ItemCode string of the supplier item
const productId = ref(null); 
const isLoading = ref(false);

// NEW: Reactive array to store skipped import messages
const skippedImportMessages = ref([]);


const datePickerDate = computed({
    get() {
        if (orderForm.order_date) {
            const parts = orderForm.order_date.split('-');
            return new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        }
        return null;
    },
    set(value) {
        if (value) {
            const d = new Date(value);
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            const year = d.getFullYear();
            orderForm.order_date = `${year}-${month}-${day}`;
        } else {
            orderForm.order_date = null;
        }
    }
});


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
    id: null, // This will hold the StoreOrderItem ID if editing an existing one, otherwise null for new
    inventory_code: null, // This will now hold the SupplierItem ItemCode string
    name: null, // This will be the item_name
    unit_of_measurement: null, // This will be the uom for display
    base_uom: null, // NEW: From sap_master_file
    base_qty: null, // NEW: From sap_master_file
    quantity: null,
    cost: null,
    total_cost: null,
    uom: null, // This will be the uom for backend submission
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

// Function to clear all orders
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
    // CRITICAL FIX: Add validation for cost being 0 or null
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
        !productDetails.inventory_code || // This will now be the ItemCode string
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

    // CRITICAL FIX: Find by ItemCode string for new items
    const existingItemIndex = orderForm.orders.findIndex(
        (order) => order.inventory_code === productDetails.inventory_code
    );

    // Determine the effective BaseQTY for calculation. Default to 1 if not available or <= 0.
    const effectiveBaseQtyForNewItem = Number(productDetails.base_qty) > 0 ? Number(productDetails.base_qty) : 1;
    const currentQuantity = Number(productDetails.quantity);
    const currentCost = Number(productDetails.cost);


    if (existingItemIndex !== -1) {
        // Update existing item
        const existingItem = orderForm.orders[existingItemIndex];
        const newTotalQuantity = existingItem.quantity + currentQuantity;
        
        // Use the base_qty already present in the existing item, or default to 1
        const effectiveBaseQtyForExistingItem = Number(existingItem.base_qty) > 0 ? Number(existingItem.base_qty) : 1;

        const newBaseUomQty = parseFloat((newTotalQuantity * effectiveBaseQtyForExistingItem).toFixed(2));
        const newTotalCost = parseFloat((newBaseUomQty * currentCost).toFixed(2));
        
        existingItem.quantity = newTotalQuantity;
        existingItem.base_uom_qty = newBaseUomQty;
        existingItem.total_cost = newTotalCost;

    } else {
        // Add new item
        productDetails.base_uom_qty = parseFloat((currentQuantity * effectiveBaseQtyForNewItem).toFixed(2));
        productDetails.total_cost = parseFloat((productDetails.base_uom_qty * currentCost).toFixed(2));

        orderForm.orders.push({ 
            id: null, // Explicitly set 'id' to null for imported items
            inventory_code: String(productDetails.inventory_code), // This is now the ItemCode string - ensure it's a string
            name: productDetails.name, 
            unit_of_measurement: productDetails.unit_of_measurement, 
            base_uom: productDetails.base_uom, // NEW: Add BaseUOM
            base_qty: productDetails.base_qty, // NEW: Add BaseQTY
            base_uom_qty: productDetails.base_uom_qty, // NEW: Add calculated BaseUoM Qty
            quantity: parseFloat(Number(productDetails.quantity).toFixed(2)), // Ensure quantity is number and formatted
            cost: Number(productDetails.cost), // Ensure cost is number
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
        const d = new Date(date); // Changed 'value' to 'date' to match parameter name
        const month = String(d.getMonth() + 1).padStart(2, "0");
        const day = String(d.getDate()).padStart(2, "0");
        const year = d.getFullYear();
        return `${year}-${month}-${day}`;
    };
    orderForm.order_date = formatDate(orderForm.order_date);


    try {
        confirm.require({
            message: "Are you sure you want to update the order details?", // Changed message for clarity
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
                orderForm.put(route("store-orders.update", props.order.id), {
                    onSuccess: () => {
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: "Order Updated Successfully.",
                            life: 5000,
                        });

                        localStorage.removeItem("editStoreOrderDraft");
                        localStorage.removeItem("previoustoreOrderNumber");
                    },
                    onError: (e) => {
                        toast.add({
                            severity: "error",
                            summary: "Error",
                            detail: e.error || e.message || "Can't place update the order.", // Show error message if available
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

// productId will now hold the ItemCode string of the supplier item
watch(productId, async (itemCode) => { 
    if (itemCode) {
        isLoading.value = true;
        itemForm.item = itemCode; // Store the ItemCode string in itemForm.item

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

            // CRITICAL FIX: Fetch details using the ItemCode and SupplierCode
            const response = await axios.get(route("SupplierItems.get-details-by-code", {
                itemCode: itemCode, // Pass the ItemCode string
                supplierCode: supplierCode // Still need supplierCode to ensure it's the correct item for this supplier
            }));
            const result = response.data.item; // Assuming the API returns { item: {...} }

            if (result) {
                // Store the ItemCode string in productDetails.inventory_code - ensure it's a string
                productDetails.inventory_code = String(result.ItemCode); 
                productDetails.name = result.item_name;
                productDetails.unit_of_measurement = result.uom;
                productDetails.cost = Number(result.cost); // Ensure cost is a number
                productDetails.uom = result.uom; 

                // --- NEW LOGIC FOR BASE_UOM and BASE_QTY (from Create.vue) ---
                let foundBaseUom = null;
                let foundBaseQty = 1; // Default to 1 to prevent division by zero or NaN in calculations

                // CRITICAL FIX: Always use the singular accessor 'sap_master_file'
                const apiResultSapMasterFile = result.sap_master_file;
                if (apiResultSapMasterFile) {
                    console.log('    Full sap_master_file object (watch):', JSON.parse(JSON.stringify(apiResultSapMasterFile))); // Retained log
                    if (Object.prototype.hasOwnProperty.call(apiResultSapMasterFile, 'BaseQty')) {
                        const rawFoundBaseQty = apiResultSapMasterFile.BaseQty;
                        foundBaseQty = Number(rawFoundBaseQty);
                        if (isNaN(foundBaseQty) || foundBaseQty <= 0) {
                            foundBaseQty = 1; // Fallback if conversion results in NaN or non-positive
                        }
                        foundBaseUom = apiResultSapMasterFile.BaseUOM;
                    }
                }
                // --- END NEW LOGIC ---

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

const orderRestrictionDate = reactive({
    minDate: null,
    maxDate: null,
});

const calculatePULILANOrderDate = () => {
    const now = new Date();

    const nextSunday = new Date(now);
    nextSunday.setDate(now.getDate() + (7 - now.getDay()));

    const nextSaturday = new Date(nextSunday);
    nextSaturday.setDate(nextSunday.getDate() + 6);

    orderRestrictionDate.minDate = nextSunday;
    orderRestrictionDate.maxDate = nextSaturday;
};

const calculateGSIOrderDate = () => {
    const now = new Date();

    const nextSunday = new Date(now);
    nextSunday.setDate(now.getDate() + (7 - now.getDay()));

    const nextWednesday = new Date(nextSunday);
    nextWednesday.setDate(nextSunday.getDate() + 3);

    const upcomingSunday = new Date(now);
    upcomingSunday.setDate(now.getDate() + (7 - now.getDay()));

    const secondBatchStartDate = new Date(upcomingSunday);
    secondBatchStartDate.setDate(upcomingSunday.getDate() + 4);

    const secondBatchEndDate = new Date(upcomingSunday);
    secondBatchEndDate.setDate(upcomingSunday.getDate() + 6);

    const currentDay = now.getDay();
    const currentHour = now.getHours();

    if (
        currentDay === 0 ||
        currentDay === 1 ||
        currentDay === 2 ||
        (currentDay === 3 && currentHour < 7)
    ) {
        orderRestrictionDate.minDate = upcomingSunday;
        orderRestrictionDate.maxDate = nextWednesday;
    } else {
        orderRestrictionDate.minDate = secondBatchStartDate;
        orderRestrictionDate.maxDate = secondBatchEndDate;
    }
};

const computeOverallTotal = computed(() => {
    // Format Overall Total with commas
    return orderForm.orders
        .reduce((total, order) => total + parseFloat(order.total_cost), 0)
        .toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
});

watch(
    () => orderForm.supplier_id,
    async (supplierCode) => { // supplierCode is now the string code
        // Only reset order_date if the component is mounted and ready,
        // preventing it from nullifying the initial date from props.
        if (isMountedAndReady.value) { 
            orderForm.order_date = null; 
        }
        productId.value = null;
        Object.keys(productDetails).forEach((key) => {
            productDetails[key] = null;
        });

        availableProductsOptions.value = [];

        if (!supplierCode) {
            return;
        }

        try {
            isLoading.value = true;
            // CRITICAL FIX: Fetch items by supplier code, and ensure the options
            // value is the ItemCode string of the supplier item.
            const response = await axios.get(route('store-orders.get-supplier-items', supplierCode));
            // Directly assign the response data, as the backend already formats it to { value, label }
            availableProductsOptions.value = response.data.items; 
            isLoading.value = false;

        } catch (error) {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "Failed to load items for the selected supplier.",
                life: 5000,
            });
            isLoading.value = false;
        }

        const selectedSupplier = suppliersOptions.value.find(
            (option) => option.value === supplierCode // Match by supplierCode (string)
        );

        if (selectedSupplier) {
            if (
                selectedSupplier.label === "GSI OT-BAKERY" ||
                selectedSupplier.label === "GSI OT-PR"
            ) {
                calculateGSIOrderDate();
            } else if (selectedSupplier.label === "PUL OT-DG") {
                calculatePULILANOrderDate();
            }
        }
    },
    { immediate: true }
);

const isSupplierSelected = computed(() => {
    return orderForm.supplier_id !== null && orderForm.supplier_id !== '';
});

// NEW: Computed property to determine if dropdowns should be disabled
const shouldLockDropdowns = computed(() => {
    return orderForm.orders.length > 0;
});

// NEW: Computed property to control the visibility of the "Import Orders" button
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
    // The composable's editOrderQuantity is no longer directly called here for array modification,
    // as that logic is now handled in handleEditQuantityConfirm for direct reactivity.
    // We keep it if it has other side effects like showing toasts or closing its internal modal state.
    editOrderQuantity: composableEditOrderQuantity, 
} = useEditQuantity(orderForm, editableOrderItems, props.order); // CRITICAL FIX: Pass editableOrderItems ref here

// New function to handle the confirm button click in the edit quantity modal
const handleEditQuantityConfirm = () => {
    // Find the item in orderForm.orders to update its quantity
    // The 'id' in orderForm.orders for existing items is StoreOrderItem.id (numeric)
    // The 'id' in orderForm.orders for new items is null
    // The 'formQuantity.id' passed to the modal is 'order.id' from the table,
    // which is StoreOrderItem.id for existing items.
    // So, we should find by StoreOrderItem.id.
    const itemIndex = orderForm.orders.findIndex(item => item.id === formQuantity.id);

    if (itemIndex !== -1) {
        const newQuantity = Number(formQuantity.quantity);
        const currentItem = orderForm.orders[itemIndex];

        // Basic validation for quantity
        if (isNaN(newQuantity) || newQuantity <= 0) {
            formQuantity.errors.quantity = "Quantity must be a positive number.";
            toast.add({ severity: "error", summary: "Validation Error", detail: "Quantity must be a positive number.", life: 3000 });
            return;
        }

        // Ensure cost is a number before calculation to prevent NaN
        const itemCost = Number(currentItem.cost);
        if (isNaN(itemCost)) {
            toast.add({ severity: "error", summary: "Calculation Error", detail: "Item cost is invalid. Cannot update total cost.", life: 3000 });
            return;
        }

        // Determine the effective BaseQTY for calculation. Default to 1 if not available or <= 0.
        const effectiveBaseQty = Number(currentItem.base_qty) > 0 ? Number(currentItem.base_qty) : 1;

        currentItem.quantity = parseFloat(newQuantity.toFixed(2));
        currentItem.base_uom_qty = parseFloat((newQuantity * effectiveBaseQty).toFixed(2)); // NEW: Recalculate BaseUOM Qty
        currentItem.total_cost = parseFloat(
            currentItem.base_uom_qty * itemCost // Use base_uom_qty for total cost calculation
        ).toFixed(2);

        orderForm.orders = [...orderForm.orders];

        toast.add({ severity: "success", summary: "Success", detail: "Quantity Updated.", life: 3000 });
        isEditQuantityModalOpen.value = false; // Close the modal
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
    skippedImportMessages.value = []; // Clear previous skipped messages when opening modal
};

// New function to handle downloading the dynamic template
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
        // Use axios to make a GET request to the new backend route
        const response = await axios.get(route('store-orders.download-supplier-order-template', { supplierCode: supplierCode }), {
            responseType: 'blob', // Important: responseType must be 'blob' to handle file download
        });

        // Create a blob URL and trigger download
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        // Dynamically set the filename based on the supplier code
        link.setAttribute('download', `supplier_order_template_${supplierCode}.xlsx`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url); // Clean up the URL object

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
    skippedImportMessages.value = []; // Clear messages before new import attempt
    const formData = new FormData();
    formData.append("orders_file", excelFileForm.orders_file);
    // Append the currently selected supplier_id to the form data
    formData.append("supplier_id", orderForm.supplier_id); 

    axios
        .post(route("store-orders.imported-file"), formData, {
            headers: {
                "Content-Type": "multipart/form-data",
            },
        })
        .then((response) => {
            response.data.orders.forEach((importedOrder) => {
                // Normalize keys from backend response for easier access
                const itemCodeString = importedOrder.item_code || importedOrder.ItemCode || importedOrder.inventory_code;
                const itemName = importedOrder.item_name || importedOrder.ItemName || importedOrder.name;
                
                // CRITICAL FIX: For imported items, we need the ItemCode string for 'id'
                const importedItemId = itemCodeString; 

                // CRITICAL DEBUGGING: Log the value before Number() conversion and after
                const rawQuantityValue = importedOrder.qty || importedOrder.Qty || importedOrder.quantity;
                const quantity = Number(rawQuantityValue);

                const cost = Number(importedOrder.cost || importedOrder.Cost);
                const unit = importedOrder.unit || importedOrder.UOM || importedOrder.unit_of_measurement;
                const baseQty = Number(importedOrder.base_qty || importedOrder.BaseQTY); // NEW: Get BaseQTY from imported data

                // Validate cost for imported items
                if (isNaN(cost) || cost === 0) {
                    toast.add({
                        severity: "error",
                        summary: "Validation Error",
                        detail: `Imported item '${itemName || itemCodeString || 'Unknown Item'}' has a cost of zero or is invalid and will be skipped.`,
                        life: 7000,
                    });
                    return; // Skip this item
                }

                // Validate quantity for imported items - REMOVED TOAST MESSAGE
                if (isNaN(quantity) || quantity < 0.1) { 
                    // The item will still be skipped, but no toast message will be displayed.
                    return; // Skip this item
                }
                
                // NEW: Validate baseQty for imported items
                if (isNaN(baseQty) || baseQty <= 0) {
                    toast.add({
                        severity: "error",
                        summary: "Validation Error",
                        detail: `Imported item '${itemName || itemCodeString || 'Unknown Item'}' has an invalid BaseQTY and will be skipped. BaseQTY must be a positive number.`,
                        life: 7000,
                    });
                    return; // Skip this item
                }

                // Calculate BaseUoM Qty for imported item
                const importedBaseUomQty = parseFloat((quantity * baseQty).toFixed(2));
                // Calculate Total Cost for imported item
                const importedTotalCost = parseFloat((importedBaseUomQty * cost).toFixed(2));

                // CRITICAL FIX: Find by ItemCode string (which is now in item.id)
                const existingItemIndex = orderForm.orders.findIndex(
                    (order) => order.inventory_code === itemCodeString
                );

                if (existingItemIndex !== -1) {
                    const updatedQuantity =
                        Number(orderForm.orders[existingItemIndex].quantity) + quantity;
                    const updatedBaseUomQty = parseFloat((updatedQuantity * baseQty).toFixed(2)); // Recalculate BaseUoM Qty
                    
                    orderForm.orders[existingItemIndex].quantity = updatedQuantity;
                    orderForm.orders[existingItemIndex].base_uom_qty = updatedBaseUomQty; // NEW: Update BaseUoM Qty
                    orderForm.orders[existingItemIndex].total_cost = parseFloat(
                        updatedBaseUomQty * cost // NEW: Total Cost = BaseUoM Qty * Cost
                    ).toFixed(2);
                } else {
                    const newItem = {
                        id: null, // Explicitly set 'id' to null for imported items
                        inventory_code: String(itemCodeString), // This is now the ItemCode string - ensure it's a string
                        name: itemName, 
                        unit_of_measurement: unit, 
                        base_uom: importedOrder.base_uom || null, 
                        base_qty: baseQty, // NEW: Add BaseQTY
                        base_uom_qty: importedBaseUomQty, // NEW: Add calculated BaseUoM Qty
                        quantity: parseFloat(quantity.toFixed(2)), // Ensure quantity is number and formatted
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

            // NEW: Display skipped items as persistent messages
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
                detail: error.response.data.message || "An error occured while trying to get the imported orders. Please make sure that you are using the correct format.",
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
        <!-- NEW: Display area for skipped import messages -->
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
                            <FormError>{{
                                orderForm.errors.supplier_id
                            }}</FormError>
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
                            <FormError>{{
                                orderForm.errors.branch_id
                            }}</FormError>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <InputLabel label="Order Date" />
                            <DatePicker
                                showIcon
                                fluid
                                dateFormat="yy/mm/dd"
                                v-model="datePickerDate"
                                :showOnFocus="false"
                                :manualInput="true"
                                :minDate="orderRestrictionDate.minDate"
                                :maxDate="orderRestrictionDate.maxDate"
                                :disabled="shouldLockDropdowns"
                            />
                            <FormError>{{
                                orderForm.errors.order_date
                            }}</FormError>
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
                            <FormError>{{
                                itemForm.errors.quantity
                            }}</FormError>
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
                            <!-- Delete All Button -->
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
                                    {{ order.base_uom_qty }} <!-- NEW COLUMN DATA -->
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
                                        class="text-red-500 size-5"
                                        @click="minusItemQuantity(order.id)"
                                    >
                                        <Minus />
                                    </button>
                                    <button
                                        class="text-green-500 size-5"
                                        @click="addItemQuantity(order.id)"
                                    >
                                        <Plus />
                                    </button>
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
                                    @click="minusItemQuantity(order.id)"
                                >
                                    <Minus />
                                </button>
                                <button
                                    class="text-green-500 size-5"
                                    @click="addItemQuantity(order.id)"
                                >
                                    <Plus />
                                </button>
                                <button
                                    @click="removeItem(order.id)"
                                    variant="outline"
                                    class="text-red-500 size-5"
                                >
                                    <Trash2 />
                                </button>
                            </MobileTableHeading>
                            <LabelXS
                                >UOM: {{ order.unit_of_measurement }}</LabelXS
                            >
                            <LabelXS>Ordered Qty: {{ order.quantity }}</LabelXS>
                            <LabelXS>BaseUOM Qty: {{ order.base_uom_qty }}</LabelXS> <!-- NEW MOBILE LABEL -->
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

                <CardFooter class="flex justify-end gap-3">
                    <Button
                        variant="outline"
                        class="text-lg px-7"
                        @click="backButton"
                    >
                        Back
                    </Button>
                    <Button
                        :disabled="orderForm.processing"
                        @click="update"
                        class="gap-2"
                    >
                        Update Order
                        <span v-if="orderForm.processing"><Loading /></span>
                    </Button>
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
                        <FormError>{{
                            excelFileForm.errors.orders_file
                        }}</FormError>
                    </div>
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs">Accepted Orders File Format</Label>
                        <ul>
                            <li class="text-xs">
                                <!-- Updated to call the new dynamic download function -->
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
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Edit Quantity</DialogTitle>
                    <DialogDescription>
                        Please input the new quantity.
                    </DialogDescription>
                </DialogHeader>
                <InputContainer>
                    <LabelXS>Quantity</LabelXS>
                    <Input type="number" v-model="formQuantity.quantity" />
                    <FormError>{{ formQuantity.errors.quantity }}</FormError>
                </InputContainer>

                <DialogFooter>
                    <Button
                        @click="handleEditQuantityConfirm"
                        :disabled="isLoading"
                        type="submit"
                        class="gap-2"
                    >
                        Confirm
                        <span><Loading v-if="isLoading" /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
