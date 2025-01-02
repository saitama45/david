<script setup>
import { router, useForm } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";

const confirm = useConfirm();
const { toast } = useToast();

const isLoading = ref(false);

const { menu, ingredients, categories, products } = defineProps({
    menu: {
        type: Object,
        required: true,
    },
    ingredients: {
        type: Object,
        required: true,
    },
    categories: {
        type: Object,
        required: true,
    },
    products: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: menu.name,
    price: menu.price,
    category_id: menu.category_id + "",
    remarks: menu.remarks,
    ingredients: [],
});

const { options: productsOption } = useSelectOptions(products);
const { options: categoriesOption } = useSelectOptions(categories);

ingredients.forEach((item) => {
    const ingredient = {
        id: item.id,
        inventory_code: item.inventory_code,
        name: item.name,
        quantity: item.quantity,
        uom: item.uom,
    };

    form.ingredients.push(ingredient);
});

const ingredientsForm = useForm({
    id: "",
    inventory_code: "",
    name: "",
    quantity: "",
    uom: "",
});

const addItemQuantity = (id) => {
    const index = form.ingredients.findIndex((item) => item.id === id);
    if (form.ingredients[index].quantity < 1) {
        form.ingredients[index].quantity = Number(
            (form.ingredients[index].quantity + 0.1).toFixed(1)
        );
    } else {
        form.ingredients[index].quantity += 1;
    }
};

const minusItemQuantity = (id) => {
    const index = form.ingredients.findIndex((item) => item.id === id);
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
};

watch(
    () => ingredientsForm.id,
    (newValue) => {
        if (newValue) {
            isLoading.value = true;
            axios
                .get(route("product.show", newValue))
                .then((response) => response.data)
                .then((result) => {
                    ingredientsForm.name = result.name;
                    ingredientsForm.inventory_code = result.inventory_code;
                    ingredientsForm.uom = result.unit_of_measurement;
                })
                .catch((err) => console.log(err))
                .finally(() => (isLoading.value = false));
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
    if (Number(ingredientsForm.quantity) < 0.1) {
        ingredientsForm.setError("quantity", "Quantity must be at least 1");
        return;
    }

    const existingItemIndex = form.ingredients.findIndex(
        (item) => item.id === ingredientsForm.id
    );

    if (existingItemIndex !== -1) {
        form.ingredients[existingItemIndex].quantity += Number(
            ingredientsForm.quantity
        );
    } else {
        form.ingredients.push({ ...ingredientsForm });
    }

    ingredientsForm.reset();
};

const update = () => {};
</script>
<template>
    <Layout heading="Edit Menu">
        <Card class="grid grid-cols-3 gap-5 p-5">
            <DivFlexCol class="gap-5">
                <Card>
                    <CardHeader>
                        <CardTitle>Menu Details</CardTitle>
                        <CardDescription
                            >Please input all the required
                            fields.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="grid grid-cols-2 gap-5">
                        <InputContainer>
                            <Label>Name</Label>
                            <Input v-model="form.name" />
                            <FormError>{{ form.errors.name }}</FormError>
                        </InputContainer>
                        <InputContainer>
                            <Label>Price</Label>
                            <Input type="number" v-model="form.price" />
                            <FormError>{{ form.errors.price }}</FormError>
                        </InputContainer>
                        <InputContainer>
                            <Label>Category</Label>
                            <Select
                                filter
                                :options="categoriesOption"
                                v-model="form.category_id"
                                optionLabel="label"
                                optionValue="value"
                            >
                            </Select>
                            <FormError>{{ form.errors.category_id }}</FormError>
                        </InputContainer>
                        <InputContainer>
                            <Label>Remarks</Label>
                            <Textarea v-model="form.remarks" />
                        </InputContainer>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Ingredients</CardTitle>
                        <CardDescription
                            >Please input all the required
                            fields.</CardDescription
                        >
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
                            >
                            </Select>
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

            <TableContainer class="col-span-2">
                <TableHeader>
                    <SpanBold>Ingredients</SpanBold>
                </TableHeader>
                <Table>
                    <TableHead>
                        <TH>Inventory Code</TH>
                        <TH>Name</TH>
                        <TH>Quantity</TH>
                        <TH>UOM</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="ingredient in form.ingredients">
                            <TD>
                                {{ ingredient.inventory_code }}
                            </TD>
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
                <DivFlexCenter class="justify-end">
                    <Button @click="update">Create</Button>
                </DivFlexCenter>
            </TableContainer>
        </Card>
    </Layout>
</template>
