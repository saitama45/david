<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
const toast = useToast();
const confirm = useConfirm();

const form = useForm({
    name: null,
    email: null,
    password: null,
    roles: [],
    remarks: null,
    assignedBranches: [],
});

const handleCreate = () => {
    confirm.require({
        message: "Are you sure you want to create this user?",
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
            form.post(route("users.store"), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "New User Successfully Created",
                        life: 3000,
                    });
                },
                onError: (e) => {
                    console.log(e);
                },
            });
        },
    });
};

const props = defineProps({
    roles: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
});
const handleCancel = () => {
    router.get(route("users.index"));
};
const { options: rolesOptions } = useSelectOptions(props.roles);
const { options: branchesOptions } = useSelectOptions(props.branches);
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
                    <MultiSelect
                        filter
                        placeholder="Assign Roles"
                        v-model="form.roles"
                        :options="rolesOptions"
                        optionLabel="label"
                        optionValue="value"
                    ></MultiSelect>
                    <FormError>{{ form.errors.role }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Remarks</Label>
                    <Textarea v-model="form.remarks" />
                    <FormError>{{ form.errors.remarksl }}</FormError>
                </InputContainer>
                <InputContainer v-if="form.roles.includes('so_encoder')">
                    <Label>Assigned Branches</Label>
                    <MultiSelect
                        filter
                        placeholder="Assign Branches"
                        v-model="form.assignedBranches"
                        :options="branchesOptions"
                        optionLabel="label"
                        optionValue="value"
                    >
                    </MultiSelect>
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
