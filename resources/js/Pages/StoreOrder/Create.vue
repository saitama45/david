<script setup>
import { ref, reactive, computed, watch, onBeforeMount } from 'vue';
import Select from "primevue/select";
import DatePicker from "primevue/datepicker";
import axios from 'axios'; // Import axios for API calls

import { useBackButton } from "@/Composables/useBackButton";
const { backButton } = useBackButton(route("store-orders.index"));
import { useSelectOptions } from "@/Composables/useSelectOptions";

import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";

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

const { options: suppliersOptions } = useSelectOptions(props.suppliers);

import { useForm } from "@inertiajs/vue3";

const productId = ref(null);
const visible = ref(false);
const isLoading = ref(false);

watch(visible, (newValue) => {
    if (!newValue) {
        excelFileForm.reset();
        excelFileForm.clearErrors();
    }
});

const productDetails = reactive({ // Renamed for clarity in previous response, sticking to productDetails for consistency with user's current code
    id: null,
    inventory_code: null,
    name: null,
    unit_of_measurement: null,
    base_uom: null,
    quantity: null,
    cost: null,
    total_cost: null,
    uom: null,
});

const excelFileForm = useForm({
    orders_file: null,
});

const orderForm = useForm({
    branch_id: previousOrder?.store_branch_id ? previousOrder.store_branch_id + "" : null, // Initialize with null if previousOrder or its branch_id is undefined
    supplier_id: previousOrder?.supplier_id ? previousOrder.supplier_id + "" : null, // Initialize with null if previousOrder or its supplier_id is undefined
    order_date: null,
    orders: [],
});

const computeOverallTotal = computed(() => {
    return orderForm.orders
        .reduce((total, order) => total + parseFloat(order.total_cost), 0)
        .toFixed(2);
});

const itemForm = useForm({
    item: null,
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
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "Can't place the order.",
                        life: 5000,
                    });
                },
            });
        },
    });
};

// Adjust this watch to fetch SupplierItem details from a new endpoint
watch(productId, (newValue) => {
    if (newValue) {
        isLoading.value = true;
        itemForm.item = newValue; // productId is the ID of the SupplierItem

        // Fetch details of the selected SupplierItem
        axios
            .get(route("SupplierItems.details.json", newValue)) // <--- **This route should be defined in web.php**
            .then((response) => response.data)
            .then((result) => {
                productDetails.id = result.id;
                productDetails.name = result.item_name; // From supplier_items table
                productDetails.inventory_code = result.ItemCode; // From supplier_items table
                productDetails.unit_of_measurement = result.uom; // From supplier_items table
                productDetails.base_uom = result.sap_masterfile.BaseUOM;
                productDetails.cost = result.cost; // From supplier_items table
                productDetails.uom = result.uom; // Redundant, but keeping for consistency if needed
            })
            .catch((err) => {
                console.error("Error fetching supplier item details:", err);
                toast.add({
                    severity: "error",
                    summary: "Error",
                    detail: "Failed to load item details.",
                    life: 5000,
                });
            })
            .finally(() => (isLoading.value = false));
    } else {
        // Clear productDetails if productId is null (item deselected)
        Object.keys(productDetails).forEach((key) => {
            productDetails[key] = null;
        });
    }
});

const importOrdersButton = () => {
    visible.value = true;
};

