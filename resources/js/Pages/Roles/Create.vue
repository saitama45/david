<script setup>
import Checkbox from "primevue/checkbox";

import { useForm } from "@inertiajs/vue3";

const form = useForm({
    name: "",
    selectedPermissions: [],
});
const props = defineProps({
    permissions: {
        type: Object,
        required: true,
    },
});

const createNewRoles = () => {
    form.post(route("roles.store"), {});
};
</script>

<template>
    <Layout heading="Create Role">
        <Card class="p-5 space-y-5">
            <InputContainer>
                <LabelXS>Name</LabelXS>
                <Input v-model="form.name" />
                <FormError>{{ form.errors.name }}</FormError>
            </InputContainer>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-3">
                    Permissions
                </label>
                <div
                    class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4"
                >
                    <div
                        v-for="(label, id) in permissions"
                        :key="id"
                        class="flex items-center space-x-2"
                    >
                        <Checkbox
                            :inputId="`permission-${id}`"
                            v-model="form.selectedPermissions"
                            :value="id"
                            name="permissions[]"
                        />
                        <label
                            :for="`permission-${id}`"
                            class="text-sm text-gray-600"
                        >
                            {{ label }}
                        </label>
                    </div>
                </div>
            </div>
            <DivFlexCenter class="justify-end">
                <Button @click="createNewRoles">Create</Button>
            </DivFlexCenter>
        </Card>
    </Layout>
</template>
