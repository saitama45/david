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
    item: {
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
        message: "Are you sure you want to update this product?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "success",
        },
        accept: () => {
            form.post(route("items.update", item.id), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Product Successfully Updated",
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

watch(isImportModalVisible, (value) => {
    if (!value) {
        importForm.reset();
        importForm.clearErrors();
        isLoading.value = false;
    }
});

const item = props.item;
const form = useForm({
    inventory_category_id: item.inventory_category_id?.toString() ?? null,
    unit_of_measurement_id: item.unit_of_measurement_id?.toString() ?? null,
    conversion: item.conversion ?? null,
    packaging: item.packaging ?? null,
    category_a: item.category_a ?? null,
    category_b: item.category_b ?? null,
    name: item.name ?? null,
    inventory_code: item.inventory_code ?? null,
    brand: item.brand,
    cost: item.cost,
    categories:
        item?.product_categories.map((item) => item.id.toString()) ?? [],
});
console.log(item?.product_categories.map((item) => item.id.toString()) ?? []);
console.log(item.product_categories);
const handleUpdate = () => {
    confirm.require({
        message: "Are you sure you want to update this product?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "success",
        },
        accept: () => {
            form.put(route("items.update", item.id), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Product Successfully Updated",
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
</script>

<template>
    <Layout heading="Edit Product Details">
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
                    <Label>Packaging</Label>
                    <Input v-model="form.packaging" />
                    <FormError>{{ form.errors.packaging }}</FormError>
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
                    <Label>Category - A</Label>
                    <Input v-model="form.category_a" />
                    <FormError>{{ form.errors.category_a }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Category - B</Label>
                    <Input v-model="form.category_b" />
                    <FormError>{{ form.errors.category_b }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Cost</Label>
                    <Input v-model="form.cost" type="number" />
                    <FormError>{{ form.errors.cost }}</FormError>
                </InputContainer>
            </CardContent>
            <CardFooter class="justify-end gap-3">
                <BackButton />
                <Button @click="handleUpdate">Update</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>
