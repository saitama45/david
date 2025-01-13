<script setup>
import { useForm } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
const toast = useToast();
const form = useForm({
    name: null,
    supplier_code: null,
    remarks: null,
});

const store = () => {
    form.post(route("suppliers.store"), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Supplier Create Successfully.",
                life: 5000,
            });
            form.reset();
        },
    });
};
</script>

<template>
    <Layout heading="Create New Supplier">
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
            <DivFlexCenter class="justify-end col-span-2 gap-3">
                <BackButton />
                <Button @click="store">Create</Button>
            </DivFlexCenter>
        </Card>
    </Layout>
</template>
