<script setup>
import { ref, reactive, computed, watch, onBeforeMount } from 'vue';
import Select from "primevue/select";
import DatePicker from "primevue/datepicker";
import axios from 'axios'; // Import axios for API calls

import { useSelectOptions } from "@/Composables/useSelectOptions";

import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";

// Lucide icons for table actions
import { Trash2, Minus, Plus } from "lucide-vue-next"; 

const confirm = useConfirm();
const { toast } = useToast();

const drafts = ref(null);
onBeforeMount(() => {
    const previousData = localStorage.getItem("storeStoreOrderDraft");
    if (previousData) {
        drafts.value = JSON.parse(previousData);
    }
});

const props = defineProps({
    branches: {
        type: Object,
        required: true,
    },
    suppliers: {
        type: Object,
        required: true,
    },
    previousOrder: {
        type: Object,
        required: false,
    },
});

const previousOrder = props.previousOrder;

const { options: branchesOptions } = useSelectOptions(props.branches);

// availableProductsOptions will now be directly populated from API
const availableProductsOptions = ref([]);

// suppliersOptions will now directly map supplier_code to value and name to label
const { options: suppliersOptions } = useSelectOptions(props.suppliers);

import { useForm } from "@inertiajs/vue3";

const productId = ref(null); // This will now hold the ItemCode of the selected SupplierItem
const visible = ref(false);
const isLoading = ref(false);

watch(visible, (newValue) => {
    if (!newValue) {
        excelFileForm.reset();
        excelFileForm.clearErrors();
    }
});

const productDetails = reactive({
    id: null, // This will be the ItemCode (string) that gets stored in item_code column
    inventory_code: null, // This will be the ItemCode (string)
    name: null, // This will be the item_name
    unit_of_measurement: null, // This will be the uom
    base_uom: null, // From sap_masterfile (BaseUOM)
    base_qty: null, // Needed for 'Add Item' calculation
    quantity: null,
    cost: null,
    total_cost: null,
    uom: null,
});

const excelFileForm = useForm({
    orders_file: null,
});

const orderForm = useForm({
    branch_id: previousOrder?.store_branch_id ? previousOrder.store_branch_id + "" : null,
    supplier_id: previousOrder?.supplier_id ? previousOrder.supplier_id + "" : null, // This will now hold supplier_code (string)
    order_date: null,
    orders: [],
});

const computeOverallTotal = computed(() => {
    // Format Overall Total with commas
    return orderForm.orders
        .reduce((total, order) => total + parseFloat(order.total_cost), 0)
        .toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
});

const itemForm = useForm({
    item: null, // This will hold the ItemCode when an item is selected in the dropdown
});

const store = () => {
    if (orderForm.orders.length < 1) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Please select at least one item before proceeding.",
            life: 5000,
        });
        return;
    }

    if (!orderForm.order_date) {
        orderForm.setError("order_date", "Order date is required.");
        return;
    }

    const formatDate = (date) => {
        const month = String(date.getMonth() + 1).padStart(2, "0");
        const day = String(date.getDate()).padStart(2, "0");
        const year = date.getFullYear();
        return `${year}-${month}-${day}`;
    };
    orderForm.order_date = formatDate(new Date(orderForm.order_date));

    confirm.require({
        message: "Are you sure you want to place this order?",
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
            orderForm.post(route("store-orders.store"), {
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Order Created Successfully.",
                        life: 5000,
                    });
                    localStorage.removeItem("storeStoreOrderDraft");
                },
                onError: (e) => {
                    // --- IMPORTANT DEBUGGING CHANGE ---
                    console.error("Frontend Error during Place Order:", e); 
                    const errorMessage = e.error || e.message || "Can't place the order."; // Capture more error info
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: errorMessage,
                        life: 5000,
                    });
                },
            });
        },
    });
};

