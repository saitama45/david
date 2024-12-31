<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
const confirm = useConfirm();
const { toast } = useToast();
const { menus, branches } = defineProps({
    menus: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    store_branch_id: null,
    usage_date: new Date().toDateString(),
    items: [],
});

const itemForm = useForm({
    id: null,
    name: null,
    quantity: null,
    price: null,
    total_price: null,
});

const addToItemsList = () => {
    if (itemForm.id === null) {
        itemForm.setError("id", "Item field is required");
        return;
    }
    if (itemForm.quantity < 1) {
        itemForm.setError("quantity", "Quantity must be atleast 1");
        return;
    }
    console.log(itemForm.id);
    const existingItemIndex = form.items.findIndex(
        (item) => item.id === itemForm.id
    );
    if (existingItemIndex === -1) {
        itemForm.total_price = parseFloat(itemForm.quantity * itemForm.price);
        form.items.push({ ...itemForm });
    } else {
        const item = form.items[existingItemIndex];
        item.quantity += itemForm.quantity;
        item.total_price = parseFloat(item.quantity * itemForm.price);
    }

    itemForm.reset();
    itemForm.clearErrors();

    // Object.keys(itemForm).forEach((key) => {
    //     itemForm[key] = null;
    // });
};

const { options: menusOptions } = useSelectOptions(menus);
const { options: branchesOptions } = useSelectOptions(branches);

watch(
    () => itemForm.id,
    (newValue) => {
        if (newValue == null) return;
        itemForm.clearErrors();
        axios
            .get(route("menu-item.show", newValue))
            .then((res) => res.data)
            .then((result) => {
                itemForm.name = result.name;
                itemForm.price = result.price;
            })
            .catch((err) => console.log(err));
    }
);

const addItemQuantity = (id) => {
    const index = form.items.findIndex((item) => item.id === id);
    form.items[index].quantity += 1;
    form.items[index].total_price = parseFloat(
        form.items[index].quantity * form.items[index].price
    ).toFixed(2);
};

const computeOverallTotal = computed(() => {
    return form.items
        .reduce((total, order) => total + parseFloat(order.total_price), 0)
        .toFixed(2);
});

const minusItemQuantity = (id) => {
    const index = form.items.findIndex((item) => item.id === id);
    form.items[index].quantity -= 1;
    if (form.items[index].quantity < 1) {
        form.items = form.items.filter((item) => item.id !== id);
        return;
    }
    form.items[index].total_price = parseFloat(
        form.items[index].quantity * form.items[index].price
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
            form.items = form.items.filter((item) => item.id !== id);
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
    if (form.items.length < 1) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Please select at least one item before proceeding.",
            life: 5000,
        });
        return;
    }
    confirm.require({
        message: "Are you sure you want to create this record?",
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
            form.post(route("usage-records.store"), {
                onSuccess: () => {
                    console.log("success");
                },
                onError: () => {
                    console.log("error");
                },
            });
        },
    });
};
</script>

<template>
    <Layout heading="Create Usage Record">
        <Card class="p-5 grid grid-cols-3 gap-5">
            <DivFlexCol class="gap-5">
                <Card>
                    <CardHeader>
                        <CardTitle>Record Details</CardTitle>
                        <CardDescription
                            >Please input all the required
                            fields.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <InputContainer>
                            <LabelXS>Store Branch</LabelXS>
                            <Select
                                filter
                                placeholder="Select a Store"
                                :options="branchesOptions"
                                optionLabel="label"
                                optionValue="value"
                                v-model="form.store_branch_id"
                            >
                            </Select>
                            <FormError>{{
                                form.errors.store_branch_id
                            }}</FormError>
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Usage Date</LabelXS>
                            <DatePicker
                                fluid
                                showIcon
                                v-model="form.usage_date"
                                :maxDate="new Date()"
                            />
                            <FormError>{{ form.errors.usage_date }}</FormError>
                        </InputContainer>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Menu List</CardTitle>
                        <CardDescription
                            >Please input all the required
                            fields.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <InputContainer>
                            <LabelXS>Item</LabelXS>
                            <Select
                                filter
                                placeholder="Select a Store"
                                :options="menusOptions"
                                optionLabel="label"
                                optionValue="value"
                                v-model="itemForm.id"
                            >
                            </Select>
                            <FormError>{{ itemForm.errors.id }}</FormError>
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Quantity</LabelXS>
                            <Input type="number" v-model="itemForm.quantity" />
                            <FormError>{{
                                itemForm.errors.quantity
                            }}</FormError>
                        </InputContainer>
                    </CardContent>
                    <CardFooter class="justify-end">
                        <Button @click="addToItemsList">Add</Button>
                    </CardFooter>
                </Card>
            </DivFlexCol>

            <TableContainer class="col-span-2">
                <DivFlexCenter class="flex justify-between">
                    <SpanBold>Items Sold</SpanBold>
                    <DivFlexCenter class="gap-2">
                        <LabelXS> Overall Total:</LabelXS>
                        <SpanBold>{{ computeOverallTotal }}</SpanBold>
                    </DivFlexCenter>
                </DivFlexCenter>
                <Table>
                    <TableHead>
                        <TH>Item</TH>
                        <TH>Price</TH>
                        <TH>Quantity</TH>
                        <TH>Total Price</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="item in form.items">
                            <TD>{{ item.name }}</TD>
                            <TD>{{ item.price }}</TD>
                            <TD>{{ item.quantity }}</TD>
                            <TD>{{ item.total_price }}</TD>
                            <TD>
                                <DivFlexCenter class="gap-3">
                                    <button
                                        class="text-red-500"
                                        @click="minusItemQuantity(item.id)"
                                    >
                                        <Minus />
                                    </button>
                                    <button
                                        class="text-green-500"
                                        @click="addItemQuantity(item.id)"
                                    >
                                        <Plus />
                                    </button>
                                    <DeleteButton
                                        @click="removeItem(item.id)"
                                    />
                                </DivFlexCenter>
                            </TD>
                        </tr>
                    </TableBody>
                </Table>
                <DivFlexCenter class="justify-end">
                    <Button @click="store">Proceed</Button>
                </DivFlexCenter>
            </TableContainer>
        </Card>
    </Layout>
</template>
