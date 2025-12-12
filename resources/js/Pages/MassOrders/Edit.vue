<script setup>
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import { ref, reactive, computed, watch, onMounted, onBeforeMount, onUnmounted } from 'vue';
import Select from "primevue/select";
import axios from 'axios';
import { Calendar as CalendarIcon } from 'lucide-vue-next';

import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useForm, router } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { useBackButton } from "@/Composables/useBackButton";
const { backButton } = useBackButton(route("mass-orders.index"));

// Custom styles for dropdown positioning and z-index
const customDropdownStyles = `
    /* Force override PrimeVue dropdown styles */
    .p-dropdown-panel {
        z-index: 99999 !important;
        max-height: 200px !important;
        overflow-y: auto !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
        border-radius: 6px !important;
    }

    /* Force all dropdown variants to use proper positioning */
    .p-dropdown-panel[style*="position: absolute"] {
        position: fixed !important;
        top: auto !important;
        bottom: auto !important;
        left: auto !important;
        right: auto !important;
    }

    /* Small desktop and tablet landscape - force positioning */
    @media (max-width: 1024px) {
        .p-dropdown-panel {
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            max-height: 180px !important;
            min-width: 250px !important;
            width: auto !important;
            max-width: 90vw !important;
        }
    }

    /* Tablet portrait - force center positioning */
    @media (max-width: 768px) {
        .p-dropdown-panel {
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            width: 85vw !important;
            max-width: 320px !important;
            max-height: 50vh !important;
            min-width: 280px !important;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3) !important;
            border-radius: 8px !important;
        }

        /* Override inline styles that PrimeVue might set */
        .p-dropdown-panel[style] {
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            width: 85vw !important;
            max-width: 320px !important;
            max-height: 50vh !important;
            min-width: 280px !important;
        }
    }

    /* Large mobile - force positioning */
    @media (max-width: 640px) {
        .p-dropdown-panel {
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            width: 90vw !important;
            max-width: 300px !important;
            max-height: 55vh !important;
            min-width: 260px !important;
        }

        /* Override inline styles */
        .p-dropdown-panel[style] {
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            width: 90vw !important;
            max-width: 300px !important;
            max-height: 55vh !important;
            min-width: 260px !important;
        }
    }

    /* Small mobile - force positioning */
    @media (max-width: 480px) {
        .p-dropdown-panel {
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            width: 95vw !important;
            max-width: 280px !important;
            max-height: 60vh !important;
            min-width: 240px !important;
        }

        /* Override inline styles */
        .p-dropdown-panel[style] {
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            width: 95vw !important;
            max-width: 280px !important;
            max-height: 60vh !important;
            min-width: 240px !important;
        }
    }

    /* Extra small mobile - force positioning */
    @media (max-width: 360px) {
        .p-dropdown-panel {
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            width: 98vw !important;
            max-width: 260px !important;
            max-height: 65vh !important;
            min-width: 220px !important;
        }

        /* Override inline styles */
        .p-dropdown-panel[style] {
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            width: 98vw !important;
            max-width: 260px !important;
            max-height: 65vh !important;
            min-width: 220px !important;
        }
    }

    /* Ensure dropdown items are properly styled */
    .p-dropdown-items-wrapper {
        overflow-y: auto !important;
        max-height: inherit !important;
    }

    .p-dropdown-item {
        padding: 0.75rem 1rem !important;
        font-size: 0.875rem !important;
        line-height: 1.25rem !important;
    }

    @media (max-width: 640px) {
        .p-dropdown-item {
            padding: 1rem !important;
            font-size: 0.9rem !important;
        }
    }

    /* Fix dropdown container positioning */
    .p-dropdown {
        position: relative !important;
    }

    /* Prevent body scroll when dropdown is open on mobile */
    @media (max-width: 768px) {
        body.p-overflow-hidden {
            touch-action: none;
            -webkit-overflow-scrolling: touch;
        }
    }

    /* Ensure any dropdown that appears gets forced to center */
    .p-dropdown-panel:not(.p-hidden-accessible) {
        position: fixed !important;
        top: 50% !important;
        left: 50% !important;
        transform: translate(-50%, -50%) !important;
    }
`;

// Lucide icons for table actions - Minus and Plus removed
import { Trash2, ChevronDown, ChevronLeft, ChevronRight, Download, Upload } from "lucide-vue-next";