watch(productId, async (itemCode) => {
    if (itemCode) {
        isLoading.value = true;
        itemForm.item = itemCode;

        try {
            const supplierCode = orderForm.supplier_id;

            if (!supplierCode) {
                console.error("Supplier code not found for selected supplier ID:", orderForm.supplier_id);
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
                productDetails.id = result.ItemCode; // Assign ItemCode to productDetails.id
                productDetails.name = result.item_name;
                productDetails.inventory_code = result.ItemCode;
                productDetails.unit_of_measurement = result.uom; // This is the UOM of the selected SupplierItem
                productDetails.cost = Number(result.cost); // Ensure cost is a number
                productDetails.uom = result.uom;

                // --- NEW LOGIC FOR BASE_UOM and BASE_QTY ---
                let foundBaseUom = null;
                let foundBaseQty = 1; // Default to 1 to prevent division by zero or NaN in calculations

                // console.log('--- Debugging BaseQTY Retrieval (New Item) ---'); // Removed
                // console.log('Supplier Item UOM:', result.uom); // Removed
                console.log('Full result.sap_masterfiles:', JSON.parse(JSON.stringify(result.sap_masterfiles || result.sap_masterfile))); // Retained log

                // Assuming result.sap_masterfiles is an array of SAP masterfile entries
                // Each entry might have 'BaseUOM', 'AltUOM', 'BaseQTY'
                if (result.sap_masterfiles && Array.isArray(result.sap_masterfiles)) {
                    // console.log('Processing SAP Masterfiles (array):'); // Removed
                    const matchingSapEntry = result.sap_masterfiles.find(
                        (sapEntry) => {
                            const cleanedAltUOM = sapEntry.AltUOM ? String(sapEntry.AltUOM).trim().toLowerCase() : '';
                            const cleanedResultUOM = result.uom ? String(result.uom).trim().toLowerCase() : '';
                            const isMatch = cleanedAltUOM === cleanedResultUOM;
                            // console.log(`  Comparing SupplierItem UOM '${cleanedResultUOM}' with SAP AltUOM '${cleanedAltUOM}'. Match: ${isMatch}`); // Removed
                            return isMatch;
                        }
                    );

                    if (matchingSapEntry) {
                        foundBaseUom = matchingSapEntry.BaseUOM;
                        foundBaseQty = Number(matchingSapEntry.BaseQTY) || 1; // Ensure it's a number, default to 1
                        // console.log(`  Found direct match. BaseUOM: ${foundBaseUom}, BaseQTY: ${foundBaseQty}`); // Removed
                    } else if (result.sap_masterfiles.length > 0) {
                        // Fallback: If no specific AltUOM match, use the first entry's BaseUOM/BaseQTY
                        // This might be the case if the supplier item's UOM is the BaseUOM itself
                        foundBaseUom = result.sap_masterfiles[0].BaseUOM;
                        foundBaseQty = Number(result.sap_masterfiles[0].BaseQTY) || 1;
                        // console.log(`  No direct AltUOM match. Falling back to first SAP entry. BaseUOM: ${foundBaseUom}, BaseQTY: ${foundBaseQty}`); // Removed
                    }
                } else if (result.sap_masterfile) { // Handle case where it's a single object (not array)
                    // console.log('Processing SAP Masterfile (single object):'); // Removed
                    foundBaseUom = result.sap_masterfile.BaseUOM;
                    foundBaseQty = Number(result.sap_masterfile.BaseQTY) || 1;
                    // console.log(`  Using single SAP entry. BaseUOM: ${foundBaseUom}, BaseQTY: ${foundBaseQty}`); // Removed
                } else {
                    // console.log('No SAP Masterfile data found for this item. Defaulting BaseQTY to 1.'); // Removed
                }

                productDetails.base_uom = foundBaseUom;
                productDetails.base_qty = foundBaseQty;
                // console.log('Final productDetails.base_uom:', productDetails.base_uom); // Removed
                // console.log('Final productDetails.base_qty:', productDetails.base_qty); // Removed
                // console.log('--- End Debugging BaseQTY Retrieval (New Item) ---'); // Removed
                // --- END NEW LOGIC ---

            } else {
                toast.add({
                    severity: "error",
                    summary: "Error",
                    detail: "Item details not found.",
                    life: 5000,
                });
            }
        } catch (err) {
            console.error("Error fetching supplier item details:", err);
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

const importOrdersButton = () => {
    visible.value = true;
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
    // BaseQTY validation removed as requested. Calculations will use a default of 1 if not available.

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
            detail: "Please ensure all item details are loaded (name, code, UOM, quantity, cost).", // Updated message
            life: 5000,
        });
        return;
    }

    const existingItemIndex = orderForm.orders.findIndex(
        (order) => order.inventory_code === productDetails.inventory_code
    );

    // Determine the effective BaseQTY for calculation. Default to 1 if not available or <= 0.
    const effectiveBaseQtyForNewItem = Number(productDetails.base_qty) > 0 ? Number(productDetails.base_qty) : 1;
    const currentQuantity = Number(productDetails.quantity);
    const currentCost = Number(productDetails.cost);

    // console.log('--- Debugging Add to Orders Button ---'); // Removed
    // console.log('productDetails.quantity:', currentQuantity); // Removed
    // console.log('productDetails.base_qty (effective):', effectiveBaseQtyForNewItem); // Removed
    // console.log('productDetails.cost:', currentCost); // Removed

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

        // console.log('  Updated existing item. New Quantity:', newTotalQuantity, 'New BaseUOM Qty:', newBaseUomQty, 'New Total Cost:', newTotalCost); // Removed

    } else {
        // Add new item
        productDetails.base_uom_qty = parseFloat((currentQuantity * effectiveBaseQtyForNewItem).toFixed(2));
        productDetails.total_cost = parseFloat((productDetails.base_uom_qty * currentCost).toFixed(2));

        // console.log('  Adding new item. BaseUOM Qty:', productDetails.base_uom_qty, 'Total Cost:', productDetails.total_cost); // Removed

        orderForm.orders.push({ ...productDetails, id: productDetails.inventory_code });
    }
    // console.log('--- End Debugging Add to Orders Button ---'); // Removed

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

const addImportedItemsToOrderList = () => {
    isLoading.value = true;
    const formData = new FormData();
    formData.append("orders_file", excelFileForm.orders_file);

    axios
        .post(route("store-orders.imported-file"), formData, {
            headers: {
                "Content-Type": "multipart/form-data",
            },
        })
        .then((response) => {
            console.log('Backend response.data.orders:', response.data.orders); // Log the entire array
            response.data.orders.forEach((importedOrder) => {
                console.log('--- Processing individual importedOrder from backend (Excel import) ---');
                console.log('Raw importedOrder object:', importedOrder); // Show the raw object for each item

                // Normalize keys from backend response for easier access
                const itemCode = importedOrder.item_code || importedOrder.ItemCode || importedOrder.inventory_code;
                const itemName = importedOrder.item_name || importedOrder.ItemName || importedOrder.name;
                
                // CRITICAL DEBUGGING: Log the value before Number() conversion and after
                const rawQuantityValue = importedOrder.qty || importedOrder.Qty || importedOrder.quantity;
                const quantity = Number(rawQuantityValue);

                console.log(`Extracted values for ${itemCode || 'Unknown Item'}:`);
                console.log(`   - Raw quantity value from backend (before Number() conversion):`, rawQuantityValue);
                console.log(`   - Converted quantity:`, quantity);

                const cost = Number(importedOrder.cost || importedOrder.Cost);
                console.log(`   - Converted cost:`, cost);

                const baseQty = Number(importedOrder.base_qty || importedOrder.BaseQTY); // Keep: Get BaseQTY from imported data for calculation
                console.log(`   - Converted baseQty:`, baseQty);


                const unit = importedOrder.unit || importedOrder.UOM || importedOrder.unit_of_measurement;
                

                // Validate cost for imported items
                if (isNaN(cost) || cost === 0) {
                    toast.add({
                        severity: "error",
                        summary: "Validation Error",
                        detail: `Imported item '${itemName || itemCode || 'Unknown Item'}' has a cost of zero or is invalid and will be skipped.`,
                        life: 7000,
                    });
                    return; // Skip this item
                }

                // Validate quantity for imported items
                if (isNaN(quantity) || quantity < 0.1) {
                    toast.add({
                        severity: "error",
                        summary: "Validation Error",
                        detail: `Imported item '${itemName || itemCode || 'Unknown Item'}' has an invalid quantity and will be skipped. Quantity must be at least 0.1.`,
                        life: 7000,
                    });
                    return; // Skip this item
                }

                // NEW: Validate baseQty for imported items (still needed for imported calculation)
                if (isNaN(baseQty) || baseQty <= 0) {
                    toast.add({
                        severity: "error",
                        summary: "Validation Error",
                        detail: `Imported item '${itemName || itemCode || 'Unknown Item'}' has an invalid BaseQTY and will be skipped. BaseQTY must be a positive number.`,
                        life: 7000,
                    });
                    return; // Skip this item
                }

                // Calculate BaseUoM Qty for imported item
                const importedBaseUomQty = parseFloat((quantity * baseQty).toFixed(2));
                console.log(`   - Calculated importedBaseUomQty:`, importedBaseUomQty);

                // Calculate Total Cost for imported item
                const importedTotalCost = parseFloat((importedBaseUomQty * cost).toFixed(2));
                console.log(`   - Calculated importedTotalCost:`, importedTotalCost);


                const existingItemIndex = orderForm.orders.findIndex(
                    (order) => order.inventory_code === itemCode
                );

                if (existingItemIndex !== -1) {
                    const updatedQuantity =
                        orderForm.orders[existingItemIndex].quantity + quantity;
                    const updatedBaseUomQty = parseFloat((updatedQuantity * baseQty).toFixed(2)); // Recalculate BaseUoM Qty
                    
                    orderForm.orders[existingItemIndex].quantity = updatedQuantity;
                    orderForm.orders[existingItemIndex].base_uom_qty = updatedBaseUomQty; // NEW: Update BaseUoM Qty
                    orderForm.orders[existingItemIndex].total_cost = parseFloat(
                        updatedBaseUomQty * cost // NEW: Total Cost = BaseUoM Qty * Cost
                    ).toFixed(2);
                    console.log(`   - Updated existing item in orderForm.orders:`, orderForm.orders[existingItemIndex]);
                } else {
                    // CRITICAL FIX: Ensure the 'id' property of the imported order is ItemCode
                    const newItem = {
                        id: itemCode, 
                        inventory_code: itemCode, 
                        name: itemName, 
                        unit_of_measurement: unit, 
                        base_uom: importedOrder.base_uom || null, // Assuming base_uom might come from backend
                        base_qty: baseQty, // NEW: Add BaseQTY
                        base_uom_qty: importedBaseUomQty, // NEW: Add calculated BaseUoM Qty
                        quantity: parseFloat(quantity.toFixed(2)), // Ensure quantity is number and formatted
                        cost: cost, 
                        uom: unit, 
                        total_cost: importedTotalCost, // NEW: Use calculated total cost
                    };
                    orderForm.orders.push(newItem);
                    console.log(`   - Added new item to orderForm.orders:`, newItem);
                }
                console.log('--- End Processing individual importedOrder ---');
            });

            visible.value = false;
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Items added successfully.",
                life: 5000,
            });
            excelFileForm.orders_file = null;
        })
        .catch((error) => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occured while trying to get the imported orders. Please make sure that you are using the correct format.",
                life: 5000,
            });
            excelFileForm.setError("orders_file", error.response.data.message || "Unknown error during import.");
            console.error("Error during import:", error); // Use console.error for errors
        })
        .finally(() => (isLoading.value = false));
};

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

