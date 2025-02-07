<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useToast } from "@/Composables/useToast";
const { toast } = useToast();
import { useConfirm } from "primevue/useconfirm";
const confirm = useConfirm();
import { useForm } from "@inertiajs/vue3";

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
const { options: menusOptions } = useSelectOptions(menus);
const { options: branchesOptions } = useSelectOptions(branches);
const excelFileForm = useForm({
    store_transactions_file: null,
});
const isLoading = ref(false);
const isImportStoreTransactionModalOpen = ref(false);
const openImportStoreTransactionModal = () => {
    isImportStoreTransactionModalOpen.value = true;
};

watch(isImportStoreTransactionModalOpen, (value) => {
    if (!value) {
        isLoading.value = false;
    }
});
const importTransactions = () => {
    isLoading.value = true;
    excelFileForm.post(route("store-transactions.import"), {
        onSuccess: () => {
            isLoading.value = false;
            isImportStoreTransactionModalOpen.value = false;
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Store transactions created successfully",
                life: 3000,
            });
        },
        onError: (e) => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: e,
                life: 3000,
            });
        },
    });
};

const itemForm = useForm({
    id: null,
    product_id: null,
    name: null,
    quantity: null,
    discount: 0,
    price: null,
    line_total: null,
    net_total: null,
});

const form = useForm({
    order_date: null,
    lot_serial: null,
    posted: null,
    tim_number: null,
    receipt_number: null,
    store_branch_id: null,
    customer_id: null,
    customer: null,
    items: [],
});

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

watch(
    () => itemForm.id,
    (newValue) => {
        if (newValue == null) return;
        itemForm.clearErrors();
        axios
            .get(route("menu-item.show", newValue))
            .then((res) => res.data)
            .then((result) => {
                console.log(result);
                itemForm.name = result.name;
                itemForm.price = result.price;
                itemForm.product_id = result.product_id;
            })
            .catch((err) => console.log(err));
    }
);

const addToItemsList = () => {
    if (itemForm.id === null) {
        itemForm.setError("id", "Item field is required");
        return;
    }
    if (itemForm.quantity < 1) {
        itemForm.setError("quantity", "Quantity must be atleast 1");
        return;
    }
    console.log(itemForm);
    const existingItemIndex = form.items.findIndex(
        (item) => item.id === itemForm.id
    );
    if (existingItemIndex === -1) {
        itemForm.line_total = parseFloat(itemForm.quantity * itemForm.price);
        itemForm.net_total = parseFloat(itemForm.quantity * itemForm.price);
        form.items.push({ ...itemForm });
    } else {
        const item = form.items[existingItemIndex];
        item.quantity += itemForm.quantity;
        itemForm.line_total = parseFloat(itemForm.quantity * itemForm.price);
        itemForm.net_total = parseFloat(itemForm.quantity * itemForm.price);
    }

    itemForm.reset();
    itemForm.clearErrors();
};

watch(
    () => itemForm.quantity,
    (newQuantity) => {
        if (newQuantity && itemForm.price) {
            itemForm.line_total = parseFloat(newQuantity * itemForm.price);
            const discountAmount =
                (itemForm.discount / 100) * itemForm.line_total;
            itemForm.net_total = parseFloat(
                itemForm.line_total - discountAmount
            );
        }
    }
);

watch(
    () => itemForm.discount,
    (newDiscount) => {
        if (itemForm.line_total) {
            const discountAmount = (newDiscount / 100) * itemForm.line_total;
            itemForm.net_total = parseFloat(
                itemForm.line_total - discountAmount
            );
        }
    }
);

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
        message: "Are you sure you want to create this store transaction?",
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
            form.post(route("store-transactions.store"), {
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Store transaction Created Successfully.",
                        life: 5000,
                    });
                },
                onError: (e) => {
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "Something went wrong while trying to create the store transaction.",
                        life: 5000,
                    });
                },
            });
        },
    });
};

import { useEditQuantity } from "@/Composables/useEditQuantity";
const { isEditQuantityModalOpen, formQuantity, openEditQuantityModal } =
    useEditQuantity();

