<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
const toast = useToast();
const confirm = useConfirm();

const form = useForm({
    first_name: null,
    middle_name: null,
    last_name: null,
    phone_number: null,
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
                    <Label>First Name</Label>
                    <Input v-model="form.first_name" />
                    <FormError>{{ form.errors.first_name }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Middle Name</Label>
                    <Input v-model="form.middle_name" />
                    <FormError>{{ form.errors.middle_name }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Last Name</Label>
                    <Input v-model="form.last_name" />
                    <FormError>{{ form.errors.last_name }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Phone Number</Label>
                    <Input v-model="form.phone_number" />
                    <FormError>{{ form.errors.phone_number }}</FormError>
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
                    <FormError>{{ form.errors.roles }}</FormError>
                </InputContainer>
                <InputContainer>
                    <Label>Remarks</Label>
                    <Textarea v-model="form.remarks" />
                    <FormError>{{ form.errors.remarks }}</FormError>
                </InputContainer>
                <InputContainer v-if="form.roles.includes('so encoder')">
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
