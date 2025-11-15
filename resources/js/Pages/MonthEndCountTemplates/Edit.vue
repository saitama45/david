<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";

const toast = useToast();
const confirm = useConfirm();

const props = defineProps({
    template: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    item_code: props.template.item_code,
    item_name: props.template.item_name,
    area: props.template.area,
    category_2: props.template.category_2,
    category: props.template.category,
    brand: props.template.brand,
    packaging_config: props.template.packaging_config,
    config: props.template.config,
    uom: props.template.uom,
});

const handleUpdate = () => {
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
        message: "Are you sure you want to update this template?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Update",
            severity: "success",
        },
        accept: () => {
            form.put(route("month-end-count-templates.update", props.template.id), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Template Updated Successfully.",
                        life: 3000,
                    });
                },
                onError: (e) => {
                    console.log(e);
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "Failed to update template. Please check the form.",
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
</script>

<template>
    <Layout heading="Edit Template Details">
        <Card>
            <CardHeader>
                <CardTitle>Template Details</CardTitle>
                <CardDescription>
                    Update template information
                </CardDescription>
            </CardHeader>
            <CardContent>
                <section class="grid sm:grid-cols-2 grid-cols-1 sm:gap-5 gap-3">
                    <InputContainer>
                        <Label>Item Code *</Label>
                        <Input v-model="form.item_code" />
                        <FormError>{{ form.errors.item_code }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label>Item Name *</Label>
                        <Input v-model="form.item_name" />
                        <FormError>{{ form.errors.item_name }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label>Area</Label>
                        <Input v-model="form.area" />
                        <FormError>{{ form.errors.area }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label>Category 2</Label>
                        <Input v-model="form.category_2" />
                        <FormError>{{ form.errors.category_2 }}</FormError>
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
                        <Label>Packaging Config</Label>
                        <Input v-model="form.packaging_config" />
                        <FormError>{{ form.errors.packaging_config }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label>Config</Label>
                        <Input v-model="form.config" />
                        <FormError>{{ form.errors.config }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label>UOM</Label>
                        <Input v-model="form.uom" />
                        <FormError>{{ form.errors.uom }}</FormError>
                    </InputContainer>
                </section>
            </CardContent>
            <CardFooter class="justify-end gap-3">
                <Button @click="handleCancel" variant="outline">Cancel</Button>
                <Button @click="handleUpdate" :disabled="form.processing">Update</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>