const editQuantity = () => {
    if (formQuantity.quantity < 0.1) {
        formQuantity.setError("quantity", "Quantity should be more than 0");
        return;
    }

    const index = form.items.findIndex((item) => item.id === formQuantity.id);

    form.items[index].quantity = formQuantity.quantity;
    form.items[index].total_cost = parseFloat(
        form.items[index].quantity * form.items[index].cost
    ).toFixed(2);

    toast.add({
        severity: "success",
        summary: "Success",
        detail: "Quantity Updated",
        life: 3000,
    });

    formQuantity.reset();
    formQuantity.clearErrors();
    isEditQuantityModalOpen.value = false;
};
</script>
<template>
    <Layout
        heading="Create Store Transaction"
        buttonName="Import Store Transactions"
        :hasButton="true"
        :handleClick="openImportStoreTransactionModal"
    >
        <Card class="grid grid-cols-3 gap-3 p-5">
            <Card>
                <CardHeader>
                    <CardTitle>Transaction Details</CardTitle>
                    <CardDescription
                        >Please input all the information
                        required.</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <DivFlexCol class="gap-3">
                        <InputContainer>
                            <LabelXS>Date</LabelXS>
                            <DatePicker v-model="form.order_date" showIcon />
                            <FormError>{{ form.errors.order_date }}</FormError>
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Lot/Serial</LabelXS>
                            <Input v-model="form.lot_serial" />
                            <FormError>{{ form.errors.lot_serial }}</FormError>
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Posted</LabelXS>
                            <Input v-model="form.posted" />
                            <FormError>{{ form.errors.posted }}</FormError>
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>TM#</LabelXS>
                            <Input v-model="form.tim_number" />
                            <FormError>{{ form.errors.tim_number }}</FormError>
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Receipt No.</LabelXS>
                            <Input v-model="form.receipt_number" />
                            <FormError>{{
                                form.errors.receipt_number
                            }}</FormError>
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Branch</LabelXS>
                            <Select
                                v-model="form.store_branch_id"
                                filter
                                placeholder="Select a branch"
                                :options="branchesOptions"
                                optionLabel="label"
                                optionValue="value"
                            ></Select>
                            <FormError>{{
                                form.errors.store_branch_id
                            }}</FormError>
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Customer ID</LabelXS>
                            <Input v-model="form.customer_id" />
                            <FormError>{{ form.errors.customer_id }}</FormError>
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Customer</LabelXS>
                            <Input v-model="form.customer" />
                            <FormError>{{ form.errors.customer }}</FormError>
                        </InputContainer>
                    </DivFlexCol>
                </CardContent>
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
                        <DivFlexCol
                            class="grid grid-cols-2 gap-3 sm:items-center"
                        >
                            <InputContainer class="w-full col-span-2">
                                <LabelXS>Item</LabelXS>
                                <Select
                                    filter
                                    placeholder="Select a Store"
                                    :options="menusOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    v-model="itemForm.id"
                                />
                                <FormError>{{ itemForm.errors.id }}</FormError>
                            </InputContainer>
                            <InputContainer class="w-full">
                                <LabelXS>Price</LabelXS>
                                <Input
                                    disabled
                                    type="number"
                                    v-model="itemForm.price"
                                />
                                <FormError>{{
                                    itemForm.errors.price
                                }}</FormError>
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
                            <InputContainer class="w-full">
                                <LabelXS>Discount</LabelXS>
                                <Input
                                    type="number"
                                    v-model="itemForm.discount"
                                />
                                <FormError>{{
                                    itemForm.errors.discount
                                }}</FormError>
                            </InputContainer>
                            <InputContainer class="w-full">
                                <LabelXS>Line Total</LabelXS>
                                <Input
                                    type="number"
                                    v-model="itemForm.line_total"
                                />
                                <FormError>{{
                                    itemForm.errors.line_total
                                }}</FormError>
                            </InputContainer>
                            <InputContainer class="w-full">
                                <LabelXS>Net Total</LabelXS>
                                <Input
                                    type="number"
                                    v-model="itemForm.net_total"
                                />
                                <FormError>{{
                                    itemForm.errors.net_total
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
                            <SpanBold></SpanBold>
                        </DivFlexCenter>
                    </DivFlexCenter>
                    <Table>
                        <TableHead>
                            <TH>Item</TH>
                            <TH>Price</TH>
                            <TH>Quantity</TH>
                            <TH>Discount</TH>
                            <TH>Line Total</TH>
                            <TH>Net Total</TH>
                            <TH>Actions</TH>
                        </TableHead>
                        <TableBody>
                            <tr v-for="item in form.items">
                                <TD>{{ item.name }}</TD>
                                <TD>{{ item.price }}</TD>
                                <TD>{{ item.quantity }}</TD>
                                <TD>{{ item.discount }}</TD>
                                <TD>{{ item.line_total }}</TD>
                                <TD>{{ item.net_total }}</TD>
                                <TD class="flex gap-3 items-center">
                                    <!-- <LinkButton
                                        @click="
                                            openEditQuantityModal(
                                                item.id,
                                                item.quantity
                                            )
                                        "
                                    >
                                        Edit Quantity
                                    </LinkButton> -->
                                    <DeleteButton
                                        @click="removeItem(item.id)"
                                    />
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

        <Dialog v-model:open="isImportStoreTransactionModalOpen">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Import Store Transactions List</DialogTitle>
                    <DialogDescription>
                        Import the excel file here.
                    </DialogDescription>
                </DialogHeader>

                <InputContainer>
                    <LabelXS> Store Transactions List </LabelXS>
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
                    <Label class="text-xs">Store Transaction Template</Label>
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
                        @click="importTransactions"
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