watch(
    () => orderForm.supplier_id,
    async (supplierCode) => {
        orderForm.order_date = null;
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
            const response = await axios.get(route('store-orders.get-supplier-items', supplierCode));
            availableProductsOptions.value = response.data.items;
            isLoading.value = false;

        } catch (error) {
            console.error("Error fetching supplier items:", error);
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "Failed to load items for the selected supplier.",
                life: 5000,
            });
            isLoading.value = false;
        }

        const selectedSupplier = suppliersOptions.value.find(
            (option) => option.value === supplierCode
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


if (previousOrder) {
    previousOrder.store_order_items.forEach((item) => {
        console.log("Existing Ordered Item:", item);
        
        let baseQty = 1; // Default to 1
        let baseUom = null;

        console.log('Full item.supplier_item.sap_masterfiles:', JSON.parse(JSON.stringify(item.supplier_item.sap_masterfiles || item.supplier_item.sap_masterfile))); // Log the full SAP data

        // Assuming item.supplier_item.sap_masterfiles is an array
        if (item.supplier_item.sap_masterfiles && Array.isArray(item.supplier_item.sap_masterfiles)) {
            const matchingSapEntry = item.supplier_item.sap_masterfiles.find(
                (sapEntry) => {
                    const cleanedAltUOM = sapEntry.AltUOM ? String(sapEntry.AltUOM).trim().toLowerCase() : '';
                    const cleanedItemUOM = item.supplier_item.uom ? String(item.supplier_item.uom).trim().toLowerCase() : '';
                    const isMatch = cleanedAltUOM === cleanedItemUOM;
                    return isMatch;
                }
            );
            if (matchingSapEntry) {
                baseQty = Number(matchingSapEntry.BaseQTY) || 1;
                baseUom = matchingSapEntry.BaseUOM;
            } else if (item.supplier_item.sap_masterfiles.length > 0) {
                // Fallback to first entry if no specific AltUOM match
                baseQty = Number(item.supplier_item.sap_masterfiles[0].BaseQTY) || 1;
                baseUom = item.supplier_item.sap_masterfiles[0].BaseUOM;
            }
        } else if (item.supplier_item.sap_masterfile) { // Handle single object case
             baseQty = Number(item.supplier_item.sap_masterfile.BaseQTY) || 1;
             baseUom = item.supplier_item.sap_masterfile.BaseUOM;
        } else {
            // console.log('No SAP Masterfile data found for this previous order item. Defaulting BaseQTY to 1.'); // Removed
        }

        const quantityOrdered = Number(item.quantity_ordered);
        const itemCost = Number(item.supplier_item.cost);

        const calculatedBaseUomQty = parseFloat((quantityOrdered * baseQty).toFixed(2));
        const calculatedTotalCost = parseFloat((calculatedBaseUomQty * itemCost).toFixed(2));

        const product = {
            id: item.supplier_item.ItemCode, // Set id to ItemCode
            inventory_code: item.supplier_item.ItemCode,
            name: item.supplier_item.item_name,
            unit_of_measurement: item.supplier_item.uom,
            base_uom: baseUom, // Use the determined BaseUOM
            base_qty: baseQty, // Use the determined BaseQTY
            base_uom_qty: calculatedBaseUomQty,
            quantity: quantityOrdered,
            cost: itemCost,
            total_cost: calculatedTotalCost,
            uom: item.supplier_item.uom,
        };
        orderForm.orders.push(product);
        // console.log('Final product for previous order item:', product); // Removed
        // console.log('--- End Debugging BaseQTY Retrieval (Previous Order Item) ---'); // Removed
    });
}

import { useEditQuantity } from "@/Composables/useEditQuantity";
const {
    isEditQuantityModalOpen,
    formQuantity,
    openEditQuantityModal,
    // editQuantity is the function we need to modify
} = useEditQuantity(orderForm);

// Manually define editQuantity to include recalculation logic
const editQuantity = () => {
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
        currentItem.base_uom_qty = parseFloat((newQuantity * effectiveBaseQty).toFixed(2)); // Recalculate BaseUOM Qty
        currentItem.total_cost = parseFloat(
            currentItem.base_uom_qty * itemCost // Use base_uom_qty for total cost calculation
        ).toFixed(2);

        // Ensure reactivity by replacing the array or updating it immutably
        orderForm.orders = [...orderForm.orders];

        toast.add({ severity: "success", summary: "Success", detail: "Quantity Updated.", life: 3000 });
        isEditQuantityModalOpen.value = false; // Close the modal
    } else {
        toast.add({ severity: "error", summary: "Error", detail: "Item not found in order list.", life: 3000 });
    }
};


