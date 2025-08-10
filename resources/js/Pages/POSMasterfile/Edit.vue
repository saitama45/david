<script setup>
import { useForm } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import Select from "primevue/select";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { ref, watch } from 'vue';
import axios from 'axios';

const toast = useToast();
const confirm = useConfirm(); // Still included as a dependency of toast, but not actively used for ingredients now.

const props = defineProps({
    item: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    products: { // Still passed, but not used for adding/modifying ingredients in this view
        type: Array,
        default: () => [],
    },
    existingIngredients: {
        type: Array,
        default: () => [],
    }
});

const isLoading = ref(false);

// Main form
const item = props.item;
const form = useForm({
    ItemCode: item.ItemCode ?? null,
    ItemDescription: item.ItemDescription ?? null,
    Category: item.Category ?? null,
    SubCategory: item.SubCategory ?? null,
    SRP: item.SRP ?? 0,
    is_active: item.is_active !== null ? Number(item.is_active) : null,
    // ingredients are now only for display; they are not part of this form's submission
    // for FG details update, so they are not included in the main form object.
    // We will keep them as a reactive ref for display purposes.
});

// Reactive reference for displaying ingredients
const ingredientsDisplay = ref([]);

// Initialize ingredientsDisplay with existingIngredients fetched from controller
// Each ingredient from existingIngredients corresponds to a distinct BOM record.
props.existingIngredients.forEach((ingredient) => {
    ingredientsDisplay.value.push({
        id: ingredient.id, // BOM row id
        sap_masterfile_id: ingredient.sap_masterfile_id, // SAP product id (nullable)
        inventory_code: ingredient.inventory_code,
        name: ingredient.name,
        quantity: Number(ingredient.quantity),
        uom: ingredient.uom,
        assembly: ingredient.assembly ?? null,
        unit_cost: ingredient.unit_cost ?? null,
        total_cost: ingredient.total_cost ?? null,
    });
});

const { options: productsOption } = useSelectOptions(props.products); // Still imported but no longer used in this refactored view
const { options: categoriesOption } = useSelectOptions(props.categories);

// Removed ingredientsForm as adding ingredients is no longer part of this view.
// Removed addItemQuantity, minusItemQuantity, removeItem functions as they modify ingredients.
// Removed watch for ingredientsForm.id as ingredient selection/fetch is not needed here.

const handleUpdate = () => {
    confirm.require({
        message: "Are you sure you want to update this product's FG Details?",
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
            // Only send FG details fields
            form.put(route("POSMasterfile.update", item.id), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "FG Details Successfully Updated",
                        life: 3000,
                    });
                },
                onError: (e) => {
                    console.log(e);
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "An error occurred while updating the FG details.",
                        life: 3000,
                    });
                },
            });
        },
    });
};

const activeStatuses = ref([
    { label: "Active", value: 1 },
    { label: "Inactive", value: 0 },
]);
</script>

<template>
    <Layout heading="Edit BOM Details">
        <template #header-actions>
            <BackButton />
        </template>

        <!-- Main container for stacked sections -->
        <div class="flex flex-col gap-5 p-5">
            <!-- FG Details Section - Now occupies full width and is first -->
            <Card class="w-full">
                <CardHeader>
                    <CardTitle>FG Details</CardTitle>
                    <CardDescription>Input all the important fields</CardDescription>
                </CardHeader>
                <CardContent class="grid sm:grid-cols-2 gap-5">
                    <InputContainer>
                        <Label>ItemCode</Label>
                        <Input v-model="form.ItemCode" />
                        <FormError>{{ form.errors.ItemCode }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label>Item Desc</Label>
                        <Input v-model="form.ItemDescription" />
                        <FormError>{{ form.errors.ItemDescription }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label>Category</Label>
                        <Select
                            v-model="form.Category"
                            :options="categoriesOption"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Select a Category"
                        />
                        <FormError>{{ form.errors.Category }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label>Sub Category</Label>
                        <Input v-model="form.SubCategory" />
                        <FormError>{{ form.errors.SubCategory }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label>SRP</Label>
                        <Input v-model="form.SRP" type="number"/>
                        <FormError>{{ form.errors.SRP }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Active Status</LabelXS>
                        <Select
                            v-model="form.is_active"
                            :options="activeStatuses"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Select a Status"
                        />
                        <FormError v-if="form.errors.is_active">
                            {{ form.errors.is_active }}
                        </FormError>
                    </InputContainer>
                </CardContent>
                <CardFooter class="justify-end">
                    <Button @click="handleUpdate">Update FG Details</Button>
                </CardFooter>
            </Card>

            <!-- Ingredients List Table (Read-Only) - Now occupies full width and is second -->
            <TableContainer class="w-full">
                <TableHeader>
                    <SpanBold>Ingredients</SpanBold>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH>Item Code</TH>
                        <TH>Name</TH>
                        <TH>Assembly</TH>
                        <TH>Quantity</TH>
                        <TH>UOM</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="ingredient in ingredientsDisplay" :key="ingredient.id">
                            <TD>{{ ingredient.inventory_code }}</TD>
                            <TD>{{ ingredient.name }}</TD>
                            <TD>{{ ingredient.assembly ?? '-' }}</TD>
                            <TD>{{ ingredient.quantity }}</TD>
                            <TD>{{ ingredient.uom }}</TD>
                        </tr>
                    </TableBody>
                </Table>

                <MobileTableContainer>
                    <MobileTableRow v-for="ingredient in ingredientsDisplay" :key="ingredient.id">
                        <MobileTableHeading
                            :title="`${ingredient.name} (${ingredient.inventory_code})`"
                        >
                        </MobileTableHeading>
                        <LabelXS>Assembly: {{ ingredient.assembly ?? '-' }}</LabelXS>
                        <LabelXS>UOM: {{ ingredient.uom }}</LabelXS>
                        <LabelXS>Quantity: {{ ingredient.quantity }}</LabelXS>
                    </MobileTableRow>
                </MobileTableContainer>
            </TableContainer>
        </div>
    </Layout>
</template>
