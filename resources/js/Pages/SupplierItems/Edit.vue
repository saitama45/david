<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import MultiSelect from "primevue/multiselect";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { ref, watch } from 'vue'; // Ensure ref and watch are imported

const isImportModalVisible = ref(false); // Consider moving import logic to Index.vue

const importForm = useForm({
    products_file: null,
});

const importFile = () => {
    isLoading.value = true;
    importForm.post(route("SupplierItems.import"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Products Updated Successfully.", // Changed detail message for import
                life: 3000,
            });
            isLoading.value = false;
            isImportModalVisible.value = false; // Close modal on success
        },
        onError: (e) => {
            isLoading.value = false;
            // Display a more specific error if the backend sends it in e.message
            const errorMessage = e.message || "An error occurred while trying to import. Please check file format and logs.";
            toast.add({
                severity: "error",
                summary: "Error",
                detail: errorMessage,
                life: 3000,
            });
            console.error("Import Error:", e); // Log the error for debugging
        },
        onFinish: () => {
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
});

const isLoading = ref(false); // For import functionality

watch(isImportModalVisible, (value) => {
    if (!value) {
        importForm.reset();
        importForm.clearErrors();
        isLoading.value = false;
    }
});

const item = props.item;

// Initialize the form with existing item data, including new columns
const form = useForm({
    ItemCode: item.ItemCode ?? null, // Renamed from ItemNo
    SupplierCode: item.SupplierCode ?? null,
    category: item.category ?? '', // New column, default to empty string
    brand: item.brand ?? '', // New column
    classification: item.classification ?? '', // New column
    packaging_config: item.packaging_config ?? '', // New column
    config: item.config ?? 0, // New column, default to 0 for numbers
    uom: item.uom ?? '', // New column
    cost: item.cost ?? 0, // New column
    srp: item.srp ?? 0, // New column
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
            // Use form.put for update operations
            form.put(route("SupplierItems.update", item.id), {
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
                    console.error("Update Error:", e); // Log the error for debugging
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "Failed to update product. Please check the form data.",
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
    <Layout heading="Edit Supplier Items Details">
        <Card>
            <CardHeader>
                <CardTitle>Supplier Items Details</CardTitle>
                <CardDescription>Input all the important fields</CardDescription>
            </CardHeader>
            <CardContent class="grid sm:grid-cols-2 gap-5">
                <InputContainer>
                    <Label>Item Code</Label>
                    <Input v-model="form.ItemCode" />
                    <FormError>{{ form.errors.ItemCode }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Supplier Code</Label>
                    <Input v-model="form.SupplierCode" />
                    <FormError>{{ form.errors.SupplierCode }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Category</Label>
                    <Input v-model="form.category" />
                    <FormError>{{ form.errors.category }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Brand</Label>
                    <Input v-model="form.brand" />
                    <FormError>{{ form.errors.brand }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Classification</Label>
                    <Input v-model="form.classification" />
                    <FormError>{{ form.errors.classification }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Packaging Config</Label>
                    <Input v-model="form.packaging_config" />
                    <FormError>{{ form.errors.packaging_config }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>UOM</Label>
                    <Input v-model="form.uom" />
                    <FormError>{{ form.errors.uom }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>Cost</Label>
                    <Input type="number" v-model="form.cost" step="0.01" /> <FormError>{{ form.errors.cost }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label>SRP</Label>
                    <Input type="number" v-model="form.srp" step="0.01" /> <FormError>{{ form.errors.srp }}</FormError>
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
                                importForm.products_file =
                                    $event.target.files[0]
                            "
                        />
                        <FormError>{{
                            importForm.errors.products_file
                        }}</FormError>
                    </div>
                    <div class="flex flex-col space-y-1">
                        <Label class="text-xs"
                            >Accepted Products File Format</Label
                        >
                        <ul>
                            <li class="text-xs">
                                <a
                                    class="text-blue-500 underline"
                                    :href="route('excel.SupplierItems-template')"
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
    </Layout>
</template>