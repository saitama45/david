<script setup>
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
// Import Dropdown component from PrimeVue
import Dropdown from 'primevue/dropdown';
// Import Toast component from PrimeVue for displaying messages
import Toast from 'primevue/toast';

const toast = useToast();

const form = useForm({
    name: null,
    supplier_code: null,
    remarks: null,
    is_active: true, // Default to 'Active' when creating a new supplier
});

// Options for the is_active dropdown. Values are booleans.
const statusOptions = [
    { label: 'Active', value: true },
    { label: 'Inactive', value: false },
];

const store = () => {
    form.post(route("suppliers.store"), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Supplier Created Successfully.",
                life: 5000,
            });
            form.reset();
        },
        onError: (errors) => {
            // Display errors if any
            let errorMessage = "Please correct the errors below.";
            if (Object.keys(errors).length > 0) {
                errorMessage = Object.values(errors).join(', ');
            }
            toast.add({
                severity: "error",
                summary: "Error",
                detail: errorMessage,
                life: 5000,
            });
        },
    });
};

</script>

<template>
    <Layout heading="Create New Supplier">
        <Toast /> <!-- Add Toast component here to display messages -->
        <Card class="sm:grid sm:grid-cols-2 gap-5 p-5">
            <InputContainer>
                <LabelXS>Name</LabelXS>
                <Input v-model="form.name" />
                <FormError>{{ form.errors.name }}</FormError>
            </InputContainer>
            <InputContainer>
                <LabelXS>Supplier Code</LabelXS>
                <Input v-model="form.supplier_code" />
                <FormError>{{ form.errors.supplier_code }}</FormError>
            </InputContainer>
            <InputContainer>
                <LabelXS>Remarks</LabelXS>
                <Textarea v-model="form.remarks" />
                <FormError>{{ form.errors.remarks }}</FormError>
            </InputContainer>

            <!-- New InputContainer for is_active dropdown -->
            <InputContainer>
                <LabelXS>Status</LabelXS>
                <Dropdown v-model="form.is_active" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Select Status" class="w-full" />
                <FormError>{{ form.errors.is_active }}</FormError>
            </InputContainer>

            <DivFlexCenter class="justify-end col-span-2 gap-3">
                <BackButton />
                <Button @click="store">Create</Button>
            </DivFlexCenter>
        </Card>
    </Layout>
</template>
