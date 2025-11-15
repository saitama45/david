<script setup>
import { useForm } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";

const toast = useToast();
const confirm = useConfirm();

const handleCreate = () => {
    // Clear previous errors before validation
    form.clearErrors();

    let isValid = true;

    // Client-side validation checks
    if (!form.item_code) {
        form.setError('item_code', 'Item Code is required.');
        isValid = false;
    }
    if (!form.item_name) {
        form.setError('item_name', 'Item Name is required.');
        isValid = false;
    }

    if (!isValid) {
        toast.add({
            severity: "error",
            summary: "Validation Error",
            detail: "Please correct the highlighted fields.",
            life: 3000,
        });
        return; // Stop execution if validation fails
    }

    confirm.require({
        message: "Are you sure you want to create this template?",
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
            form.post(route("month-end-count-templates.store"), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "New Template Successfully Created",
                        life: 3000,
                    });
                },
                onError: (e) => {
                    console.log(e);
                    // You might want to display a more user-friendly error message here
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "Failed to create template. Please check the form.",
                        life: 3000,
                    });
                },
            });
        },
    });
};

const handleCancel = () => {
    router.get(route("month-end-count-templates.index"));
};

const form = useForm({
    item_code: null,
    item_name: null,
    area: null,
    category_2: null,
    category: null,
    brand: null,
    packaging_config: null,
    config: null,
    uom: null,
});

</script>

<template>
    <Layout heading="Create New Template">
        <Card>
            <CardHeader>
                <CardTitle>Template Details</CardTitle>
                <CardDescription>
                    Input all the important fields
                </CardDescription>
            </CardHeader>
            <CardContent>
                <section class="grid sm:grid-cols-2 grid-cols-1 sm:gap-5 gap-3">
                    <InputContainer>
                        <LabelXS>Item Code *</LabelXS>
                        <Input v-model="form.item_code" />
                        <FormError>{{ form.errors.item_code }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Item Name *</LabelXS>
                        <Input v-model="form.item_name" />
                        <FormError>{{ form.errors.item_name }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Area</LabelXS>
                        <Input v-model="form.area" />
                        <FormError>{{ form.errors.area }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Category 2</LabelXS>
                        <Input v-model="form.category_2" />
                        <FormError>{{ form.errors.category_2 }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Category</LabelXS>
                        <Input v-model="form.category" />
                        <FormError>{{ form.errors.category }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Brand</LabelXS>
                        <Input v-model="form.brand" />
                        <FormError>{{ form.errors.brand }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Packaging Config</LabelXS>
                        <Input v-model="form.packaging_config" />
                        <FormError>{{ form.errors.packaging_config }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Config</LabelXS>
                        <Input v-model="form.config" />
                        <FormError>{{ form.errors.config }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>UOM</LabelXS>
                        <Input v-model="form.uom" />
                        <FormError>{{ form.errors.uom }}</FormError>
                    </InputContainer>
                </section>
            </CardContent>
            <CardFooter class="justify-end gap-3">
                <Button @click="handleCancel" variant="outline">Cancel</Button>
                <Button @click="handleCreate" :disabled="form.processing">Create</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>