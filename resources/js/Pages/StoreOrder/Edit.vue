<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { useBackButton } from "@/Composables/useBackButton";
const { backButton } = useBackButton(route("store-orders.index"));

const confirm = useConfirm();
const { toast } = useToast();

const drafts = ref(null);
onBeforeMount(() => {
    const previousData = localStorage.getItem("editStoreOrderDraft");
    if (previousData) {
        drafts.value = JSON.parse(previousData);
    }
});

onMounted(() => {
    if (drafts.value) {
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
                orderForm.order_date = drafts.value.order_date;
                orderForm.orders = drafts.value.orders;
            },
        });
    }
});

const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
    orderedItems: {
        type: Object,
        required: true,
    },
    products: {
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

const { options: branchesOptions } = useSelectOptions(props.branches);
const { options: productsOptions } = useSelectOptions(props.products);
const { options: suppliersOptions } = useSelectOptions(props.suppliers);
const productId = ref(null);
const isLoading = ref(false);

const orderForm = useForm({
    supplier_id: props.order.supplier_id + "",
    branch_id: props.order.store_branch_id + "",
    order_date: props.order.order_date,
    orders: [],
});

const itemForm = useForm({
    item: null,
});

const productDetails = reactive({
    id: null,
    inventory_code: null,
    name: null,
    unit_of_measurement: null,
    quantity: null,
    cost: null,
    total_cost: null,
});

props.orderedItems.forEach((item) => {
    const product = {
        id: item.product_inventory.id,
        inventory_code: item.product_inventory.inventory_code,
        name: item.product_inventory.name,
        unit_of_measurement: item.product_inventory.unit_of_measurement.name,
        quantity: item.quantity_ordered,
        cost: item.product_inventory.cost,
        total_cost: parseFloat(
            item.quantity_ordered * item.product_inventory.cost
        ).toFixed(2),
    };
    orderForm.orders.push(product);
});

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

const addToOrdersButton = () => {
    itemForm.clearErrors();
    if (!itemForm.item) {
        itemForm.setError("item", "Item field is required");
        return;
    }
    if (Number(productDetails.quantity) < 0.1) {
        itemForm.setError("quantity", "Quantity must be at least 0.1");
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
            Number(productDetails.cost * quantity)
        ).toFixed(2);
    } else {
        productDetails.total_cost = parseFloat(
            Number(productDetails.cost * productDetails.quantity)
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
    confirm.require({
        message: "Are you sure you want to place update the order details?",
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
                },
                onError: (e) => {
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "Can't place update the order.",
                        life: 5000,
                    });
                },
            });
        },
    });
};

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
    return orderForm.orders
        .reduce((total, order) => total + parseFloat(order.total_cost), 0)
        .toFixed(2);
});

watch(
    () => orderForm.supplier_id,
    (supplier_id) => {
        orderForm.order_date = null;
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

onMounted(() => {
    const selectedBranch = Object.values(suppliersOptions.value).find(
        (option) => option.value === orderForm.supplier_id + ""
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
});

import { useEditQuantity } from "@/Composables/useEditQuantity";
const {
    isEditQuantityModalOpen,
    formQuantity,
    openEditQuantityModal,
    editQuantity,
} = useEditQuantity(orderForm);

const heading = `Edit Order #${props.order.order_number}`;

const excelFileForm = useForm({
    orders_file: null,
});
const visible = ref(false);
const importOrdersButton = () => {
    visible.value = true;
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

watch(orderForm, (value) => {
    localStorage.setItem("editStoreOrderDraft", JSON.stringify(value));
});
</script>
<template>
    <Layout
        :heading="heading"
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
                                    <!-- <button
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
                                    </button> -->
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
                            :key="order.item_code"
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
                    <Button @click="update">Place Order</Button>
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
