<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import FormError from "@/Components/FormError.vue";
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
    order_number: null,
    transaction_period: null,
    transaction_date: null,
    cashier_id: null,
    order_type: null,
    sub_total: 0,
    total_amount: 0,
    tax_amount: 0,
    payment_type: null,
    discount_amount: 0,
    discount_type: null,
    service_charge: 0,
    remarks: "",
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
    const total = form.items
        .reduce((total, order) => total + parseFloat(order.total_price), 0)
        .toFixed(2);

    form.sub_total = total;
    form.total_amount = total - form.discount_amount + form.service_charge;
    return total;
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

const transactionPeriods = [
    { value: "breakfast", label: "Breakfast" },
    { value: "lunch", label: "Lunch" },
    { value: "dinner", label: "Dinner" },
];

const orderTypes = [
    { value: "dine_in", label: "Dine In" },
    { value: "take_out", label: "Take Out" },
];

const paymentTypes = [
    { value: "cash", label: "Cash" },
    { value: "credit_card", label: "Credit Card" },
    { value: "debit_card", label: "Debit Card" },
    { value: "gift_card", label: "Gift Card" },
];
const excelFileForm = useForm({
    store_transactions_file: null,
});
const isLoading = ref(false);
const isImportStoreTransactionModalOpen = ref(false);
const openStoreTransactionsModal = () => {
    isImportStoreTransactionModalOpen.value = true;
};
const importStoreTransactions = () => {
    isLoading.value = true;
    excelFileForm.post(route("usage-records.import"), {
        onSuccess: () => {
            isLoading.value = false;
            isImportStoreTransactionModalOpen.value = false;
        },
        onError: () => {
            isLoading.value = false;
        },
    });
};
</script>

