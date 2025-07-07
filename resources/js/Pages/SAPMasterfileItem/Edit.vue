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
    // inventoryCategories: {
    //     type: Object,
    //     required: true,
    // },
    // unitOfMeasurements: {
    //     type: Object,
    //     required: true,
    // },
    // productCategories: {
    //     type: Object,
    //     required: true,
    // },
    item: {
        type: Object,
        required: true,
    },
});

// const { options: inventoryCategoryOptions } = useSelectOptions(
//     props.inventoryCategories
// );
// const { options: unitOfMeasurementsOptions } = useSelectOptions(
//     props.unitOfMeasurements
// );
// const { options: productCategoriesOptions } = useSelectOptions(
//     props.productCategories
// );

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
    ItemNo: item.ItemNo ?? null,
    ItemDescription: item.ItemDescription ?? null,
    AltQty: item.AltQty ?? 0,
    BaseQty: item.BaseQty ?? 0,
    AltUOM: item.AltUOM ?? null,
    BaseUOM: item.BaseUOM ?? null,
    is_active: item.is_active !== null ? Number(item.is_active) : null,
});

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

const activeStatuses = ref([
    { label: "Active", value: 1 },
    { label: "Inactive", value: 0 },
]);
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
                    <Label>ItemNo</Label>
                    <Input v-model="form.ItemNo" />
                    <FormError>{{ form.errors.ItemNo }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Item Desc</Label>
                    <Input v-model="form.ItemDescription" />
                    <FormError>{{ form.errors.ItemDescription }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Alt Qty</Label>
                    <Input v-model="form.AltQty" type="number"/>
                    <FormError>{{ form.errors.AltQty }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Base Qty</Label>
                    <Input v-model="form.BaseQty" type="number"/>
                    <FormError>{{ form.errors.BaseQty }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Alt UOM</Label>
                    <Input v-model="form.AltUOM"  />
                    <FormError>{{ form.errors.AltUOM }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Base UOM</Label>
                    <Input v-model="form.BaseUOM"  />
                    <FormError>{{ form.errors.BaseUOM }}</FormError>
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
            <CardFooter class="justify-end gap-3">
                <BackButton />
                <Button @click="handleUpdate">Update</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>
