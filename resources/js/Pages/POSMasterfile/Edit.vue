<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import MultiSelect from "primevue/multiselect";
import Select from "primevue/select";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { ref, watch } from 'vue';
import axios from 'axios';

const isImportModalVisible = ref(false);

const importForm = useForm({
    products_file: null,
});

const importFile = () => {
    isLoading.value = true;
    importForm.post(route("POSMasterfile.import"), {
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
    item: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    products: {
        type: Array,
        default: () => [],
    },
    existingIngredients: {
        type: Array,
        default: () => [],
    }
});


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
    ItemCode: item.ItemCode ?? null,
    ItemDescription: item.ItemDescription ?? null,
    Category: item.Category ?? null,
    SubCategory: item.SubCategory ?? null,
    SRP: item.SRP ?? 0,
    is_active: item.is_active !== null ? Number(item.is_active) : null,
    ingredients: [],
});

props.existingIngredients.forEach((ingredient) => {
    form.ingredients.push({
        id: ingredient.id,
        inventory_code: ingredient.ItemCode,
        name: ingredient.ItemDescription,
        quantity: ingredient.quantity,
        uom: ingredient.BaseUOM,
    });
});


const { options: productsOption } = useSelectOptions(props.products);
const { options: categoriesOption } = useSelectOptions(props.categories);

const ingredientsForm = useForm({
    id: "",
    inventory_code: "",
    name: "",
    quantity: "",
    uom: "", // This will now hold AltUOM
});

const addItemQuantity = (id) => {
    const index = form.ingredients.findIndex((item) => item.id === id);
    if (index !== -1) {
        if (form.ingredients[index].quantity < 1) {
            form.ingredients[index].quantity = Number(
                (form.ingredients[index].quantity + 0.1).toFixed(1)
            );
        } else {
            form.ingredients[index].quantity += 1;
        }
    }
};

const minusItemQuantity = (id) => {
    const index = form.ingredients.findIndex((item) => item.id === id);
    if (index !== -1) {
        if (form.ingredients[index].quantity < 1) {
            form.ingredients[index].quantity = Number(
                (form.ingredients[index].quantity - 0.1).toFixed(1)
            );
        } else {
            form.ingredients[index].quantity -= 1;
        }
        if (form.ingredients[index].quantity < 0.1) {
            form.ingredients = form.ingredients.filter((item) => item.id !== id);
            return;
        }
    }
};

watch(
    () => ingredientsForm.id,
    (newValue) => {
        if (newValue) {
            isLoading.value = true;
            axios
                // Corrected route name: 'POSMasterfile.product.show'
                .get(route("POSMasterfile.product.show", newValue))
                .then((response) => response.data)
                .then((result) => {
                    ingredientsForm.name = result.name;
                    ingredientsForm.inventory_code = result.inventory_code;
                    ingredientsForm.uom = result.alt_unit_of_measurement; // Use alt_unit_of_measurement
                })
                .catch((err) => console.log(err))
                .finally(() => (isLoading.value = false));
        } else {
            ingredientsForm.name = "";
            ingredientsForm.inventory_code = "";
            ingredientsForm.uom = "";
        }
    }
);

const removeItem = (id) => {
    confirm.require({
        message:
            "Are you sure you want to remove this from the ingredients list?",
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
            form.ingredients = form.ingredients.filter(
                (item) => item.id !== id
            );
            toast.add({
                severity: "success",
                summary: "Confirmed",
                detail: "Item Removed",
                life: 3000,
            });
        },
    });
};

const addIngredient = () => {
    ingredientsForm.clearErrors();
    if (!ingredientsForm.id) {
        ingredientsForm.setError("id", "Product field is required");
        return;
    }
    if (Number(ingredientsForm.quantity) <= 0) {
        ingredientsForm.setError("quantity", "Quantity must be greater than 0");
        return;
    }

    const existingItemIndex = form.ingredients.findIndex(
        (item) => item.id === ingredientsForm.id
    );

    if (existingItemIndex !== -1) {
        form.ingredients[existingItemIndex].quantity = Number(
            (form.ingredients[existingItemIndex].quantity + Number(ingredientsForm.quantity)).toFixed(1)
        );
    } else {
        form.ingredients.push({
            id: ingredientsForm.id,
            inventory_code: ingredientsForm.inventory_code,
            name: ingredientsForm.name,
            quantity: Number(ingredientsForm.quantity),
            uom: ingredientsForm.uom, // Use AltUOM here
        });
    }

    ingredientsForm.reset();
};