const addToOrdersButton = () => {
    itemForm.clearErrors();
    if (!itemForm.item) {
        itemForm.setError("item", "Item field is required");
        return;
    }
    // Check if productDetails.quantity is a valid number before comparing
    if (isNaN(Number(productDetails.quantity)) || Number(productDetails.quantity) < 0.1) {
        itemForm.setError("quantity", "Quantity must be at least 0.1 and a valid number");
        return;
    }

    if (
        !productDetails.inventory_code ||
        !productDetails.name ||
        !productDetails.unit_of_measurement ||
        !productDetails.quantity ||
        productDetails.cost === null // Ensure cost is loaded
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
        (order) => order.id === productDetails.id
    );

    if (existingItemIndex !== -1) {
        const quantity = (orderForm.orders[existingItemIndex].quantity +=
            Number(productDetails.quantity));
        orderForm.orders[existingItemIndex].total_cost = parseFloat(
            productDetails.cost * quantity
        ).toFixed(2);
    } else {
        productDetails.total_cost = parseFloat(
            productDetails.cost * productDetails.quantity
        ).toFixed(2);
        orderForm.orders.push({ ...productDetails });
    }

    // Clear productDetails and productId after adding
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
            response.data.orders.forEach((importedOrder) => {
                const existingItemIndex = orderForm.orders.findIndex(
                    (order) => order.id === importedOrder.id
                );

                if (existingItemIndex !== -1) {
                    const updatedQuantity =
                        orderForm.orders[existingItemIndex].quantity +
                        Number(importedOrder.quantity);
                    orderForm.orders[existingItemIndex].quantity =
                        updatedQuantity;
                    orderForm.orders[existingItemIndex].total_cost = parseFloat(
                        updatedQuantity *
                            orderForm.orders[existingItemIndex].cost
                    ).toFixed(2);
                } else {
                    orderForm.orders.push({
                        ...importedOrder,
                        total_cost: parseFloat(
                            importedOrder.quantity * importedOrder.cost
                        ).toFixed(2),
                    });
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
        })
        .catch((error) => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occured while trying to get the imported orders. Please make sure that you are using the correct format.",
                life: 5000,
            });
            excelFileForm.setError("orders_file", error.response.data.message);
            console.log(error);
        })
        .finally(() => (isLoading.value = false));
};

const addItemQuantity = (id) => {
    const index = orderForm.orders.findIndex((item) => item.id === id);
    orderForm.orders[index].quantity = parseFloat(
        (orderForm.orders[index].quantity + 0.1).toFixed(2)
    );
    orderForm.orders[index].total_cost = parseFloat(
        orderForm.orders[index].quantity * orderForm.orders[index].cost
    ).toFixed(2);
};

