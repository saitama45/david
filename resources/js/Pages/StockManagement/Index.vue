<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { usePage, router, useForm } from "@inertiajs/vue3";

import { throttle } from "lodash";

import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
const confirm = useConfirm();
const { toast } = useToast();
const { products, branches } = defineProps({
    products: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
});

const { options: branchesOptions } = useSelectOptions(branches);

const branchId = ref(
    usePage().props.filters.branchId || branchesOptions.value[0].value
);

let search = ref(usePage().props.filters.search);

watch(branchId, (newValue) => {
    console.log(usePage());
    router.get(
        route("stock-management.index"),
        {
            branchId: newValue,
            search: search.value,
            page: 1,
        },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        }
    );
});

watch(
    search,
    throttle(function (value) {
        router.get(
            route("stock-management.index"),
            {
                search: value,
                branchId: branchId.value,
                page: 1,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

const isLogUsageModalOpen = ref(false);
const isAddQuantityModalOpen = ref(true);

const form = useForm({
    id: null,
    store_branch_id: null,
    quantity: null,
    remarks: null,
});
watch(isLogUsageModalOpen, (value) => {
    if (!value) {
        form.reset();
        form.clearErrors();
    }
});
const openLogUsageModal = (id) => {
    form.id = id;
    form.store_branch_id = branchId.value;
    isLogUsageModalOpen.value = true;
};

const openAddQuantityModal = (id) => {
    form.id = id;
    form.store_branch_id = branchId.value;
    isAddQuantityModalOpen.value = true;
};

const logUsage = () => {
    form.post(route("stock-management.log-usage"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Usaged logged Successfully.",
                life: 5000,
            });
            isLogUsageModalOpen.value = false;
        },
        onError: () => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occured while trying to log the usage.",
                life: 5000,
            });
        },
    });
};

const showDetails = (id) => {
    router.get(
        route("stock-management.show", id),
        {
            branchId: branchId.value,
        },
        {}
    );
};
</script>
<template>
    <Layout heading="Stock Management">
        <TableContainer>
            <DivFlexCenter class="justify-between">
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search..."
                        v-model="search"
                    />
                </SearchBar>
                <Select
                    filter
                    class="min-w-72"
                    placeholder="Select a Supplier"
                    :options="branchesOptions"
                    optionLabel="label"
                    optionValue="value"
                    v-model="branchId"
                >
                </Select>
            </DivFlexCenter>
            <Table>
                <TableHead>
                    <TH>Name</TH>
                    <TH>Code</TH>
                    <TH>UOM</TH>
                    <TH>SOH</TH>
                    <TH>Estimated Used</TH>
                    <TH>Recorded Used</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="product in products.data">
                        <TD>{{ product.name }}</TD>
                        <TD>{{ product.inventory_code }}</TD>
                        <TD>{{ product.uom }}</TD>
                        <TD>{{ product.stock_on_hand }}</TD>
                        <TD
                            >{{ product.estimated_used }}
                            {{ product.ingredient_units }}</TD
                        >
                        <TD>{{ product.recorded_used }}</TD>
                        <TD>
                            <DivFlexCenter class="gap-3">
                                <ShowButton @click="showDetails(product.id)" />
                                <Button
                                    @click="openLogUsageModal(product.id)"
                                    variant="link"
                                    class="text-xs text-orange-500"
                                    >Log Usage</Button
                                >
                                <Button
                                    @click="openAddQuantityModal(product.id)"
                                    variant="link"
                                    class="text-xs text-green-500"
                                    >Add Quantity</Button
                                >
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="products" />
        </TableContainer>

        <Dialog v-model:open="isLogUsageModalOpen">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Log Usage</DialogTitle>
                    <DialogDescription>
                        Please input all the required fields.
                    </DialogDescription>
                </DialogHeader>
                <DivFlexCol class="gap-3">
                    <InputContainer>
                        <LabelXS>Store Branch</LabelXS>
                        <Select
                            filter
                            class="min-w-72"
                            placeholder="Select a Supplier"
                            :options="branchesOptions"
                            optionLabel="label"
                            optionValue="value"
                            v-model="form.store_branch_id"
                        >
                        </Select>
                        <FormError>{{ form.errors.store_branch_id }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Quantity Used</LabelXS>
                        <Input type="number" v-model="form.quantity" />
                        <FormError>{{ form.errors.quantity }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Remarks</LabelXS>
                        <Textarea type="number" v-model="form.remarks" />
                        <FormError>{{ form.errors.remarks }}</FormError>
                    </InputContainer>
                </DivFlexCol>
                <DialogFooter class="justify-end">
                    <Button @click="logUsage">Submit</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isAddQuantityModalOpen">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Add Quantity</DialogTitle>
                    <DialogDescription>
                        Please input all the required fields.
                    </DialogDescription>
                </DialogHeader>
                <DivFlexCol class="gap-3">
                    <InputContainer>
                        <LabelXS>Store Branch</LabelXS>
                        <Select
                            filter
                            class="min-w-72"
                            placeholder="Select a Supplier"
                            :options="branchesOptions"
                            optionLabel="label"
                            optionValue="value"
                            v-model="form.store_branch_id"
                        >
                        </Select>
                        <FormError>{{ form.errors.store_branch_id }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Quantity</LabelXS>
                        <Input type="number" v-model="form.quantity" />
                        <FormError>{{ form.errors.quantity }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Remarks</LabelXS>
                        <Textarea type="number" v-model="form.remarks" />
                        <FormError>{{ form.errors.remarks }}</FormError>
                    </InputContainer>
                </DivFlexCol>
                <DialogFooter class="justify-end">
                    <Button @click="logUsage">Submit</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
