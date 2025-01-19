<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useToast } from "@/Composables/useToast";
const { toast } = useToast();
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
    name: null,
    quantity: null,
    discount: 0,
    price: null,
    line_total: null,
    net_total: null,
});

const form = useForm({
    items: [],
});

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
</script>
<template>
    <Layout
        heading="Create Store Transactions"
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
                            <LabelXS>Lot/Serial</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Posted</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>TM#</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Receipt No.</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Branch</LabelXS>
                            <Select
                                filter
                                placeholder="Select a branch"
                                :options="branchesOptions"
                                optionLabel="label"
                                optionValue="value"
                            ></Select>
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Lot/Serial</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Customer ID</LabelXS>
                            <Input />
                        </InputContainer>
                        <InputContainer>
                            <LabelXS>Customer</LabelXS>
                            <Input />
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
                            </tr>
                        </TableBody>
                    </Table>
                    <DivFlexCenter class="justify-end">
                        <Button>Proceed</Button>
                    </DivFlexCenter>
                </TableContainer>
            </DivFlexCol>
        </Card>

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