// UI State Management
const openSections = ref({
    orderInfo: true,
    items: true,
    addItem: true
});

const toggleSection = (section) => {
    openSections.value[section] = !openSections.value[section];
};

// Search and Pagination for Items
const searchTerm = ref('');
const currentPage = ref(1);
const itemsPerPage = ref(200);

const filteredItems = computed(() => {
    if (!searchTerm.value) {
        return editableOrderItems.value;
    }

    const term = searchTerm.value.toLowerCase();
    return editableOrderItems.value.filter(item =>
        item.name.toLowerCase().includes(term) ||
        item.inventory_code.toLowerCase().includes(term) ||
        item.unit_of_measurement.toLowerCase().includes(term)
    );
});

const totalPages = computed(() => {
    return Math.ceil(filteredItems.value.length / itemsPerPage.value);
});

const paginatedItems = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage.value;
    const end = start + itemsPerPage.value;
    return filteredItems.value.slice(start, end);
});

const visiblePages = computed(() => {
    const total = totalPages.value;
    const current = currentPage.value;
    const delta = 2;
    const range = [];

    for (let i = Math.max(2, current - delta); i <= Math.min(total - 1, current + delta); i++) {
        range.push(i);
    }

    if (current - delta > 2) {
        range.unshift('...');
    }
    if (current + delta < total - 1) {
        range.push('...');
    }

    if (total > 1) {
        range.unshift(1);
    }
    if (total > 1 && total !== 1) {
        range.push(total);
    }

    return range.filter(page => page !== '...' || range.indexOf(page) === range.lastIndexOf(page));
});

// Update order item
const updateOrder = (item) => {
    // Update the original order in orderForm.orders
    const index = orderForm.orders.findIndex(order => order.inventory_code === item.inventory_code);
    if (index !== -1) {
        orderForm.orders[index] = { ...item };

        // Also update the same item in editableOrderItems to keep them synchronized
        const editableIndex = editableOrderItems.value.findIndex(order => order.inventory_code === item.inventory_code);
        if (editableIndex !== -1) {
            editableOrderItems.value[editableIndex] = { ...item };
        }
    }
};

// Import functions - these will be assigned after the original functions are defined
let downloadTemplate = () => {
    console.log('Download template function not yet assigned');
};

let openFileDialog = () => {
    console.log('Open file dialog function not yet assigned');
};

