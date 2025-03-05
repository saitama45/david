<script setup>
import Select from "primevue/select";
import { useBackButton } from "@/Composables/useBackButton";
const { backButton } = useBackButton(route("cash-pull-out.index"));
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

onMounted(() => {
    if (drafts.value != null) {
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
                orderForm.store_branch_id = drafts.value.store_branch_id;
                orderForm.order_date = drafts.value.order_date;
                orderForm.orders = drafts.value.orders;
            },
        });
    }
});

const props = defineProps({
    products: {
        type: Object,
        required: false,
    },
    branches: {
        type: Object,
        required: false,
    },
});

const { options: productsOptions } = useSelectOptions(props.products);
const { options: branchesOptions } = useSelectOptions(props.branches);
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
    store_branch_id: "",
    vendor: "",
    vendor_address: "",
    date_needed: null,
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
            orderForm.post(route("cash-pull-out.store"), {
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
    <Layout heading="Create Cash Pull Out">
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
                            <InputLabel label="Store Branch" />
                            <Select
                                filter
                                placeholder="Select a Store"
                                v-model="orderForm.store_branch_id"
                                :options="branchesOptions"
                                optionLabel="label"
                                optionValue="value"
                            >
                            </Select>
                            <FormError>{{
                                orderForm.errors.store_branch_id
                            }}</FormError>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <InputLabel label="Date Needed" />
                            <DatePicker
                                showIcon
                                fluid
                                dateFormat="yy/mm/dd"
                                v-model="orderForm.date_needed"
                                :showOnFocus="false"
                                :manualInput="true"
                            />
                            <FormError>{{
                                orderForm.errors.date_needed
                            }}</FormError>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <InputLabel label="Vendor" />
                            <Input v-model="orderForm.vendor" />
                            <FormError>{{ orderForm.errors.vendor }}</FormError>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <InputLabel label="Vendor Address" />
                            <Textarea v-model="orderForm.vendor_address" />
                            <FormError>{{
                                orderForm.errors.vendor_address
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

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>
