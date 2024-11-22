<script setup>
import {
    Card,
    CardContent,
    CardHeader,
    CardDescription,
    CardFooter,
    CardTitle,
} from "@/components/ui/card";
import { useForm, router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import MultiSelect from "primevue/multiselect";

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
    form.post(route("items.store"), {
        preserveScroll: true,
        onSuccess: () => {},
        onError: (e) => {
            console.log(e);
        },
    });
};

const handleCancel = () => {
    router.get(route("items.index"));
};
</script>

<template>
    <Layout heading="Create New Product">
        <Card>
            <CardHeader>
                <CardTitle>Product Details</CardTitle>
                <CardDescription
                    >Input all the important fields</CardDescription
                >
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-5">
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
</template>
