<script setup>
import { useForm, router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "primevue/usetoast";
import { computed } from 'vue';
import InputMask from 'primevue/inputmask'; // Import InputMask
import ToggleSwitch from 'primevue/toggleswitch'; // Import ToggleSwitch for consistency

const toast = useToast();
const confirm = useConfirm();

const handleCreate = () => {
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
    if (!form.password) {
        form.setError('password', 'Password is required.');
        isValid = false;
    } else if (form.password.length < 8) {
        form.setError('password', 'Password must be at least 8 characters.');
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
                    // You might want to display a more user-friendly error message here
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: "Failed to create user. Please check the form.",
                        life: 3000,
                    });
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
    suppliers: {
        type: Object,
        required: true,
    },
    user: {
        type: Object,
        required: false,
    },
});
const handleCancel = () => {
    router.get(route("users.index"));
};

const { options: rolesOptions } = useSelectOptions(props.roles);
const { options: branchesOptions } = useSelectOptions(props.branches);
const { options: suppliersOptions } = useSelectOptions(props.suppliers);

const form = useForm({
    first_name: null,
    middle_name: null,
    last_name: null,
    phone_number: null,
    email: null,
    password: null,
    roles: props.user?.roles.map((item) => item.id.toString()) ?? [],
    remarks: null,
    assignedBranches:
        props.user?.store_branches.map((item) => item.id.toString()) ?? [],
    assignedSuppliers:
        props.user?.suppliers.map((item) => item.id.toString()) ?? [], // Assuming suppliersOptions values are IDs
});

// Computed property for "Check All Branches" state (getter/setter for ToggleSwitch)
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

// Computed property for "Check All Suppliers" state (getter/setter for ToggleSwitch)
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
            <CardContent>
                <section class="grid sm:grid-cols-2 grid-cols-1 sm:gap-5 gap-3">
                    <InputContainer>
                        <LabelXS>First Name</LabelXS>
                        <Input v-model="form.first_name" />
                        <FormError>{{ form.errors.first_name }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Middle Name</LabelXS>
                        <Input v-model="form.middle_name" />
                        <FormError>{{ form.errors.middle_name }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Last Name</LabelXS>
                        <Input v-model="form.last_name" />
                        <FormError>{{ form.errors.last_name }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Phone Number</LabelXS>
                        <!-- Using InputMask for phone number -->
                        <InputMask v-model="form.phone_number" mask="9999 999 9999" placeholder="09xx xxx xxxx" />
                        <FormError>{{ form.errors.phone_number }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Email</LabelXS>
                        <Input v-model="form.email" type="email" />
                        <FormError>{{ form.errors.email }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Password</LabelXS>
                        <Input v-model="form.password" type="password" />
                        <FormError>{{ form.errors.password }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Roles</LabelXS>
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
                        <LabelXS>Remarks</LabelXS>
                        <Textarea v-model="form.remarks" />
                        <FormError>{{ form.errors.remarks }}</FormError>
                    </InputContainer>

                    <InputContainer class="sm:col-span-2">
                        <div class="flex items-center space-x-2 mb-2">
                            <!-- Using ToggleSwitch for "Check All Branches" -->
                            <ToggleSwitch v-model="isAllBranchesChecked" id="checkAllBranches" />
                            <label for="checkAllBranches" class="text-sm font-medium text-gray-700">Check All Branches</label>
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

                    <!-- New section for Assign Suppliers -->
                    <InputContainer class="sm:col-span-2">
                        <div class="flex items-center space-x-2 mb-2">
                            <!-- Using ToggleSwitch for "Check All Suppliers" -->
                            <ToggleSwitch v-model="isAllSuppliersChecked" id="checkAllSuppliers" />
                            <label for="checkAllSuppliers" class="text-sm font-medium text-gray-700">Check All Suppliers</label>
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
                <Button @click="handleCreate" :disabled="form.processing">Create</Button>
            </CardFooter>
        </Card>
    </Layout>
</template>
