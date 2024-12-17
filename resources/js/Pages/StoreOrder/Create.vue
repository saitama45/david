<script setup>
import Select from "primevue/select";
import { useBackButton } from "@/Composables/useBackButton";
const { backButton } = useBackButton(route("store-orders.index"));
import { useSelectOptions } from "@/Composables/useSelectOptions";

import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";

const confirm = useConfirm();
const { toast } = useToast();

const props = defineProps({
    products: {
        type: Object,
        required: false,
    },
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
const { options: productsOptions } = useSelectOptions(props.products);
const { options: suppliersOptions } = useSelectOptions(props.suppliers);

import { useForm } from "@inertiajs/vue3";

const productId = ref(null);
const visible = ref(false);
const isLoading = ref(false);

const productDetails = reactive({
    id: null,
    inventory_code: null,
    name: null,
    unit_of_measurement: null,
    quantity: null,
    cost: null,
    total_cost: null,
});

const excelFileForm = useForm({
    orders_file: null,
});

const orderForm = useForm({
    branch_id: previousOrder?.store_branch_id + "",
    supplier_id: previousOrder?.supplier_id + "",
    order_date: null,
    orders: [],
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
// Nat - (This function will just check if the value item select changed to set the UOM accordingly)
watch(productId, (newValue) => {
    if (newValue) {
        isLoading.value = true;
        itemForm.item = newValue;
        axios
            .get(route("product.show", newValue))
            .then((response) => response.data)
            .then((result) => {
                productDetails.id = result.id;
                productDetails.name = result.name;
                productDetails.inventory_code = result.inventory_code;
                productDetails.unit_of_measurement = result.unit_of_measurement;
                productDetails.cost = result.cost;
            })
            .catch((err) => console.log(err))
            .finally(() => (isLoading.value = false));
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
    if (Number(productDetails.quantity) < 1) {
        itemForm.setError("quantity", "Quantity must be at least 1");
        return;
    }

    if (
        !productDetails.inventory_code ||
        !productDetails.name ||
        !productDetails.unit_of_measurement ||
        !productDetails.quantity
    ) {
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

// Nat - (getting the imported data)
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
            excelFileForm.setError("orders_file", error.response.data.message);
        })
        .finally(() => (isLoading.value = false));
};

const addItemQuantity = (id) => {
    const index = orderForm.orders.findIndex((item) => item.id === id);
    orderForm.orders[index].quantity += 1;
    orderForm.orders[index].total_cost = parseFloat(
        orderForm.orders[index].quantity * orderForm.orders[index].cost
    ).toFixed(2);
};

const minusItemQuantity = (id) => {
    const index = orderForm.orders.findIndex((item) => item.id === id);
    orderForm.orders[index].quantity -= 1;
    if (orderForm.orders[index].quantity < 1) {
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

if (previousOrder) {
    previousOrder.store_order_items.forEach((item) => {
        const product = {
            id: item.product_inventory.id,
            inventory_code: item.product_inventory.inventory_code,
            name: item.product_inventory.name,
            unit_of_measurement:
                item.product_inventory.unit_of_measurement.name,
            quantity: item.quantity_ordered,
            cost: item.product_inventory.cost,
            total_cost: parseFloat(
                item.quantity_ordered * item.product_inventory.cost
            ).toFixed(2),
        };
        orderForm.orders.push(product);
    });
}

const calculatePULILANOrderDate = () => {
    const now = new Date();

    const nextSunday = new Date(
        now.getFullYear(),
        now.getMonth(),
        now.getDate() + (7 - now.getDay())
    );

    const nextSaturday = new Date(
        now.getFullYear(),
        now.getMonth(),
        nextSunday.getDate() + 6
    );

    orderRestrictionDate.minDate = nextSunday;
    orderRestrictionDate.maxDate = nextSaturday;
};

const calculateGSIOrderDate = () => {
    const now = new Date();

    const upcomingSunday = new Date(
        now.getFullYear(),
        now.getMonth(),
        now.getDate() + (7 - now.getDay())
    );

    const secondBatchStartDate = new Date(
        now.getFullYear(),
        now.getMonth(),
        upcomingSunday.getDate() + 4
    );

    const secondBatchEndDate = new Date(
        now.getFullYear(),
        now.getMonth(),
        upcomingSunday.getDate() + 6
    );

    const currentDay = now.getDay();
    const currentHour = now.getHours();

    const getNextDayOfWeek = (targetDay, forceNextWeek = false) => {
        const result = new Date(now);

        let daysToAdd = (targetDay - now.getDay() + 7) % 7;

        if (forceNextWeek || daysToAdd === 0) {
            daysToAdd += 7;
        }

        result.setDate(now.getDate() + daysToAdd);
        return result;
    };
    const nextSunday = getNextDayOfWeek(0, true);
    const nextWednesday = getNextDayOfWeek(3, true);

    const nextThursday = getNextDayOfWeek(4, true);
    const nextSaturday = getNextDayOfWeek(6, true);

    if (
        currentDay === 0 ||
        currentDay === 1 ||
        currentDay === 2 ||
        (currentDay === 3 && currentHour < 7)
    ) {
        orderRestrictionDate.minDate = nextSunday;
        orderRestrictionDate.maxDate = nextWednesday;
    } else {
        orderRestrictionDate.minDate = secondBatchStartDate;
        orderRestrictionDate.maxDate = secondBatchEndDate;
    }
};
watch(
    () => orderForm.supplier_id,
    (supplier_id) => {
        if (!supplier_id) return;

        const selectedBranch = Object.values(suppliersOptions.value).find(
            (option) => option.value === supplier_id + ""
        );

        if (!selectedBranch) return;

        if (
            selectedBranch.label === "GSI OT-BAKERY" ||
            selectedBranch.label === "GSI OT-PR"
        ) {
            calculateGSIOrderDate();
        } else if (selectedBranch.label === "PUL OT-DG") {
            calculatePULILANOrderDate();
        }
    }
);
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
                                :options="productsOptions"
                                optionLabel="label"
                                optionValue="value"
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
                <CardHeader>
                    <CardTitle>Items List</CardTitle>
                </CardHeader>
                <CardContent class="flex-1">
                    <Table>
                        <TableHead>
                            <TH> Name </TH>
                            <TH> Code </TH>
                            <TH> Quantity </TH>
                            <TH> Unit </TH>
                            <TH> Cost </TH>
                            <TH> Total Cost </TH>
                            <TH> Action </TH>
                        </TableHead>

                        <TableBody>
                            <tr
                                v-for="order in orderForm.orders"
                                :key="order.item_code"
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
                                    {{ order.cost }}
                                </TD>
                                <TD>
                                    {{ order.total_cost }}
                                </TD>
                                <TD class="flex gap-3">
                                    <button
                                        class="text-red-500"
                                        @click="minusItemQuantity(order.id)"
                                    >
                                        <Minus />
                                    </button>
                                    <button
                                        class="text-green-500"
                                        @click="addItemQuantity(order.id)"
                                    >
                                        <Plus />
                                    </button>
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
                        <span><Loading v-if="isLoading" /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>
