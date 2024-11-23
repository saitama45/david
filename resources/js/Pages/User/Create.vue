<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
const form = useForm({
    name: null,
    email: null,
    password: null,
    role: null,
    remarks: null,
});

const props = defineProps({
    roles: {
        type: Object,
        required: true,
    },
});
const handleCancel = () => {
    router.get(route("users.index"));
};
const { options: rolesOptions } = useSelectOptions(props.roles);
</script>
<template>
    <Layout heading="Create New User">
        <Card>
            <CardHeader>
                <CardTitle>User Details</CardTitle>
                <CardDescription
                    >Input all the important fields</CardDescription
                >
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-5">
                <InputContainer>
                    <Label>Name</Label>
                    <Input v-model="form.name" />
                    <FormError>{{ form.errors.name }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Email</Label>
                    <Input v-model="form.email" type="email" />
                    <FormError>{{ form.errors.email }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Role</Label>
                    <Select
                        filter
                        placeholder="Select Role"
                        v-model="form.role"
                        :options="rolesOptions"
                        optionLabel="label"
                        optionValue="value"
                    >
                    </Select>
                    <FormError>{{ form.errors.role }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Remarks</Label>
                    <Textarea v-model="form.remarks" />
                    <FormError>{{ form.errors.remarksl }}</FormError>
                </InputContainer>
            </CardContent>
            <CardFooter class="justify-end gap-3">
                <Button @click="handleCancel" variant="outline">Cancel</Button>
                <Button @click="handleCreate">Create</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>