watch(orderForm, (value) => {
    localStorage.setItem("storeStoreOrderDraft", JSON.stringify(value));
    // console.log('Draft saved to localStorage:', JSON.stringify(value)); // Removed
}, { deep: true });
</script>

<template>
    <Layout
        heading="Store Order > Create"
        :hasButton="true"
        buttonName="Import Orders"
        :handleClick="importOrdersButton"
    >
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
                                v-model="orderForm.order_date"
                                :showOnFocus="false"
                                :manualInput="true"
                                :minDate="orderRestrictionDate.minDate"
                                :maxDate="orderRestrictionDate.maxDate"
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
                        </DivFlexCenter>
                    </DivFlexCenter>
                </CardHeader>
                <CardContent class="flex-1">
                    <Table>
                        <TableHead>
                            <TH> Name </TH>
                            <TH> Code </TH>
                            <TH> Quantity </TH>
                            <TH> Base UOM </TH>
                            <TH> BaseUOM Qty </TH> <!-- NEW COLUMN HEADER -->
                            <TH> Unit </TH>
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
                                    {{ order.base_uom }}
                                </TD>
                                <TD>
                                    {{ order.base_uom_qty }} <!-- NEW COLUMN DATA -->
                                </TD>
                                <TD>
                                    {{ order.unit_of_measurement }}
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
                                        class="text-red-500"
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
                            <LabelXS>Quantity: {{ order.quantity }}</LabelXS>
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

                <CardFooter class="flex justify-end">
                    <Button @click="store">Place Order</Button>
                </CardFooter>
            </Card>
        </div>

        <Dialog v-model:open="visible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Import Orders</DialogTitle>
                    <DialogDescription
                        >Upload an Excel file to import orders.</DialogDescription
                    >
                </DialogHeader>
                <div class="grid gap-4 py-4">
                    <InputContainer>
                        <Label for="orders_file">Excel File</Label>
                        <Input
                            id="orders"
                            type="file"
                            @change="
                                excelFileForm.orders_file = $event.target.files[0]
                            "
                        />
                        <FormError>{{
                            excelFileForm.errors.orders_file
                        }}</FormError>
                    </InputContainer>
                </div>
                <DialogFooter>
                    <Button variant="ghost" @click="visible = false"
                        >Cancel</Button
                    >
                    <Button @click="addImportedItemsToOrderList" :disabled="isLoading">
                        <div v-if="isLoading" class="flex items-center">
                            <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Importing...
                        </div>
                        <span v-else>Import</span>
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
