<script setup>
import Checkbox from "primevue/checkbox";

import { useForm } from "@inertiajs/vue3";
const props = defineProps({
    permissions: {
        type: Object,
        required: true,
    },
    role: {
        type: Object,
        required: true,
    },
});

const roleCurrentPermissions = props.role.permissions.map((item) =>
    item.id.toString()
);

const form = useForm({
    name: props.role.name,
    selectedPermissions: roleCurrentPermissions,
});

const updateRole = () => {
    form.put(route("roles.update", props.role.id), {
        onSuccess: () => {
            console.log("test");
        },
        onError: (e) => {
            console.log(e);
        },
    });
};
</script>

<template>
    <Layout heading="Edit Role">
        <Card class="p-5 space-y-5">
            <InputContainer>
                <LabelXS>Name</LabelXS>
                <Input v-model="form.name" />
                <FormError>{{ form.errors.name }}</FormError>
            </InputContainer>
            <InputContainer>
                <DivFlexCenter class="justify-between">
                    <LabelXS> Permissions</LabelXS>
                </DivFlexCenter>
                <div
                    class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5"
                >
                    <DivFlexCol
                        v-for="(label, id) in permissions"
                        :key="id"
                        class="gap-3"
                    >
                        <SpanBold class="text-xs">{{
                            id.toUpperCase().replace(/_/g, " ")
                        }}</SpanBold>
                        <DivFlexCenter
                            class="gap-3"
                            v-for="(data, id) in label"
                        >
                            <Checkbox
                                :inputId="`permission-${id}`"
                                v-model="form.selectedPermissions"
                                :value="id"
                                name="permissions[]"
                            />
                            <label
                                :for="`permission-${id}`"
                                class="text-xs text-gray-600"
                            >
                                {{ data }}
                            </label>
                        </DivFlexCenter>
                    </DivFlexCol>
                </div>
            </InputContainer>
            <DivFlexCenter class="justify-end">
                <Button @click="updateRole">Update</Button>
            </DivFlexCenter>
        </Card>
    </Layout>
</template>