const importExcelFile = (event) => {
    const file = event.target.files[0];
    if (file) {
        excelFileForm.orders_file = file;
        addImportedItemsToOrderList();
    }
};

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
    canViewCost: {
        type: Boolean,
        default: false,
    }
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
    // Inject custom styles for dropdowns
    const styleElement = document.createElement('style');
    styleElement.textContent = customDropdownStyles;
    styleElement.id = 'mass-orders-dropdown-fixes';
    document.head.appendChild(styleElement);

    // Add MutationObserver to override PrimeVue dropdown positioning
    const dropdownObserver = new MutationObserver((mutations) => {
        const dropdownPanels = document.querySelectorAll('.p-dropdown-panel');
        dropdownPanels.forEach(panel => {
            const rect = panel.getBoundingClientRect();
            const viewportWidth = window.innerWidth;

            // Force center positioning on screens smaller than 1024px
            if (viewportWidth <= 1024) {
                panel.style.setProperty('position', 'fixed', 'important');
                panel.style.setProperty('top', '50%', 'important');
                panel.style.setProperty('left', '50%', 'important');
                panel.style.setProperty('transform', 'translate(-50%, -50%)', 'important');
                panel.style.setProperty('z-index', '99999', 'important');

                // Apply responsive width based on screen size
                if (viewportWidth <= 768) {
                    panel.style.setProperty('width', '85vw', 'important');
                    panel.style.setProperty('max-width', '320px', 'important');
                    panel.style.setProperty('max-height', '50vh', 'important');
                } else if (viewportWidth <= 640) {
                    panel.style.setProperty('width', '90vw', 'important');
                    panel.style.setProperty('max-width', '300px', 'important');
                    panel.style.setProperty('max-height', '55vh', 'important');
                } else if (viewportWidth <= 480) {
                    panel.style.setProperty('width', '95vw', 'important');
                    panel.style.setProperty('max-width', '280px', 'important');
                    panel.style.setProperty('max-height', '60vh', 'important');
                } else {
                    panel.style.setProperty('width', 'auto', 'important');
                    panel.style.setProperty('max-width', '90vw', 'important');
                    panel.style.setProperty('max-height', '180px', 'important');
                }
            }
        });
    });

    // Start observing the entire document for added nodes and attribute changes
    dropdownObserver.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['style', 'class']
    });

    // Store observer for cleanup
    window.massOrdersDropdownObserver = dropdownObserver;

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
            unit_of_measurement: item.uom,
            base_uom: baseUom, // Use the determined BaseUOM
            base_qty: baseQty, // Use the determined BaseQTY
            base_uom_qty: calculatedBaseUomQty,
            quantity: quantityOrdered,
            cost: itemCost,
            total_cost: calculatedTotalCost, // Use calculated total cost
            uom: item.uom,
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
            // Remove from both orderForm.orders and editableOrderItems to keep them synchronized
            orderForm.orders = orderForm.orders.filter((item) => item.id !== id);
            editableOrderItems.value = editableOrderItems.value.filter((item) => item.id !== id);

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
            editableOrderItems.value = []; // Clear the ref as well to keep them synchronized
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

        // Also update the same item in editableOrderItems to keep them synchronized
        const editableItemIndex = editableOrderItems.value.findIndex(item =>
            item.inventory_code === existingItem.inventory_code
        );
        if (editableItemIndex !== -1) {
            editableOrderItems.value[editableItemIndex] = { ...existingItem };
        }

    } else {
        productDetails.base_uom_qty = parseFloat((currentQuantity * effectiveBaseQtyForNewItem).toFixed(2));
        productDetails.total_cost = parseFloat((productDetails.base_uom_qty * currentCost).toFixed(2));

        const newItem = {
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
        };

        orderForm.orders.push(newItem);

        // Also add the item to editableOrderItems to keep them synchronized
        // editableOrderItems.value.push({ ...newItem });
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
    // Try to find the item in orderForm.orders first
    let itemIndex = orderForm.orders.findIndex(item => item.id === formQuantity.id);

    // If not found, try to find by inventory_code in case of ID mismatch
    if (itemIndex === -1) {
        itemIndex = orderForm.orders.findIndex(item =>
            item.inventory_code === formQuantity.inventory_code ||
            item.name === formQuantity.name
        );
    }

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

        // Update the item in orderForm.orders
        currentItem.quantity = parseFloat(newQuantity.toFixed(2));
        currentItem.base_uom_qty = parseFloat((newQuantity * effectiveBaseQty).toFixed(2));
        currentItem.total_cost = parseFloat(
            Number(currentItem.base_uom_qty) * Number(currentItem.cost)
        ).toFixed(2);

        // Create a new array to trigger reactivity
        orderForm.orders = [...orderForm.orders];

        // Also update the same item in editableOrderItems to keep them synchronized
        const editableItemIndex = editableOrderItems.value.findIndex(item =>
            item.id === currentItem.id ||
            item.inventory_code === currentItem.inventory_code
        );

        if (editableItemIndex !== -1) {
            editableOrderItems.value[editableItemIndex] = { ...currentItem };
        }

        toast.add({ severity: "success", summary: "Success", detail: "Quantity Updated.", life: 3000 });
        isEditQuantityModalOpen.value = false;
    } else {
        // Enhanced error message with debugging info
        toast.add({
            severity: "error",
            summary: "Error",
            detail: `Item not found in order list. Looking for ID: ${formQuantity.id}, Inventory Code: ${formQuantity.inventory_code}, Name: ${formQuantity.name}`,
            life: 5000
        });
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

// Assign the proper functions after they are defined
openFileDialog = importOrdersButton;

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

// Assign the proper functions after they are defined
downloadTemplate = downloadDynamicTemplate;


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

            editableOrderItems.value = orderForm.orders;

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

// Cleanup styles and observer when component is unmounted
onUnmounted(() => {
    const styleElement = document.getElementById('mass-orders-dropdown-fixes');
    if (styleElement) {
        document.head.removeChild(styleElement);
    }

    // Disconnect the MutationObserver if it exists
    if (window.massOrdersDropdownObserver) {
        window.massOrdersDropdownObserver.disconnect();
        delete window.massOrdersDropdownObserver;
    }
});

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

        <!-- Accordion-style Form Sections -->
        <div class="space-y-4">
            <!-- Order Information Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <!-- Section Header -->
                <button
                    @click="toggleSection('orderInfo')"
                    class="w-full px-4 sm:px-6 py-4 bg-gradient-to-r from-blue-50 to-blue-100 border-b border-blue-200 flex items-center justify-between text-left hover:from-blue-100 hover:to-blue-200 transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-blue-600 rounded-lg">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">Order Information</h2>
                            <p class="text-sm text-gray-600">Basic order details</p>
                        </div>
                    </div>
                    <ChevronDown
                        :class="['w-5 h-5 text-gray-600 transition-transform duration-200', { 'rotate-180': openSections.orderInfo }]"
                    />
                </button>

                <!-- Section Content -->
                <div v-show="openSections.orderInfo" class="px-4 sm:px-6 py-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Supplier -->
                        <div class="space-y-2">
                            <label class="text-sm font-medium text-gray-700">Supplier</label>
                            <Select
                                filter
                                placeholder="Select a Supplier"
                                v-model="orderForm.supplier_id"
                                :options="suppliersOptions"
                                optionLabel="label"
                                optionValue="value"
                                :disabled="shouldLockDropdowns"
                                class="w-full"
                            />
                            <FormError>{{ orderForm.errors.supplier_id }}</FormError>
                        </div>

                        <!-- Order Date -->
                        <div class="space-y-2">
                            <label class="text-sm font-medium text-gray-700">Order Date</label>
                            <div class="relative" ref="dateInputRef">
                                <div class="relative">
                                    <input
                                        id="order_date"
                                        type="text"
                                        readonly
                                        :value="orderForm.order_date"
                                        @click="showCalendar = !showCalendar"
                                        :disabled="isDatepickerDisabled || shouldLockDropdowns"
                                        class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm transition-colors placeholder:text-gray-500 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-gray-400 disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer"
                                        placeholder="Select a date"
                                    />
                                    <CalendarIcon class="absolute right-3 top-1/2 -translate-y-1/2 size-4 text-gray-500 pointer-events-none" />
                                </div>
                                <div v-show="showCalendar" :class="['absolute z-50 bg-white border border-gray-200 rounded-lg shadow-lg p-4 w-full min-w-[300px]', calendarPositionClass]">
                                    <div class="flex justify-between items-center mb-4">
                                        <button type="button" @click.stop="goToPrevMonth()" class="p-2 rounded-full hover:bg-gray-100">&lt;</button>
                                        <h2 class="text-lg font-semibold">{{ (currentCalendarDate || new Date()).toLocaleString('default', { month: 'long', year: 'numeric' }) }}</h2>
                                        <button type="button" @click.stop="goToNextMonth()" class="p-2 rounded-full hover:bg-gray-100">&gt;</button>
                                    </div>
                                    <div class="grid grid-cols-7 gap-1 text-center text-xs font-medium text-gray-500 mb-2">
                                        <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                                    </div>
                                    <div class="grid grid-cols-7 gap-2">
                                        <template v-for="(day, d_idx) in getCalendarDays()" :key="d_idx">
                                            <div
                                                class="text-center py-1.5 rounded-full text-sm cursor-pointer"
                                                :class="[
                                                    !day ? '' :
                                                    (day.isDisabled ? 'text-gray-300 line-through cursor-not-allowed' :
                                                    (orderForm.order_date && day.date.toDateString() === new Date(orderForm.order_date + 'T00:00:00').toDateString() ?
                                                    'bg-blue-600 text-white font-bold shadow-md' :
                                                    'bg-gray-100 text-gray-800 font-semibold hover:bg-blue-100'))
                                                ]"
                                                @click="selectDate(day)"
                                            >
                                                {{ day ? day.day : '' }}
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <FormError>{{ orderForm.errors.order_date }}</FormError>
                        </div>

                        <!-- Store Branch -->
                        <div class="space-y-2">
                            <label class="text-sm font-medium text-gray-700">Store Branch</label>
                            <Select
                                filter
                                placeholder="Select a Store"
                                v-model="orderForm.branch_id"
                                :options="branchesOptions"
                                optionLabel="label"
                                optionValue="value"
                                :disabled="shouldLockDropdowns"
                                class="w-full"
                            />
                            <FormError>{{ orderForm.errors.branch_id }}</FormError>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add Item Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <!-- Section Header -->
                <button
                    @click="toggleSection('addItem')"
                    class="w-full px-4 sm:px-6 py-4 bg-gradient-to-r from-green-50 to-green-100 border-b border-green-200 flex items-center justify-between text-left hover:from-green-100 hover:to-green-200 transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-green-600 rounded-lg">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Add Item</h3>
                            <p class="text-sm text-gray-600">Add items to your order</p>
                        </div>
                    </div>
                    <ChevronDown
                        :class="['w-5 h-5 text-gray-600 transition-transform duration-200', { 'rotate-180': openSections.addItem }]"
                    />
                </button>

                <!-- Section Content -->
                <div v-show="openSections.addItem" class="px-4 sm:px-6 py-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Item -->
                        <div class="space-y-2">
                            <label class="text-sm font-medium text-gray-700">Item</label>
                            <Select
                                filter
                                placeholder="Select an Item"
                                v-model="productId"
                                :options="availableProductsOptions"
                                optionLabel="label"
                                optionValue="value"
                                :disabled="!isSupplierSelected || isLoading"
                                class="w-full"
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

                        <!-- UOM -->
                        <div class="space-y-2">
                            <label class="text-sm font-medium text-gray-700">UOM</label>
                            <input
                                type="text"
                                disabled
                                v-model="productDetails.unit_of_measurement"
                                class="flex h-10 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm"
                            />
                        </div>

                        <!-- Cost -->
                        <div class="space-y-2" v-if="canViewCost">
                            <label class="text-sm font-medium text-gray-700">Cost</label>
                            <input
                                type="text"
                                disabled
                                v-model="productDetails.cost"
                                class="flex h-10 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm"
                            />
                        </div>

                        <!-- Quantity -->
                        <div class="space-y-2">
                            <label class="text-sm font-medium text-gray-700">Quantity</label>
                            <input
                                type="number"
                                v-model="productDetails.quantity"
                                class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm"
                            />
                            <FormError>{{ itemForm.errors.quantity }}</FormError>
                        </div>
                    </div>

                    <div class="flex justify-end mt-4">
                        <Button @click="addToOrdersButton" :disabled="isLoading">
                            Add to Orders
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Items Management Section -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <!-- Section Header -->
                <button
                    @click="toggleSection('items')"
                    class="w-full px-4 sm:px-6 py-4 bg-gradient-to-r from-purple-50 to-purple-100 border-b border-purple-200 flex items-center justify-between text-left hover:from-purple-100 hover:to-purple-200 transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-purple-600 rounded-lg">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Order Items ({{ editableOrderItems.length }})</h3>
                            <p class="text-sm text-gray-600">Manage your order items and quantities</p>
                        </div>
                    </div>
                    <ChevronDown
                        :class="['w-5 h-5 text-gray-600 transition-transform duration-200', { 'rotate-180': openSections.items }]"
                    />
                </button>

                <!-- Section Content -->
                <div v-show="openSections.items" class="p-4 sm:p-6 space-y-4">
                    <!-- Search and Tools -->
                    <div class="flex flex-col gap-3">
                        <!-- Search Bar -->
                        <div class="relative">
                            <input
                                type="text"
                                v-model="searchTerm"
                                placeholder="Search items by code or name..."
                                class="w-full h-10 pl-10 pr-4 rounded-md border border-gray-300 bg-white text-sm shadow-sm transition-colors placeholder:text-gray-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
                            />
                            <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex flex-wrap gap-2">
                            <Button
                                @click="downloadTemplate"
                                variant="outline"
                                size="sm"
                                class="flex items-center gap-2"
                            >
                                <Download class="w-4 h-4" />
                                <span class="hidden sm:inline">Download Template</span>
                                <span class="sm:hidden">Download</span>
                            </Button>

                            <div class="relative">
                                <Button
                                    @click="openFileDialog"
                                    variant="outline"
                                    size="sm"
                                    class="flex items-center gap-2"
                                >
                                    <Upload class="w-4 h-4" />
                                    <span class="hidden sm:inline">Upload Excel</span>
                                    <span class="sm:hidden">Upload</span>
                                </Button>
                                <input
                                    type="file"
                                    ref="fileInput"
                                    @change="importExcelFile"
                                    accept=".xlsx, .xls"
                                    class="hidden"
                                />
                            </div>

                            <div class="flex items-center gap-2 ml-auto" v-if="canViewCost">
                                <span class="text-sm text-gray-600">Total:</span>
                                <span class="font-bold text-lg">{{ computeOverallTotal }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Items Count and View Toggle -->
                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-sm text-gray-600">
                        <span>{{ filteredItems.length }} items found</span>
                        <div class="flex items-center gap-2">
                            <span>Items per page:</span>
                            <button
                                @click="itemsPerPage = 10"
                                :class="['px-2 py-1 rounded text-xs font-medium transition-colors', itemsPerPage === 10 ? 'bg-blue-100 text-blue-700' : 'text-gray-500 hover:text-gray-700']"
                            >
                                10
                            </button>
                            <button
                                @click="itemsPerPage = 25"
                                :class="['px-2 py-1 rounded text-xs font-medium transition-colors', itemsPerPage === 25 ? 'bg-blue-100 text-blue-700' : 'text-gray-500 hover:text-gray-700']"
                            >
                                25
                            </button>
                            <button
                                @click="itemsPerPage = 50"
                                :class="['px-2 py-1 rounded text-xs font-medium transition-colors', itemsPerPage === 50 ? 'bg-blue-100 text-blue-700' : 'text-gray-500 hover:text-gray-700']"
                            >
                                50
                            </button>
                            <button
                                @click="itemsPerPage = 200"
                                :class="['px-2 py-1 rounded text-xs font-medium transition-colors', itemsPerPage === 200 ? 'bg-blue-100 text-blue-700' : 'text-gray-500 hover:text-gray-700']"
                            >
                                200
                            </button>
                        </div>
                    </div>

                    <!-- Mobile Card View -->
                    <div class="block md:hidden space-y-2">
                        <div
                            v-for="(order, index) in paginatedItems"
                            :key="order.id"
                            class="bg-white border border-gray-200 rounded-lg p-3 hover:shadow-md transition-shadow"
                        >
                            <div class="flex items-start justify-between mb-2">
                                <div class="flex-1">
                                    <div class="font-medium text-gray-900">{{ order.name }}</div>
                                    <div class="text-sm text-gray-600 mt-1">{{ order.inventory_code }}</div>
                                    <div class="text-xs text-gray-500 mt-1">UOM: {{ order.unit_of_measurement }}</div>
                                </div>
                                <button
                                    @click="removeItem(order.id)"
                                    class="h-8 w-8 p-0 hover:bg-red-50 hover:text-red-600 hover:border-red-300 flex-shrink-0 ml-2 rounded-md border border-gray-300"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <span class="text-gray-600">Qty:</span>
                                    <span class="font-medium ml-1">{{ order.quantity }}</span>
                                </div>
                                <div v-if="canViewCost">
                                    <span class="text-gray-600">Cost:</span>
                                    <span class="font-medium ml-1">{{ Number(order.cost).toFixed(2) }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-600">Base Qty:</span>
                                    <span class="font-medium ml-1">{{ order.base_uom_qty }}</span>
                                </div>
                                <div v-if="canViewCost">
                                    <span class="text-gray-600">Total:</span>
                                    <span class="font-medium ml-1">{{ Number(order.total_cost).toFixed(2) }}</span>
                                </div>
                            </div>
                            <div class="mt-2 pt-2 border-t border-gray-100">
                                <button
                                    @click="openEditQuantityModal(order.id, order.quantity)"
                                    class="w-full text-sm text-blue-600 hover:text-blue-800 font-medium"
                                >
                                    Edit Quantity
                                </button>
                            </div>
                        </div>

                        <!-- Empty State for Mobile -->
                        <div v-if="paginatedItems.length === 0" class="text-center py-8 text-gray-500">
                            <svg class="w-12 h-12 text-gray-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                            </svg>
                            <p>No items found. Try searching or add items manually.</p>
                        </div>
                    </div>

                    <!-- Desktop Table View -->
                    <div class="hidden md:block">
                        <div class="overflow-x-auto border border-gray-200 rounded-lg">
                            <table class="w-full text-sm">
                                <!-- Table Header -->
                                <thead class="bg-gray-50 border-b border-gray-200">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-medium text-gray-900">Item Code</th>
                                        <th class="px-4 py-3 text-left font-medium text-gray-900">Item Name</th>
                                        <th class="px-4 py-3 text-left font-medium text-gray-900">UOM</th>
                                        <th class="px-4 py-3 text-center font-medium text-gray-900">Quantity</th>
                                        <th class="px-4 py-3 text-center font-medium text-gray-900">Base Qty</th>
                                        <th v-if="canViewCost" class="px-4 py-3 text-center font-medium text-gray-900">Cost</th>
                                        <th v-if="canViewCost" class="px-4 py-3 text-center font-medium text-gray-900">Total Cost</th>
                                        <th class="px-4 py-3 text-center font-medium text-gray-900">Actions</th>
                                    </tr>
                                </thead>

                                <!-- Table Body -->
                                <tbody class="divide-y divide-gray-200">
                                    <tr v-for="order in paginatedItems" :key="order.id" class="hover:bg-gray-50">
                                        <td class="px-4 py-3 text-gray-900 font-medium">{{ order.inventory_code }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ order.name }}</td>
                                        <td class="px-4 py-3 text-gray-900">{{ order.unit_of_measurement }}</td>
                                        <td class="px-4 py-3 text-center">{{ order.quantity }}</td>
                                        <td class="px-4 py-3 text-center">{{ order.base_uom_qty }}</td>
                                        <td v-if="canViewCost" class="px-4 py-3 text-center">{{ Number(order.cost).toFixed(2) }}</td>
                                        <td v-if="canViewCost" class="px-4 py-3 text-center">{{ Number(order.total_cost).toFixed(2) }}</td>
                                        <td class="px-4 py-3 text-center">
                                            <div class="flex justify-center gap-1">
                                                <button
                                                    @click="openEditQuantityModal(order.id, order.quantity)"
                                                    class="text-blue-600 hover:text-blue-800 text-sm font-medium"
                                                >
                                                    Edit
                                                </button>
                                                <button
                                                    @click="removeItem(order.id)"
                                                    class="text-red-600 hover:text-red-800"
                                                >
                                                    <Trash2 class="w-4 h-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Empty State for Desktop -->
                                    <tr v-if="paginatedItems.length === 0">
                                        <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                            <div class="flex flex-col items-center">
                                                <svg class="w-12 h-12 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                                </svg>
                                                <p>No items found. Try searching or add items manually.</p>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div v-if="filteredItems.length > itemsPerPage" class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4 border-t border-gray-200">
                        <div class="text-sm text-gray-700">
                            Showing {{ (currentPage - 1) * itemsPerPage + 1 }} to {{ Math.min(currentPage * itemsPerPage, filteredItems.length) }} of {{ filteredItems.length }} items
                        </div>
                        <div class="flex items-center gap-1">
                            <button
                                @click="currentPage--"
                                :disabled="currentPage === 1"
                                class="h-8 w-8 p-0 rounded-md border border-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <ChevronLeft class="w-4 h-4" />
                            </button>

                            <button
                                v-for="page in visiblePages"
                                :key="page"
                                @click="currentPage = page === '...' ? null : currentPage = page"
                                :disabled="page === '...'"
                                :class="[
                                    'h-8 w-8 p-0 rounded-md text-sm font-medium transition-colors',
                                    currentPage === page ? 'bg-blue-600 text-white' :
                                    page === '...' ? 'text-gray-500 cursor-default' :
                                    'border border-gray-300 hover:bg-gray-50'
                                ]"
                            >
                                {{ page }}
                            </button>

                            <button
                                @click="currentPage++"
                                :disabled="currentPage === totalPages"
                                class="h-8 w-8 p-0 rounded-md border border-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <ChevronRight class="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="flex flex-col sm:flex-row gap-3 justify-end pt-4 border-t border-gray-200">
                        <div class="flex gap-2">
                            <Button
                                @click="clearAllOrders"
                                variant="outline"
                                class="text-red-600 hover:text-red-700 hover:bg-red-50"
                                :disabled="orderForm.orders.length === 0"
                            >
                                <Trash2 class="w-4 h-4 mr-2" />
                                Clear All
                            </Button>
                        </div>
                        <Button @click="update" :disabled="isLoading" class="min-w-[120px]">
                            <svg v-if="isLoading" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            {{ isLoading ? 'Updating...' : 'Update Order' }}
                        </Button>
                    </div>
                </div>
            </div>
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