const handleUpdate = () => {
    if (form.ingredients.length < 1) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Please select at least one ingredient before proceeding.",
            life: 5000,
        });
        return;
    }
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
            form.put(route("POSMasterfile.update", item.id), {
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
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "An error occurred while updating the product.",
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
        <!-- Add a slot for header actions, assuming the Layout component supports it -->
        <template #header-actions>
            <BackButton />
        </template>

        <Card class="grid sm:grid-cols-3 gap-5 p-5">
            <DivFlexCol class="gap-5">
                <!-- BOM Details / Product Details Card -->
                <Card>
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
                </Card>

                <!-- Ingredients Add Card -->
                <Card>
                    <CardHeader>
                        <CardTitle>Ingredients</CardTitle>
                        <CardDescription>Please input all the required fields.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-5">
                        <InputContainer>
                            <Label>Product</Label>
                            <Select
                                filter
                                :options="productsOption"
                                v-model="ingredientsForm.id"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Select a Product"
                            ></Select>
                            <FormError>
                                {{ ingredientsForm.errors.id }}
                            </FormError>
                        </InputContainer>
                        <InputContainer>
                            <Label>Product UOM</Label>
                            <Input
                                v-model="ingredientsForm.uom"
                                :disabled="true"
                            />
                        </InputContainer>
                        <InputContainer>
                            <Label>Quantity</Label>
                            <Input
                                type="number"
                                v-model="ingredientsForm.quantity"
                            />
                            <FormError>
                                {{ ingredientsForm.errors.quantity }}
                            </FormError>
                        </InputContainer>
                    </CardContent>
                    <CardFooter class="justify-end">
                        <Button @click="addIngredient">Add</Button>
                    </CardFooter>
                </Card>
            </DivFlexCol>

            <!-- Ingredients List Table -->
            <TableContainer class="sm:col-span-2">
                <TableHeader>
                    <SpanBold>Ingredients</SpanBold>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH>Item Code</TH>
                        <TH>Name</TH>
                        <TH>Quantity</TH>
                        <TH>UOM</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="ingredient in form.ingredients" :key="ingredient.id">
                            <TD>{{ ingredient.inventory_code }}</TD>
                            <TD>{{ ingredient.name }}</TD>
                            <TD>{{ ingredient.quantity }}</TD>
                            <TD>{{ ingredient.uom }}</TD>
                            <TD class="flex gap-3">
                                <button
                                    class="text-red-500"
                                    @click="minusItemQuantity(ingredient.id)"
                                >
                                    <Minus />
                                </button>
                                <button
                                    class="text-green-500"
                                    @click="addItemQuantity(ingredient.id)"
                                >
                                    <Plus />
                                </button>
                                <DeleteButton
                                    @click="removeItem(ingredient.id)"
                                    variant="outline"
                                    class="text-red-500"
                                />
                            </TD>
                        </tr>
                    </TableBody>
                </Table>

                <MobileTableContainer>
                    <MobileTableRow v-for="ingredient in form.ingredients" :key="ingredient.id">
                        <MobileTableHeading
                            :title="`${ingredient.name} (${ingredient.inventory_code})`"
                        >
                            <button
                                class="text-red-500 size-5"
                                @click="minusItemQuantity(ingredient.id)"
                            >
                                <Minus />
                            </button>
                            <button
                                class="text-green-500 size-5"
                                @click="addItemQuantity(ingredient.id)"
                            >
                                <Plus />
                            </button>
                            <DeleteButton
                                @click="removeItem(ingredient.id)"
                                variant="outline"
                                class="text-red-500"
                            />
                        </MobileTableHeading>
                        <LabelXS>UOM: {{ ingredient.uom }}</LabelXS>
                        <LabelXS>Quantity: {{ ingredient.quantity }}</LabelXS>
                    </MobileTableRow>
                </MobileTableContainer>
                <DivFlexCenter class="justify-end">
                    <Button @click="handleUpdate">Update</Button>
                </DivFlexCenter>
            </TableContainer>
        </Card>
    </Layout>
</template>