const minusItemQuantity = (id) => {
    const index = orderForm.orders.findIndex((item) => item.id === id);
    orderForm.orders[index].quantity = parseFloat(
        (orderForm.orders[index].quantity - 0.1).toFixed(2)
    );
    if (orderForm.orders[index].quantity < 0.1) {
        orderForm.orders = orderForm.orders.filter((item) => item.id !== id);
        return;
    }
    orderForm.orders[index].total_cost = parseFloat(
        orderForm.orders[index].quantity * orderForm.orders[index].cost
    ).toFixed(2);
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

// This watch block is critical for fetching filtered items
watch(
    () => orderForm.supplier_id,
    async (supplierId) => { // Made it async to use await
        orderForm.order_date = null;
        productId.value = null; // Clear selected product when supplier changes
        Object.keys(productDetails).forEach((key) => { // Clear product details as well
            productDetails[key] = null;
        });

        availableProductsOptions.value = []; // Clear options immediately

        if (!supplierId) {
            // Check if supplierId is null or undefined before proceeding
            return; // Exit if no supplier selected
        }

        try {
            // *** CHANGE: Fetch supplier by ID to get the supplier_code ***
            const supplierResponse = await axios.get(route('suppliers.show', supplierId));
            const supplierCode = supplierResponse.data.supplier_code;

            if (supplierCode) {
                // Fetch supplier items based on the supplier_code
                isLoading.value = true;
                const response = await axios.get(route('store-orders.get-supplier-items', supplierCode));
                // Assuming response.data.items is already in {value: 'id', label: 'name'} format or needs mapping
                availableProductsOptions.value = response.data.items;
                isLoading.value = false;
            } else {
                console.error("Supplier code could not be determined for selected supplier ID:", supplierId);
            }

        } catch (error) {
            console.error("Error fetching supplier or supplier items:", error);
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "Failed to load items for the selected supplier.",
                life: 5000,
            });
            isLoading.value = false;
        }


        // Date calculation logic (remains the same)
        const selectedSupplier = suppliersOptions.value.find(
            (option) => option.value === supplierId + ""
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
    { immediate: true } // Run immediately on component mount if previousOrder exists
);

// Computed property to check if a supplier is selected (still useful for other disabled states)
const isSupplierSelected = computed(() => {
    return orderForm.supplier_id !== null && orderForm.supplier_id !== '';
});


if (previousOrder) {
    previousOrder.store_order_items.forEach((item) => {
        // *** CHANGE: Adapt this to item.supplier_item structure ***
        console.log("Existing Ordered Item:", item);
        const product = {
            id: item.supplier_item.id,
            inventory_code: item.supplier_item.ItemCode,
            name: item.supplier_item.item_name,
            unit_of_measurement: item.supplier_item.uom,
            base_uom: item.supplier_item.sap_masterfile.BaseUOM,
            quantity: item.quantity_ordered,
            cost: item.supplier_item.cost,
            total_cost: parseFloat(
                item.quantity_ordered * item.supplier_item.cost
            ).toFixed(2),
        };
        orderForm.orders.push(product);
    });
}

import { useEditQuantity } from "@/Composables/useEditQuantity";
const {
    isEditQuantityModalOpen,
    formQuantity,
    openEditQuantityModal,
    editQuantity,
} = useEditQuantity(orderForm);

watch(orderForm, (value) => {
    localStorage.setItem("storeStoreOrderDraft", JSON.stringify(value));
});
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
                                :disabled="!isSupplierSelected"
                            >
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
                        <Button @click="addToOrdersButton">
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
                                    {{ order.unit_of_measurement }}
                                </TD>
                                <TD>
                                    {{ order.cost }}
                                </TD>
                                <TD>
                                    {{ order.total_cost }}
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
                            <LabelXS>Cost: {{ order.cost }}</LabelXS>
                        </MobileTableRow>
                    </MobileTableContainer>
                </CardContent>
                <CardFooter class="flex justify-end">
                    <Button @click="store">Place Order</Button>
                </CardFooter>
            </Card>
        </div>

        <Dialog v-model:open="isEditQuantityModalOpen">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Edit Quantity</DialogTitle>
                    <DialogDescription>
                        Please input all the required fields.
                    </DialogDescription>
                </DialogHeader>
                <InputContainer>
                    <LabelXS>Quantity</LabelXS>
                    <Input type="number" v-model="formQuantity.quantity" />
                    <FormError>{{ formQuantity.errors.quantity }}</FormError>
                </InputContainer>

                <DialogFooter>
                    <Button
                        @click="editQuantity"
                        :disabled="isLoading"
                        type="submit"
                        class="gap-2"
                    >
                        Confirm
                        <span v-if="isLoading"><Loading /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="visible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Import Orders</DialogTitle>
                    <DialogDescription>
                        Import the excel file of your orders.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-5">
                    <div class="flex flex-col space-y-1">
                        <Label>Orders</Label>
                        <Input
                            type="file"
                            @input="
                                excelFileForm.orders_file =
                                    $event.target.files[0]
                            "
                        />
                        <FormError>{{
                            excelFileForm.errors.orders_file
                        }}</FormError>
                    </div>
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs">Order Templates</Label>
                        <ul>
                            <li class="text-xs">
                                GSI BAKERY:
                                <a
                                    class="text-blue-500 underline"
                                    href="/excel/gsi-bakery-template"
                                    >Click to download</a
                                >
                            </li>
                            <li class="text-xs">
                                GSI OT:
                                <a
                                    class="text-blue-500 underline"
                                    href="/excel/gsi-pr-template"
                                    >Click to download</a
                                >
                            </li>
                            <li class="text-xs">
                                PUL:
                                <a
                                    class="text-blue-500 underline"
                                    href="/excel/pul-template"
                                    >Click to download</a
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
                        Proceed
                        <span v-if="isLoading"><Loading /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>