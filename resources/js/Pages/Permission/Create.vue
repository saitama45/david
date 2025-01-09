<script setup>
import { useForm } from "@inertiajs/vue3";

const form = useForm({
    name: null,
});
const createNewPermission = () => {
    form.post(route("permissions.store"), {});
};
</script>

<template>
    <Layout heading="Create Permission">
        <Card class="p-5 space-y-5">
            <InputContainer>
                <LabelXS>Name</LabelXS>
                <Input v-model="form.name" />
                <FormError>{{ form.errors.name }}</FormError>
            </InputContainer>
            <DivFlexCenter class="justify-end">
                <Button @click="createNewPermission">Create</Button>
            </DivFlexCenter>
        </Card>
    </Layout>
</template>
