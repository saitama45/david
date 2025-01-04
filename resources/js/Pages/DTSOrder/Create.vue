<script setup>
import { useForm } from "@inertiajs/vue3";
const { suppliers, items, branches, variant } = defineProps({
    suppliers: {
        type: Object,
        required: true,
    },
    items: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
    variant: {
        required: true,
        type: String,
    },
});

import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
const confirm = useConfirm();
const { toast } = useToast();

import { useSelectOptions } from "@/Composables/useSelectOptions";

const { options: productsOption } = useSelectOptions(items);
const { options: suppliersOptions } = useSelectOptions(suppliers);
const { options: branchesOptions } = useSelectOptions(branches);
console.log(suppliersOptions);
console.log(productsOption);
console.log(branchesOptions);
const orderForm = useForm({
    branch_id: null,
    supplier_id: Object.keys(suppliers)[0] + "",
    order_date: null,
    orders: [],
    variant: variant,
});

const allowedDays = ref([]);

const dayNameToNumber = {
    SUNDAY: 0,
    MONDAY: 1,
    TUESDAY: 2,
    WEDNESDAY: 3,
    THURSDAY: 4,
    FRIDAY: 5,
    SATURDAY: 6,
};

watch(
    () => orderForm.branch_id,
    (value) => {
        orderForm.order_date = null;
        if (value) {
            axios
                .get(route("schedule.show", value), {
                    params: { variant: variant },
                })
                .then((response) => {
                    // [1, 3, 5]
                    const days = response.data.map(
                        (item) => dayNameToNumber[item]
                    );
                    // [Moday, Wednesay, Friday]
                    let daysOfWeek = [0, 1, 2, 3, 4, 5, 6];
                    allowedDays.value = daysOfWeek.filter(
                        (item) => !days.includes(item)
                    );
                    // [Tuesday, THrusy, Sat]
                })
                .catch((err) => console.log(err));
        }
    }
);
const getNextMonday = () => {
    const today = new Date();
    const dayOfWeek = today.getDay();
    const daysUntilNextSunday = (7 - dayOfWeek) % 7 || 7;

    const nextSunday = new Date(today);
    nextSunday.setDate(today.getDate() + daysUntilNextSunday + 1);
    return nextSunday;
};

const getNextSaturday = () => {
    const today = new Date();
    const dayOfWeek = today.getDay();
    const daysUntilNextSunday = (7 - dayOfWeek) % 7 || 7;

    const nextSunday = new Date(today);
    nextSunday.setDate(today.getDate() + daysUntilNextSunday + 6);
    return nextSunday;
};

const productId = ref(null);

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

const itemForm = useForm({
    item: null,
});

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

const computeOverallTotal = computed(() => {
    return orderForm.orders
        .reduce((total, order) => total + parseFloat(order.total_cost), 0)
        .toFixed(2);
});

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
    if (variant === "ice cream" && Number(productDetails.quantity) < 5) {
        itemForm.setError("quantity", "Quantity must be at least 5");
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
            orderForm.post(route("dts-orders.store"), {
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
</script>
<template>
    <Layout :heading="`DST Orders > ${variant.toUpperCase()} > Create`">
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
                                :options="suppliersOptions"
                                optionLabel="label"
                                optionValue="value"
                                v-model="orderForm.supplier_id"
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
                                :options="branchesOptions"
                                optionLabel="label"
                                optionValue="value"
                                v-model="orderForm.branch_id"
                            >
                            </Select>
                            <FormError>{{
                                orderForm.errors.branch_id
                            }}</FormError>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <InputLabel label="Order Date" />
                            <DatePicker
                                v-model="orderForm.order_date"
                                showIcon
                                fluid
                                :disabledDays="allowedDays"
                                dateFormat="yy/mm/dd"
                                :showOnFocus="false"
                                :minDate="getNextMonday()"
                                :maxDate="getNextSaturday()"
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
                                :options="productsOption"
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
                                        :disabled="
                                            variant === 'ice cream' &&
                                            order.quantity < 6
                                        "
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
        <BackButton :routeName="route('dts-orders.index')"/>
    </Layout>
</template>
