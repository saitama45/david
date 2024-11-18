<script setup>
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
    CardFooter,
} from "@/components/ui/card";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";

import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";


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

import { ref, reactive, watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";

const productId = ref(null);
const orderDate = ref(props.orderDate);
const store = ref(null);
const visible = ref(false);
const toast = useToast();
const isLoading = ref(false);

const productDetails = reactive({
    item_code: null,
    item_name: null,
    unit: null,
    quantity: null,
    cost: null,
});

const form = useForm({
    orders_file: null,
});

const itemForm = useForm({
    item: null,
    quantity: null,
});

import { useConfirm } from "primevue/useconfirm";

const confirm = useConfirm();
// Nat - (This function will just check if the value item select changed to set the UOM accordingly)
watch(productId, (newValue) => {
    if (newValue) {
        isLoading.value = true;
        itemForm.item = newValue;
        axios
            .get(route("product.show", newValue))
            .then((response) => response.data)
            .then((result) => {
                productDetails.item_name = result.InventoryName;
                productDetails.item_code = result.InventoryID;
                productDetails.unit = result.Packaging;
                productDetails.cost = result.Cost;
                console.log(productDetails);
            })
            .catch((err) => console.log(err))
            .finally(() => (isLoading.value = false));
    }
});

const importOrdersButton = () => {
    visible.value = true;
};

const orders = ref([]);

const addToOrdersButton = () => {
    itemForm.quantity = productDetails.quantity;
    if (!itemForm.item) {
        itemForm.setError("item", "Item field is required");
    }
    if (!itemForm.quantity) {
        itemForm.setError("quantity", "Quantity field is required");
    }

    if (
        !productDetails.item_code ||
        !productDetails.item_name ||
        !productDetails.unit ||
        !productDetails.quantity
    ) {
        console.log("test");

        return;
    }

    const existingItemIndex = orders.value.findIndex(
        (order) => order.item_code === productDetails.item_code
    );

    if (existingItemIndex !== -1) {
        orders.value[existingItemIndex].quantity += Number(
            productDetails.quantity
        );
    } else {
        orders.value.push({ ...productDetails });
    }

    console.log(orders.value);

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
            orders.value = orders.value.filter(
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
            console.log(response);
            orders.value = [...orders.value, ...response.data.orders];
            console.log(orders.value);
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
                        <div class="space-y-1">
                            <Label>Store</Label>
                            <Select v-model="store">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select Store" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectLabel>Select Store</SelectLabel>
                                        <SelectItem
                                            v-for="(value, key) in branches"
                                            :key="key"
                                            :value="key"
                                        >
                                            {{ value }}
                                        </SelectItem>
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <Label>SO Date</Label>
                            <Input type="date" v-model="orderDate" />
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
                        <div class="space-y-1">
                            <Label>Item</Label>
                            <Select v-model="productId">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select Item" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectLabel>Select Store</SelectLabel>
                                        <SelectItem
                                            v-for="(value, key) in products"
                                            :key="key"
                                            :value="key"
                                        >
                                            {{ value }}
                                        </SelectItem>
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                            <FormError>{{ itemForm.errors.item }}</FormError>
                        </div>
                        <div class="flex flex-col space-y-1">
                            <Label>Unit Of Measurement (UOM)</Label>
                            <Input
                                type="text"
                                disabled
                                v-model="productDetails.unit"
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
            <Card class="col-span-2">
                <CardHeader>
                    <CardTitle>Items List</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead> Name </TableHead>
                                <TableHead> Code </TableHead>
                                <TableHead> Unit </TableHead>
                                <TableHead> Quantity </TableHead>
                                <TableHead> Cost </TableHead>
                                <TableHead> Action </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="order in orders"
                                :key="order.item_code"
                            >
                                <TableCell>
                                    {{ order.item_name }}
                                </TableCell>
                                <TableCell>
                                    {{ order.item_code }}
                                </TableCell>
                                <TableCell>
                                    {{ order.unit }}
                                </TableCell>
                                <TableCell>
                                    {{ order.quantity }}
                                </TableCell>
                                <TableCell>
                                    {{ order.cost }}
                                </TableCell>
                                <TableCell>
                                    <Button
                                        @click="removeItem(order.item_code)"
                                        variant="outline"
                                        class="text-red-500"
                                    >
                                        <Trash2 />
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
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
