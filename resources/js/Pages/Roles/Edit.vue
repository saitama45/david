<script setup>
import Checkbox from "primevue/checkbox";
import { useToast } from "primevue/usetoast";
const toast = useToast();
import { useForm } from "@inertiajs/vue3";
import { computed } from 'vue'; // Import computed for new logic
import ToggleSwitch from 'primevue/toggleswitch'; // Import ToggleSwitch

const props = defineProps({
    permissions: {
        // This prop is now expected to be a nested object:
        // {
        //   'Main Category Label': {
        //     'Sub Category Label': [
        //       { id: 'permission_id_1', name: 'permission name 1' },
        //       { id: 'permission_id_2', name: 'permission name 2' },
        //       ...
        //     ],
        //     ...
        //   },
        //   ...
        // }
        type: Object,
        required: true,
    },
    role: {
        type: Object,
        required: true,
    },
});

// Map the current role's permissions to an array of their IDs (as numbers).
// This is used to pre-select the checkboxes for the role's existing permissions.
// IMPORTANT: Ensure the type here matches the type of `permission.id` in the template.
// If `permission.id` is a number, keep these as numbers. If it's a string, convert to string.
// Assuming permission.id is an integer from the database, we keep it as a number.
const roleCurrentPermissions = props.role.permissions.map((item) =>
    item.id
);

// Initialize the Inertia form with the role's name and its current permissions.
const form = useForm({
    name: props.role.name,
    selectedPermissions: roleCurrentPermissions,
});

// --- New "Check All" Logic ---

// Flatten all permission IDs from the nested permissions prop
const allPermissionIds = computed(() => {
    const ids = [];
    for (const mainCategoryLabel in props.permissions) {
        for (const subCategoryLabel in props.permissions[mainCategoryLabel]) {
            props.permissions[mainCategoryLabel][subCategoryLabel].forEach(permission => {
                ids.push(permission.id);
            });
        }
    }
    return ids;
});

// Computed property for the global "Check All" ToggleSwitch
const globalCheckAll = computed({
    get() {
        // If no permissions, it's unchecked
        if (allPermissionIds.value.length === 0) {
            return false;
        }
        // Return true only if ALL permissions are selected, otherwise false.
        // ToggleSwitch is binary, so no indeterminate state.
        return allPermissionIds.value.every(id =>
            form.selectedPermissions.includes(id)
        );
    },
    set(value) {
        if (value) {
            // Select all permissions
            form.selectedPermissions = [...allPermissionIds.value];
        } else {
            // Deselect all permissions
            form.selectedPermissions = [];
        }
    }
});

// Function to get computed property for each main group's "Check All" ToggleSwitch
const groupCheckAll = (mainCategoryLabel) => computed({
    get() {
        const groupPermissionIds = [];
        for (const subCategoryLabel in props.permissions[mainCategoryLabel]) {
            props.permissions[mainCategoryLabel][subCategoryLabel].forEach(permission => {
                groupPermissionIds.push(permission.id);
            });
        }

        if (groupPermissionIds.length === 0) {
            return false;
        }

        // Return true only if ALL permissions in this group are selected, otherwise false.
        // ToggleSwitch is binary, so no indeterminate state.
        return groupPermissionIds.every(id =>
            form.selectedPermissions.includes(id)
        );
    },
    set(value) {
        const groupPermissionIds = [];
        for (const subCategoryLabel in props.permissions[mainCategoryLabel]) {
            props.permissions[mainCategoryLabel][subCategoryLabel].forEach(permission => {
                groupPermissionIds.push(permission.id);
            });
        }

        if (value) {
            // Add all permissions from this group
            form.selectedPermissions = [...new Set([...form.selectedPermissions, ...groupPermissionIds])];
        } else {
            // Remove all permissions from this group
            form.selectedPermissions = form.selectedPermissions.filter(id =>
                !groupPermissionIds.includes(id)
            );
        }
    }
});

// Function to get computed property for each sub-category's "Check All" ToggleSwitch
const subCategoryCheckAll = (mainCategoryLabel, subCategoryLabel) => computed({
    get() {
        const subCategoryPermissionIds = props.permissions[mainCategoryLabel][subCategoryLabel].map(permission => permission.id);

        if (subCategoryPermissionIds.length === 0) {
            return false;
        }

        // Return true only if ALL permissions in this sub-category are selected, otherwise false.
        return subCategoryPermissionIds.every(id =>
            form.selectedPermissions.includes(id)
        );
    },
    set(value) {
        const subCategoryPermissionIds = props.permissions[mainCategoryLabel][subCategoryLabel].map(permission => permission.id);

        if (value) {
            // Add all permissions from this sub-category
            form.selectedPermissions = [...new Set([...form.selectedPermissions, ...subCategoryPermissionIds])];
        } else {
            // Remove all permissions from this sub-category
            form.selectedPermissions = form.selectedPermissions.filter(id =>
                !subCategoryPermissionIds.includes(id)
            );
        }
    }
});


