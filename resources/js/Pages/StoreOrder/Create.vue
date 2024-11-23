<script setup>
import DatePicker from "primevue/datepicker";
import Select from "primevue/select";
import { computed } from "vue";

import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";

const props = defineProps({
    products: {
        type: Object,
        required: false,
    },
    branches: {
        type: Object,
        required: true,
    },
});

const branchesOptions = computed(() => {
    return Object.entries(props.branches).map(([value, label]) => ({
        value: value,
        label: label,
    }));
});

const productsOptions = computed(() => {
    return Object.entries(props.products).map(([value, label]) => ({
        value: value,
        label: label,
    }));
});

import { ref, reactive, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
const toast = useToast();
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

const form = useForm({
    orders_file: null,
});

const orderForm = useForm({
    branch_id: null,
    order_date: new Date().toLocaleString().slice(0, 10),
    orders: [],
});

const itemForm = useForm({
    item: null,
});

const store = () => {
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
            console.log(e);
        },
    });
};

import { useConfirm } from "primevue/useconfirm";

const confirm = useConfirm();
// Nat - (This function will just check if the value item select changed to set the UOM accordingly)
watch(productId, (newValue) => {
    if (newValue) {
        isLoading.value = true;
        itemForm.item = newValue;
        axios
            .get(route("product.show", newValue.value))
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
    if (!itemForm.item) {
        itemForm.setError("item", "Item field is required");
    }
    if (!productDetails.quantity) {
        itemForm.setError("quantity", "Quantity field is required");
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
        orderForm.orders[existingItemIndex].total_cost =
            productDetails.cost * quantity;
    } else {
        productDetails.total_cost =
            productDetails.cost * productDetails.quantity;
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

const removeItem = (item_code) => {
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
            // Remove .value here
            orderForm.orders = orderForm.orders.filter(
                (item) => item.item_code !== item_code
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

// Nat - (getting the imported data)
const proceedButton = () => {
    isLoading.value = true;
    const formData = new FormData();
    formData.append("orders_file", form.orders_file);

    axios
        .post(route("store-orders.imported-file"), formData, {
            headers: {
                "Content-Type": "multipart/form-data",
            },
        })
        .then((response) => {
            // Remove .value here
            orderForm.orders = [...orderForm.orders, ...response.data.orders];
            visible.value = false;
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Items added successfully.",
                life: 5000,
            });
            form.orders_file = null;
        })
        .catch((error) => {
            form.setError("orders_file", error.response.data.message);
        })
        .finally(() => (isLoading.value = false));
};
</script>

<template>
    <Layout
        heading="Store Order > Create"
        :hasButton="true"
        buttonName="Import Orders"
        :handleClick="importOrdersButton"
    >
        <div class="grid grid-cols-3 gap-5">
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
                            <Label>Store</Label>
                            <Select
                                filter
                                placeholder="Select a Store"
                                v-model="orderForm.branch_id"
                                :options="branchesOptions"
                                optionLabel="label"
                                optionValue="value"
                            >
                            </Select>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <Label>SO Date</Label>
                            <DatePicker
                                showIcon
                                fluid
                                dateFormat="yy/mm/dd"
                                v-model="orderForm.order_date"
                                :showOnFocus="false"
                            />
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
                                <TD>
                                    <Button
                                        @click="removeItem(order.item_code)"
                                        variant="outline"
                                        class="text-red-500"
                                    >
                                        <Trash2 />
                                    </Button>
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
                            @input="form.orders_file = $event.target.files[0]"
                        />
                        <FormError>{{ form.errors.orders_file }}</FormError>
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
                    <Button @click="proceedButton" type="submit" class="gap-2">
                        Proceed
                        <span><Loading v-if="isLoading" /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
