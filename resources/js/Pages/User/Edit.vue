<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { computed } from 'vue';
import InputMask from 'primevue/inputmask';
import ToggleSwitch from 'primevue/toggleswitch';

const toast = useToast();
const confirm = useConfirm();

const props = defineProps({
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
    suppliers: {
        type: Object,
        required: true,
    },
});

console.log('Raw branches prop:', props.branches);
console.log('Raw suppliers prop:', props.suppliers);

const { user, roles, branches, suppliers } = props;

const userCurrentRoles = Array.isArray(user.roles) ? user.roles.map((role) => role.id.toString()) : [];
// CRITICAL FIX: Ensure assignedSuppliers are mapped by their 'value' (supplier_code) from options
// This assumes your suppliersOptions are structured as { label: '...', value: 'supplier_code' }
const userCurrentAssignedSuppliers = Array.isArray(user.suppliers)
    ? user.suppliers.map((supplier) => supplier.supplier_code)
    : [];

const form = useForm({
    first_name: user.first_name,
    middle_name: user.middle_name,
    last_name: user.last_name,
    phone_number: user.phone_number,
    email: user.email,
    password: null, // Password is null by default, only set if user types
    roles: userCurrentRoles,
    remarks: user.remarks,
    assignedBranches: user.store_branches.map((item) => item.id.toString()), // Assuming store_branches are always an array
    assignedSuppliers: userCurrentAssignedSuppliers,
});

const { options: rolesOptions } = useSelectOptions(roles);
const { options: branchesOptions } = useSelectOptions(branches);
const { options: suppliersOptions } = useSelectOptions(suppliers);

console.log('branchesOptions.value after composable:', branchesOptions.value);
console.log('suppliersOptions.value after composable:', suppliersOptions.value);

const isAllBranchesChecked = computed({
    get: () => {
        const totalOptionsCount = branchesOptions.value.length;
        return form.assignedBranches.length === totalOptionsCount && totalOptionsCount > 0;
    },
    set: (value) => {
        if (value) {
            form.assignedBranches = branchesOptions.value.map(branch => branch.value);
        } else {
            form.assignedBranches = [];
        }
    }
});

const isAllSuppliersChecked = computed({
    get: () => {
        const totalOptionsCount = suppliersOptions.value.length;
        return form.assignedSuppliers.length === totalOptionsCount && totalOptionsCount > 0;
    },
    set: (value) => {
        if (value) {
            form.assignedSuppliers = suppliersOptions.value.map(supplier => supplier.value);
        } else {
            form.assignedSuppliers = [];
        }
    }
});

const handleUpdate = () => {
    // Clear previous errors before validation
    form.clearErrors();

    let isValid = true;

    // Client-side validation checks
    if (!form.first_name) {
        form.setError('first_name', 'First name is required.');
        isValid = false;
    }
    if (!form.last_name) {
        form.setError('last_name', 'Last name is required.');
        isValid = false;
    }
    if (!form.email) {
        form.setError('email', 'Email is required.');
        isValid = false;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
        form.setError('email', 'Invalid email format.');
        isValid = false;
    }
    // Password is optional for update, but if provided, it must meet length requirements
    if (form.password && form.password.length < 8) {
        form.setError('password', 'Password must be at least 8 characters if provided.');
        isValid = false;
    }
    if (!form.phone_number || form.phone_number.replace(/[^0-9]/g, '').length !== 11) {
        form.setError('phone_number', 'Phone number is required and must be 11 digits (e.g., 09xx xxx xxxx).');
        isValid = false;
    }
    if (!form.roles || form.roles.length === 0) {
        form.setError('roles', 'At least one role must be assigned.');
        isValid = false;
    }

    if (!isValid) {
        toast.add({
            severity: "error",
            summary: "Validation Error",
            detail: "Please correct the highlighted fields.",
            life: 3000,
        });
        return; // Stop execution if validation fails
    }

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
            // --- DEBUG LOG START ---
            console.log('Form data being submitted (Edit):', form.data());
            // --- DEBUG LOG END ---
            form.post(route("users.update", user.id), {
                _method: 'put',
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
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "Failed to update user. Please check the form.",
                        life: 3000,
                    });
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
            <CardContent>
                <section class="grid sm:grid-cols-2 grid-cols-1 sm:gap-5 gap-3">
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
                        <InputMask v-model="form.phone_number" mask="9999 999 9999" placeholder="xxxx xxx xxxx" />
                        <FormError>{{ form.errors.phone_number }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label>Email</Label>
                        <Input v-model="form.email" type="email" />
                        <FormError>{{ form.errors.email }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label>Password (Leave blank to keep current)</Label>
                        <Input v-model="form.password" type="password" />
                        <FormError>{{ form.errors.password }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <Label>Roles</Label>
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

                    <InputContainer class="sm:col-span-2">
                        <div class="flex items-center space-x-2 mb-2">
                            <ToggleSwitch v-model="isAllBranchesChecked" id="editCheckAllBranches" />
                            <label for="editCheckAllBranches" class="text-sm font-medium text-gray-700">Check All Branches</label>
                        </div>
                        <LabelXS> Assign Branches </LabelXS>
                        <div
                            class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4"
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
                                <label class="text-xs text-gray-600">
                                    {{ branch.label }}
                                </label>
                            </div>
                        </div>
                        <FormError>{{
                            form.errors.assignedBranches
                        }}</FormError>
                    </InputContainer>

                    <InputContainer class="sm:col-span-2">
                        <div class="flex items-center space-x-2 mb-2">
                            <ToggleSwitch v-model="isAllSuppliersChecked" id="editCheckAllSuppliers" />
                            <label for="editCheckAllSuppliers" class="text-sm font-medium text-gray-700">Check All Suppliers</label>
                        </div>
                        <LabelXS> Assign Suppliers </LabelXS>
                        <div
                            class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4"
                        >
                            <div
                                v-for="supplier in suppliersOptions"
                                :key="supplier.value"
                                class="flex items-center space-x-2"
                            >
                                <Checkbox
                                    v-model="form.assignedSuppliers"
                                    :value="supplier.value"
                                    name="assignedSuppliers[]"
                                />
                                <label class="text-xs text-gray-600">
                                    {{ supplier.label }}
                                </label>
                            </div>
                        </div>
                        <FormError>{{
                            form.errors.assignedSuppliers
                        }}</FormError>
                    </InputContainer>
                </section>
            </CardContent>
            <CardFooter class="justify-end gap-3">
                <Button @click="handleCancel" variant="outline">Cancel</Button>
                <Button @click="handleUpdate" :disabled="form.processing">Update</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>