// --- End New "Check All" Logic ---


// Function to handle updating the role.
const updateRole = () => {
    // Send a PUT request to the 'roles.update' route with the role's ID.
    form.put(route("roles.update", props.role.id), {
        onSuccess: () => {
            // Display a success toast notification on successful update.
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Role Updated Successfully.",
                life: 3000,
            });
        },
        onError: (errors) => {
            // Log the full error object to the console for detailed inspection during development.
            console.error('Role Update Error Details:', errors);

            let detailMessage = "An error occurred while trying to update the role.";
            // If specific validation errors are returned from the backend,
            // format them into a more descriptive message for the toast.
            if (errors && Object.keys(errors).length > 0) {
                detailMessage = Object.values(errors).join(', ');
            }

            // Display an error toast notification.
            toast.add({
                severity: "error",
                summary: "Error",
                detail: detailMessage, // Includes specific validation errors if available
                life: 5000, // Increased display time for the toast
            });
        },
    });
};
</script>

<template>
    <Layout heading="Edit Role">
        <Card class="p-5 space-y-5">
            <!-- Role Name Input -->
            <InputContainer>
                <LabelXS>Name</LabelXS>
                <Input v-model="form.name" />
                <FormError>{{ form.errors.name }}</FormError>
            </InputContainer>

            <!-- Permissions Section -->
            <InputContainer>
                <div class="flex justify-between items-center mb-4">
                    <LabelXS>Permissions</LabelXS>
                    <!-- Global "Check All" ToggleSwitch -->
                    <div class="flex items-center gap-2">
                        <ToggleSwitch
                            inputId="global-check-all"
                            v-model="globalCheckAll"
                        />
                        <label for="global-check-all" class="text-xs text-gray-700 font-bold">Check All (Globally)</label>
                    </div>
                </div>
                <div
                    class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-5"
                >
                    <!-- Loop through the main permission categories (e.g., 'Settings', 'Ordering') -->
                    <div
                        v-for="(subCategories, mainCategoryLabel) in permissions"
                        :key="mainCategoryLabel"
                        class="flex flex-col gap-3"
                    >
                        <!-- Display the main category name and its "Check All" ToggleSwitch -->
                        <div class="flex items-center justify-between">
                            <SpanBold class="text-xs text-blue-700">{{
                                mainCategoryLabel.toUpperCase().replace(/_/g, " ")
                            }}</SpanBold>
                            <div class="flex items-center gap-2">
                                <ToggleSwitch
                                    :inputId="`group-check-all-${mainCategoryLabel}`"
                                    v-model="groupCheckAll(mainCategoryLabel).value"
                                />
                                <label :for="`group-check-all-${mainCategoryLabel}`" class="text-xs text-gray-700">Check All</label>
                            </div>
                        </div>


                        <!-- Loop through sub-categories within each main category -->
                        <div
                            v-for="(permissionList, subCategoryLabel) in subCategories"
                            :key="subCategoryLabel"
                            class="flex flex-col gap-2 pl-2 border-l border-gray-200"
                        >
                            <!-- Display the sub-category name and its "Check All" ToggleSwitch -->
                            <div class="flex items-center justify-between mt-2">
                                <SpanBold class="text-xs text-gray-800">{{
                                    subCategoryLabel
                                }}</SpanBold>
                                <div class="flex items-center gap-2">
                                    <ToggleSwitch
                                        :inputId="`subcategory-check-all-${mainCategoryLabel}-${subCategoryLabel}`"
                                        v-model="subCategoryCheckAll(mainCategoryLabel, subCategoryLabel).value"
                                    />
                                    <label :for="`subcategory-check-all-${mainCategoryLabel}-${subCategoryLabel}`" class="text-xs text-gray-700">Check All</label>
                                </div>
                            </div>


                            <!-- Loop through individual permissions within each sub-category -->
                            <div
                                class="flex items-center gap-3"
                                v-for="permission in permissionList"
                                :key="permission.id"
                            >
                                <!-- Checkbox for each permission -->
                                <Checkbox
                                    :inputId="`permission-${permission.id}`"
                                    v-model="form.selectedPermissions"
                                    :value="permission.id"
                                    name="permissions[]"
                                />
                                <!-- Label for the checkbox, linked by 'for' attribute -->
                                <label
                                    :for="`permission-${permission.id}`"
                                    class="text-xs text-gray-600"
                                >
                                    {{ permission.name }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <FormError>{{ form.errors.selectedPermissions }}</FormError>
            </InputContainer>

            <!-- Action Buttons -->
            <div class="flex justify-end gap-3">
                <BackButton />
                <Button @click="updateRole">Update</Button>
            </div>
        </Card>
    </Layout>
</template>