<template>
    <Layout
        heading="Create New Transaction"
        :hasButton="true"
        buttonName="Import Store Transactions"
        :handleClick="openStoreTransactionsModal"
    >
        <Card class="p-5 grid sm:grid-cols-3 grid-cols-1 gap-5">
            <Card class="sm:col-span-1 col-span-2">
                <CardHeader>
                    <CardTitle>Transaction Details</CardTitle>
                    <CardDescription>
                        Please input all the required fields.
                    </CardDescription>
                    <section class="space-y-3">
                        <InputContainer>
                            <LabelXS>Store Branch</LabelXS>
                            <Select
                                filter
                                placeholder="Select a branch"
                                :options="branchesOptions"
                                optionLabel="label"
                                optionValue="value"
                                v-model="form.store_branch_id"
                            ></Select>
                            <FormError>{{
                                form.errors.store_branch_id
                            }}</FormError>
                        </InputContainer>

                        <InputContainer>
                            <LabelXS>Order Number</LabelXS>
                            <Input v-model="form.order_number" />
                            <FormError>{{
                                form.errors.order_number
                            }}</FormError>
                        </InputContainer>

                        <InputContainer>
                            <LabelXS>Transaction Period</LabelXS>
                            <Select
                                filter
                                placeholder="Select a period"
                                :options="transactionPeriods"
                                optionLabel="label"
                                optionValue="value"
                                v-model="form.transaction_period"
                            ></Select>
                            <FormError>{{
                                form.errors.transaction_period
                            }}</FormError>
                        </InputContainer>

                        <InputContainer>
                            <LabelXS>Transaction Date</LabelXS>
                            <DatePicker v-model="form.transaction_date" />
                            <FormError>{{
                                form.errors.transaction_date
                            }}</FormError>
                        </InputContainer>

                        <InputContainer>
                            <LabelXS>Cashier Id</LabelXS>
                            <Input v-model="form.cashier_id" />
                            <FormError>{{ form.errors.cashier_id }}</FormError>
                        </InputContainer>

                        <InputContainer>
                            <LabelXS>Order Type</LabelXS>
                            <Select
                                filter
                                placeholder="Select a type"
                                :options="orderTypes"
                                optionLabel="label"
                                optionValue="value"
                                v-model="form.order_type"
                            ></Select>
                            <FormError>{{ form.errors.order_type }}</FormError>
                        </InputContainer>

                        <InputContainer>
                            <LabelXS>Sub Total</LabelXS>
                            <Input v-model="form.sub_total" />
                            <FormError>{{ form.errors.sub_total }}</FormError>
                        </InputContainer>

                        <InputContainer>
                            <LabelXS>Tax Amount</LabelXS>
                            <Input v-model="form.tax_amount" />
                            <FormError>{{ form.errors.tax_amount }}</FormError>
                        </InputContainer>

                        <InputContainer>
                            <LabelXS>Service Charge</LabelXS>
                            <Input v-model="form.service_charge" />
                            <FormError>{{
                                form.errors.service_charge
                            }}</FormError>
                        </InputContainer>

                        <InputContainer>
                            <LabelXS>Discount Type</LabelXS>
                            <Input v-model="form.discount_type" />
                            <FormError>{{
                                form.errors.discount_type
                            }}</FormError>
                        </InputContainer>

                        <InputContainer>
                            <LabelXS>Discount Amount</LabelXS>
                            <Input v-model="form.discount_amount" />
                            <FormError>{{
                                form.errors.discount_amount
                            }}</FormError>
                        </InputContainer>

                        <InputContainer>
                            <LabelXS>Total Amount</LabelXS>
                            <Input v-model="form.total_amount" />
                            <FormError>{{
                                form.errors.total_amount
                            }}</FormError>
                        </InputContainer>

                        <InputContainer>
                            <LabelXS>Payment Type</LabelXS>
                            <Select
                                filter
                                placeholder="Select a type"
                                :options="paymentTypes"
                                optionLabel="label"
                                optionValue="value"
                                v-model="form.payment_type"
                            ></Select>
                            <FormError>{{
                                form.errors.payment_type
                            }}</FormError>
                        </InputContainer>

                        <InputContainer>
                            <LabelXS>Remarks</LabelXS>
                            <Textarea v-model="form.remarks" />
                            <FormError>{{ form.errors.remarks }}</FormError>
                        </InputContainer>
                    </section>
                </CardHeader>
            </Card>

            <DivFlexCol class="gap-5 col-span-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Menu List</CardTitle>
                        <CardDescription
                            >Please input all the required
                            fields.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="space-y-3 w-full">
                        <DivFlexCol class="sm:items-center gap-3">
                            <InputContainer class="w-full">
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
                            <InputContainer class="w-full">
                                <LabelXS>Quantity</LabelXS>
                                <Input
                                    type="number"
                                    v-model="itemForm.quantity"
                                />
                                <FormError>{{
                                    itemForm.errors.quantity
                                }}</FormError>
                            </InputContainer>
                        </DivFlexCol>
                    </CardContent>
                    <CardFooter class="justify-end">
                        <Button @click="addToItemsList">Add</Button>
                    </CardFooter>
                </Card>

                <TableContainer>
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
            </DivFlexCol>
        </Card>

        <Dialog v-model:open="isImportStoreTransactionModalOpen">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Import Store Transactions</DialogTitle>
                    <DialogDescription>
                        Import the excel file here.
                    </DialogDescription>
                </DialogHeader>
                <InputContainer>
                    <LabelXS> Store Transactions </LabelXS>
                    <Input
                        :disabled="isLoading"
                        type="file"
                        @input="
                            excelFileForm.store_transactions_file =
                                $event.target.files[0]
                        "
                    />
                    <FormError>{{
                        excelFileForm.errors.store_transactions_file
                    }}</FormError>
                </InputContainer>

                <InputContainer>
                    <LabelXS>Store Transactions Template</LabelXS>
                    <ul>
                        <li class="text-xs">
                            Template:
                            <a
                                class="text-blue-500 underline"
                                href="/excel/store-transactions-template"
                                >Click to download</a
                            >
                        </li>
                    </ul>
                </InputContainer>
                <DialogFooter>
                    <Button
                        :disabled="isLoading"
                        @click="importStoreTransactions"
                        class="gap-2"
                    >
                        Proceed
                        <span v-if="isLoading"><Loading /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
