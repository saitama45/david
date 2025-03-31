<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { usePage, router, useForm } from "@inertiajs/vue3";

import { throttle, update } from "lodash";
import { ref } from "vue";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
const confirm = useConfirm();
const { toast } = useToast();
const { products, branches, costCenters } = defineProps({
    products: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
    costCenters: {
        type: Object,
        required: true,
    },
});

const { options: branchesOptions } = useSelectOptions(branches);
const { options: costCentersOptions } = useSelectOptions(costCenters);

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
const isAddQuantityModalOpen = ref(false);

const form = useForm({
    id: null,
    store_branch_id: null,
    cost_center_id: null,
    quantity: null,
    unit_cost: null,
    transaction_date: null,
    remarks: null,
});
watch(isLogUsageModalOpen, (value) => {
    if (!value) {
        form.reset();
        form.clearErrors();
    }
});

watch(isAddQuantityModalOpen, (value) => {
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

const addQuantity = () => {
    form.post(route("stock-management.add-quantity"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Usaged logged Successfully.",
                life: 5000,
            });
            isAddQuantityModalOpen.value = false;
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

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();

const exportRoute = computed(() =>
    route("stock-management.export", {
        search: search.value,
        branchId: branchId.value,
    })
);

const isUpdateModalVisible = ref(false);
const openUpdateModal = () => {
    isUpdateModalVisible.value = true;
};

const options = [
    {
        label: "Add Quantity",
        value: "add-quantity",
    },
    {
        label: "Log Usage",
        value: "log-usage",
    },
];

const updateForm = useForm({
    action: null,
    branch: branchId.value,
    file: null,
});
const action = ref(null);
watch(
    () => updateForm.action,
    (value) => {
        console.log(value);
    }
);

const isLoading = ref(false);

const updateImport = () => {
    const routeLocation =
        updateForm.action == "add-quantity"
            ? "stock-management.import-add"
            : "stock-management.import-log-usage";
 
    updateForm.branch = branchId.value;

    isLoading.value = true;

    axios
        .post(route(routeLocation), updateForm, {
            headers: {
                "Content-Type": "multipart/form-data",
            },
        })
        .then((response) => {
            console.log(response);
            // Check for any import errors
            if (response.data.errors && response.data.errors.length > 0) {
                // Show detailed error messages
                response.data.errors.forEach((error) => {
                    toast.add({
                        severity: "error",
                        summary: "Import Error",
                        detail: error,
                        life: 5000,
                    });
                });

                // If some data was imported successfully
                if (
                    response.data.imported &&
                    response.data.imported.length > 0
                ) {
                    toast.add({
                        severity: "warning",
                        summary: "Partial Import",
                        detail: `Successfully imported ${response.data.imported.length} rows`,
                        life: 3000,
                    });
                }
            } else {
                // Successful import
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: "Stock Updated Successfully.",
                    life: 3000,
                });
            }
        })
        .catch((err) => {
            console.log(err);
            // Handle network or server errors
            toast.add({
                severity: "error",
                summary: "Error",
                detail:
                    err.response?.data?.message ||
                    "An unexpected error occurred during import.",
                life: 3000,
            });
        })
        .finally(() => {
            isUpdateModalVisible.value = false;
            isLoading.value = false;
        });
};

watch(isUpdateModalVisible, (value) => {
    if (!value) {
        updateForm.reset();
        updateForm.clearErrors();
    }
});
</script>
<template>
    <Dialog v-model:open="isUpdateModalVisible">
        <DialogContent class="sm:max-w-[600px]">
            <DialogHeader>
                <DialogTitle>Update Stock</DialogTitle>
                <DialogDescription>
                    Please input all the required fields.
                </DialogDescription>
            </DialogHeader>
            <InputContainer>
                <Label>Action</Label>
                <SelectShad v-model="updateForm.action">
                    <SelectTrigger>
                        <SelectValue placeholder="Select from options" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectLabel>Options</SelectLabel>
                            <SelectItem
                                v-for="variant in options"
                                :value="variant.value"
                            >
                                {{ variant.label }}
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </SelectShad>
            </InputContainer>
            <InputContainer>
                <Label>Store Branch</Label>
                <Select
                    filter
                    class="min-w-72"
                    placeholder="Select a Supplier"
                    :options="branchesOptions"
                    optionLabel="label"
                    optionValue="value"
                    v-model="branchId"
                    disabled
                />
            </InputContainer>
            <InputContainer>
                <Label>Excel File</Label>
                <Input
                    type="file"
                    @input="updateForm.file = $event.target.files[0]"
                />
            </InputContainer>
            <InputContainer>
                <LabelXS>Accepted Excel File Format: </LabelXS>
                <a
                    :href="route('stock-management.export-add')"
                    class="text-xs text-blue-500 underline"
                    >Add Quantity</a
                >

                <a
                    :href="route('stock-management.export-log')"
                    class="text-xs text-blue-500 underline"
                    >Log Usage</a
                >
            </InputContainer>
            <DivFlexCenter class="justify-end">
                <Button :disabled="isLoading" @click="updateImport"
                    >Submit
                    <span class="ml-1" v-if="isLoading"><Loading /></span
                ></Button>
            </DivFlexCenter>
        </DialogContent>
    </Dialog>

    <Layout
        heading="Stock Management"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <TableContainer>
            <DivFlexCenter class="justify-between sm:flex-row flex-col gap-3">
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search..."
                        v-model="search"
                    />
                </SearchBar>
                <DivFlexCenter class="gap-3">
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
                    <Button @click="openUpdateModal">Update Stock</Button>
                </DivFlexCenter>
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
                                <ShowButton
                                    v-if="
                                        hasAccess(
                                            'view stock management history'
                                        )
                                    "
                                    @click="showDetails(product.id)"
                                />
                                <Button
                                    v-if="hasAccess('log stock usage')"
                                    @click="openLogUsageModal(product.id)"
                                    variant="link"
                                    class="text-xs text-orange-500"
                                    >Log Usage</Button
                                >
                                <Button
                                    v-if="hasAccess('add stock quantity')"
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

            <MobileTableContainer>
                <MobileTableRow v-for="product in products.data">
                    <MobileTableHeading
                        :title="`${product.name} (${product.inventory_code})`"
                    >
                        <ShowButton
                            v-if="hasAccess('view stock management history')"
                            @click="showDetails(product.id)"
                        />
                    </MobileTableHeading>
                    <LabelXS>UOM: {{ product.uom }}</LabelXS>
                    <LabelXS>SOH: {{ product.stock_on_hand }}</LabelXS>
                    <LabelXS
                        >Estimated Used: {{ product.estimated_used }}
                        {{ product.ingredient_units }}</LabelXS
                    >
                    <LabelXS
                        >Recorded Used: {{ product.recorded_used }}</LabelXS
                    >
                    <DivFlexCenter class="gap-3">
                        <Button
                            v-if="hasAccess('log stock usage')"
                            @click="openLogUsageModal(product.id)"
                            variant="link"
                            class="text-xs text-orange-500 p-0"
                            >Log Usage</Button
                        >
                        <Button
                            v-if="hasAccess('add stock quantity')"
                            @click="openAddQuantityModal(product.id)"
                            variant="link"
                            class="text-xs text-green-500 p-0"
                            >Add Quantity</Button
                        >
                    </DivFlexCenter>
                </MobileTableRow>
            </MobileTableContainer>
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
                            disabled
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
                        <LabelXS>Cost Center</LabelXS>
                        <SelectShad v-model="form.cost_center_id">
                            <SelectTrigger>
                                <SelectValue
                                    placeholder="Select from choices"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectItem
                                        v-for="costCenter in costCentersOptions"
                                        :value="costCenter.value"
                                    >
                                        {{ costCenter.label }}
                                    </SelectItem>
                                </SelectGroup>
                            </SelectContent>
                        </SelectShad>
                        <FormError>{{ form.errors.cost_center_id }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Quantity Used</LabelXS>
                        <Input type="number" v-model="form.quantity" />
                        <FormError>{{ form.errors.quantity }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Transaction Date</LabelXS>
                        <Input type="date" v-model="form.transaction_date" />
                        <FormError>{{
                            form.errors.transaction_date
                        }}</FormError>
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
                            disabled
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
                        <LabelXS>Unit Cost</LabelXS>
                        <Input type="number" v-model="form.unit_cost" />
                        <FormError>{{ form.errors.unit_cost }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Transaction Date</LabelXS>
                        <Input type="date" v-model="form.transaction_date" />
                        <FormError>{{
                            form.errors.transaction_date
                        }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Remarks</LabelXS>
                        <Textarea type="number" v-model="form.remarks" />
                        <FormError>{{ form.errors.remarks }}</FormError>
                    </InputContainer>
                </DivFlexCol>
                <DialogFooter class="justify-end">
                    <Button @click="addQuantity">Submit</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
