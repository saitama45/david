<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import MultiSelect from "primevue/multiselect";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";

const isImportModalVisible = ref(false);

const importForm = useForm({
    products_file: null,
});

const importFile = () => {
    isLoading.value = true;
    importForm.post(route("items.import"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "New Products Created",
                life: 3000,
            });
            isLoading.value = false;
        },
        onError: (e) => {
            isLoading.value = false;
        },
    });
};
const toast = useToast();

const confirm = useConfirm();

const form = useForm({
    inventory_category_id: null,
    unit_of_measurement_id: null,
    conversion: null,
    name: null,
    inventory_code: null,
    brand: null,
    cost: null,
    categories: null,
});

const props = defineProps({
    inventoryCategories: {
        type: Object,
        required: true,
    },
    unitOfMeasurements: {
        type: Object,
        required: true,
    },
    productCategories: {
        type: Object,
        required: true,
    },
});

const { options: inventoryCategoryOptions } = useSelectOptions(
    props.inventoryCategories
);
const { options: unitOfMeasurementsOptions } = useSelectOptions(
    props.unitOfMeasurements
);
const { options: productCategoriesOptions } = useSelectOptions(
    props.productCategories
);

const handleCreate = () => {
    confirm.require({
        message: "Are you sure you want to create this product?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Create",
            severity: "success",
        },
        accept: () => {
            form.post(route("items.store"), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "New Product Successfully Created",
                        life: 3000,
                    });
                },
                onError: (e) => {
                    console.log(e);
                },
            });
        },
    });
};
const isLoading = ref(false);
const handleCancel = () => {
    router.get(route("items.index"));
};

const openFormModal = () => {
    return (isImportModalVisible.value = true);
};

watch(isImportModalVisible, (value) => {
    if (!value) {
        importForm.reset();
        importForm.clearErrors();
        isLoading.value = false;
    }
});
</script>

<template>
    <Layout
        heading="Create New Product"
        :hasButton="true"
        buttonName="Import Excel"
        :handleClick="openFormModal"
    >
        <Card>
            <CardHeader>
                <CardTitle>Product Details</CardTitle>
                <CardDescription
                    >Input all the important fields</CardDescription
                >
            </CardHeader>
            <CardContent class="grid sm:grid-cols-2 gap-5">
                <InputContainer>
                    <Label>Inventory Category</Label>
                    <Select
                        filter
                        placeholder="Select inventory category"
                        v-model="form.inventory_category_id"
                        :options="inventoryCategoryOptions"
                        optionLabel="label"
                        optionValue="value"
                    >
                    </Select>
                    <FormError>{{
                        form.errors.inventory_category_id
                    }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Name</Label>
                    <Input v-model="form.name" />
                    <FormError>{{ form.errors.name }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Inventory Code</Label>
                    <Input v-model="form.inventory_code" />
                    <FormError>{{ form.errors.inventory_code }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Brand</Label>
                    <Input v-model="form.brand" />
                    <FormError>{{ form.errors.brand }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Conversion</Label>
                    <Input v-model="form.conversion" type="number" />
                    <FormError>{{ form.errors.conversion }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Unit of measurement</Label>
                    <Select
                        filter
                        placeholder="Select unit of measurement"
                        v-model="form.unit_of_measurement_id"
                        :options="unitOfMeasurementsOptions"
                        optionLabel="label"
                        optionValue="value"
                    >
                    </Select>
                    <FormError>{{
                        form.errors.unit_of_measurement_id
                    }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Cost</Label>
                    <Input v-model="form.cost" type="number" />
                    <FormError>{{ form.errors.cost }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Product Category</Label>
                    <MultiSelect
                        filter
                        placeholder="Select product category"
                        v-model="form.categories"
                        :options="productCategoriesOptions"
                        optionLabel="label"
                        optionValue="value"
                    >
                    </MultiSelect>
                    <FormError>{{ form.errors.categories }}</FormError>
                </InputContainer>
            </CardContent>
            <CardFooter class="justify-end gap-3">
                <Button @click="handleCancel" variant="outline">Cancel</Button>
                <Button @click="handleCreate">Create</Button>
            </CardFooter>
        </Card>
    </Layout>

    <Dialog v-model:open="isImportModalVisible">
        <DialogContent class="sm:max-w-[600px]">
            <DialogHeader>
                <DialogTitle>Import Products</DialogTitle>
                <DialogDescription>
                    Import the excel file of the products.
                </DialogDescription>
            </DialogHeader>
            <div class="space-y-5">
                <div class="flex flex-col space-y-1">
                    <Input
                        type="file"
                        @input="
                            importForm.products_file = $event.target.files[0]
                        "
                    />
                    <FormError>{{ importForm.errors.products_file }}</FormError>
                </div>
                <div class="flex flex-col space-y-1">
                    <Label class="text-xs">Accepted Products File Format</Label>
                    <ul>
                        <li class="text-xs">
                            <a
                                class="text-blue-500 underline"
                                href="/excel/products-template"
                                >Click to download</a
                            >
                        </li>
                    </ul>
                </div>
            </div>
            <DialogFooter>
                <Button
                    :disabled="isLoading"
                    @click="importFile"
                    type="submit"
                    class="gap-2"
                >
                    Proceed
                    <span><Loading v-if="isLoading" /></span>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
