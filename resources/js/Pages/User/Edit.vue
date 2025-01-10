<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
const toast = useToast();
const confirm = useConfirm();
const { user, roles, branches } = defineProps({
    user: {
        type: Object,
        required: true,
    },
    roles: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
});

const userCurrentRoles = user.roles.map((role) => role.id + "") ?? [];

const userCurrentAssignedBranches =
    user.store_branches.map((role) => role.id + "") ?? [];

const form = useForm({
    first_name: user.first_name,
    middle_name: user.middle_name,
    last_name: user.last_name,
    phone_number: user.phone_number,
    email: user.email,
    roles: userCurrentRoles,
    remarks: user.remarks,
    assignedBranches: userCurrentAssignedBranches,
});
const { options: rolesOptions } = useSelectOptions(roles);
const { options: branchesOptions } = useSelectOptions(branches);

console.log(rolesOptions);

const handleUpdate = () => {
    confirm.require({
        message: "Are you sure you want to update the user details?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Update",
            severity: "success",
        },
        accept: () => {
            form.post(route("users.update", user.id), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "User Details Updated Successfully.",
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

const handleCancel = () => {
    router.get(route("users.index"));
};
</script>

<template>
    <Layout heading="Edit User Details">
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
                    <FormError>{{ form.errors.remarksl }}</FormError>
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
                    <FormError>{{ form.errors.remarks }}</FormError>
                </InputContainer>

                <InputContainer class="col-span-2">
                    <LabelXS> Assign Branches </LabelXS>
                    <div
                        class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4"
                    >
                        <div
                            v-for="branch in branchesOptions"
                            :key="branch.value"
                            class="flex items-center space-x-2"
                        >
                            <Checkbox
                                v-model="form.assignedBranches"
                                :value="branch.value"
                                name="assignedBranches[]"
                            />
                            <label class="text-sm text-gray-600">
                                {{ branch.label }}
                            </label>
                        </div>
                    </div>
                    <FormError>{{ form.errors.assignedBranches }}</FormError>
                </InputContainer>
            </CardContent>
            <CardFooter class="justify-end gap-3">
                <Button @click="handleCancel" variant="outline">Cancel</Button>
                <Button @click="handleUpdate">Update</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>
