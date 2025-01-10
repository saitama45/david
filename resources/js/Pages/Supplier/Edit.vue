<script setup>
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";

const toast = useToast();

const { supplier } = defineProps({
    supplier: {
        type: Object,
        required: true,
    },
});
const form = useForm({
    name: supplier.name,
    supplier_code: supplier.supplier_code,
    remarks: supplier.remarks,
});

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
            form.reset();
        },
    });
};
</script>

<template>
    <Layout heading="Edit Supplier Details">
        <Card class="grid grid-cols-2 gap-5 p-5">
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
            <DivFlexCenter class="justify-end col-span-2">
                <Button @click="update">Update</Button>
            </DivFlexCenter>
        </Card>

        <BackButton />
    </Layout>
</template>
