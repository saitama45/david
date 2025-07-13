<script setup>
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
// Import Dropdown component from PrimeVue
import Dropdown from 'primevue/dropdown';
// Import Toast component from PrimeVue for displaying messages
import Toast from 'primevue/toast';


const toast = useToast();

const { supplier } = defineProps({
    supplier: {
        type: Object,
        required: true,
    },
});

// Initialize form with existing supplier data.
// Convert supplier.is_active to a number first, then use strict equality to map to boolean.
// This ensures that "0" or 0 maps to false, and "1" or 1 maps to true.
const form = useForm({
    name: supplier.name,
    supplier_code: supplier.supplier_code,
    remarks: supplier.remarks,
    is_active: Number(supplier.is_active) === 1, // Convert to number, then strictly compare to 1
});

// Options for the is_active dropdown. Values are booleans to match form.is_active.
const statusOptions = [
    { label: 'Active', value: true },
    { label: 'Inactive', value: false },
];

const update = () => {
    form.put(route("suppliers.update", supplier.id), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Supplier Updated Successfully.",
                life: 5000,
            });
            // Optionally reset the form or just leave the updated values
            // form.reset(); // Commented out as resetting might not be desired after an update
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
        }
    });
};
</script>

<template>
    <Layout heading="Edit Supplier Details">
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

            <!-- InputContainer for is_active dropdown -->
            <InputContainer>
                <LabelXS>Status</LabelXS>
                <!-- Dropdown binds to form.is_active (boolean) and uses statusOptions with boolean values -->
                <Dropdown v-model="form.is_active" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Select Status" class="w-full" />
                <FormError>{{ form.errors.is_active }}</FormError>
            </InputContainer>

            <DivFlexCenter class="justify-end col-span-2 gap-3">
                <BackButton />
                <Button @click="update">Update</Button>
            </DivFlexCenter>
        </Card>

        <!-- The BackButton here seems redundant if it's already inside the Card.
             Consider removing one if it's not intended to be duplicated. -->
        <!-- <BackButton /> -->
    </Layout>
</template>
