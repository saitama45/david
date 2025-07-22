<script setup>
import { ref, computed } from "vue";
import Checkbox from "primevue/checkbox";
import Dialog from "primevue/dialog";
import { useToast } from "primevue/usetoast";
const toast = useToast();

import { CircleHelp } from "lucide-vue-next";
import ToggleSwitch from 'primevue/toggleswitch'; // Import ToggleSwitch

import { useForm } from "@inertiajs/vue3";
import BackButton from "@/Components/BackButton.vue"; // Re-added import for BackButton

const form = useForm({
    name: "",
    selectedPermissions: [], // This will hold an array of permission IDs
});

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
});

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


/**
 * Handles the creation of a new role by submitting the form.
 * Displays success or error toasts based on the API response.
 */
const createNewRoles = () => {
    form.post(route("roles.store"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "New Role Successfully Created",
                life: 3000,
            });
            form.reset(); // Reset form after successful submission
        },
        onError: (errors) => {
            console.error('Role Creation Error Details:', errors);

            let detailMessage = "An error occurred while trying to create a new role.";
            if (errors && Object.keys(errors).length > 0) {
                detailMessage = Object.values(errors).join(', ');
            }

            toast.add({
                severity: "error",
                summary: "Error",
                detail: detailMessage,
                life: 5000,
            });
        },
    });
};

const isPermissionGuideModalVisible = ref(false);
</script>

<template>
    <Layout heading="Create Role">
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
                    <button @click="isPermissionGuideModalVisible = true">
                        <CircleHelp />
                    </button>
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
            <DivFlexCenter class="justify-end gap-3">
                <BackButton />
                <Button @click="createNewRoles">Create</Button>
            </DivFlexCenter>
        </Card>

        <Dialog
            v-model:visible="isPermissionGuideModalVisible"
            modal
            header="Permissions Guide"
            :style="{ width: '50rem' }"
            :breakpoints="{ '1199px': '75vw', '575px': '90vw' }"
        >
            <section class="grid grid-cols-2 gap-5">
                <!-- Roles -->
                <SpanBold class="col-span-2">Roles</SpanBold>
                <InputContainer>
                    <Label>View Roles</Label>
                    <LabelXS
                        >- User can view the list of roles in the
                        system.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Create Roles</Label>
                    <LabelXS
                        >- User can create new roles with specific
                        permissions.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Edit Roles</Label>
                    <LabelXS
                        >- User can modify the details and permissions of
                        existing roles.</LabelXS
                    >
                </InputContainer>

                <!-- DTS Delivery Schedules -->
                <SpanBold class="col-span-2 mt-4"
                    >DTS Delivery Schedules</SpanBold
                >
                <InputContainer>
                    <Label>View DTS Delivery Schedules</Label>
                    <LabelXS
                        >- User can view the delivery schedules in DTS.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Edit DTS Delivery Schedules</Label>
                    <LabelXS
                        >- User can edit delivery schedules in DTS.</LabelXS
                    >
                </InputContainer>

                <!-- Store Orders -->
                <SpanBold class="col-span-2 mt-4">Store Orders</SpanBold>
                <InputContainer>
                    <Label>View Store Orders</Label>
                    <LabelXS
                        >- User can view the list of all store orders.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Create Store Orders</Label>
                    <LabelXS>- User can create new store orders.</LabelXS>
                </InputContainer>
                <InputContainer>
                    <Label>Edit Store Orders</Label>
                    <LabelXS>- User can edit existing store orders.</LabelXS>
                </InputContainer>
                <InputContainer>
                    <Label>View Store Order</Label>
                    <LabelXS
                        >- User can view the details of a specific store
                        order.</LabelXS
                    >
                </InputContainer>

                <!-- DTS Orders -->
                <SpanBold class="col-span-2 mt-4">DTS Orders</SpanBold>
                <InputContainer>
                    <Label>View DTS Orders</Label>
                    <LabelXS>- User can view the list of DTS orders.</LabelXS>
                </InputContainer>
                <InputContainer>
                    <Label>Create DTS Orders</Label>
                    <LabelXS>- User can create new DTS orders.</LabelXS>
                </InputContainer>
                <InputContainer>
                    <Label>Edit DTS Orders</Label>
                    <LabelXS>- User can edit existing DTS orders.</LabelXS>
                </InputContainer>
                <InputContainer>
                    <Label>View DTS Order</Label>
                    <LabelXS
                        >- User can view the details of a specific DTS
                        order.</LabelXS
                    >
                </InputContainer>

                <!-- Orders Approval -->
                <SpanBold class="col-span-2 mt-4">Orders Approval</SpanBold>
                <InputContainer>
                    <Label>View Orders for Approval List</Label>
                    <LabelXS
                        >- User can view the list of orders awaiting
                        approval.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>View Order for Approval</Label>
                    <LabelXS
                        >- User can view the details of a specific order for
                        approval.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Approve/Decline Order Request</Label>
                    <LabelXS
                        >- User can approve or decline an order
                        request.</LabelXS
                    >
                </InputContainer>

                <!-- Approved Orders -->
                <SpanBold class="col-span-2 mt-4">Approved Orders</SpanBold>
                <InputContainer>
                    <Label>View Approved Orders</Label>
                    <LabelXS
                        >- User can view the list of approved orders.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>View Approved Order</Label>
                    <LabelXS
                        >- User can view the details of an approved
                        order.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Receive Orders</Label>
                    <LabelXS
                        >- User can mark orders as received in the
                        system.</LabelXS
                    >
                </InputContainer>

                <!-- Approvals -->
                <SpanBold class="col-span-2 mt-4">Approvals</SpanBold>
                <InputContainer>
                    <Label>View Received Orders for Approval List</Label>
                    <LabelXS
                        >- User can view the list of received orders awaiting
                        approval.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>View Approved Order for Approval</Label>
                    <LabelXS
                        >- User can view the details of approved orders awaiting
                        further approval.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Approve Received Orders</Label>
                    <LabelXS
                        >- User can approve received orders in the
                        system.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Approve Image Attachments</Label>
                    <LabelXS
                        >- User can approve attached images in received
                        orders.</LabelXS
                    >
                </InputContainer>

                <!-- Approved Received Items -->
                <SpanBold class="col-span-2 mt-4"
                    >Approved Received Items</SpanBold
                >
                <InputContainer>
                    <Label>View Approved Received Items</Label>
                    <LabelXS
                        >- User can view the list of approved received
                        items.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>View Approved Received Item</Label>
                    <LabelXS
                        >- User can view details of a specific approved received
                        item.</LabelXS
                    >
                </InputContainer>

                <!-- Store Transactions -->
                <SpanBold class="col-span-2 mt-4">Store Transactions</SpanBold>
                <InputContainer>
                    <Label>View Store Transactions</Label>
                    <LabelXS
                        >- User can view all store transactions in the
                        system.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Create Store Transactions</Label>
                    <LabelXS>- User can create new store transactions.</LabelXS>
                </InputContainer>
                <InputContainer>
                    <Label>View Store Transaction</Label>
                    <LabelXS
                        >- User can view the details of a specific store
                        transaction.</LabelXS
                    >
                </InputContainer>

                <!-- Items -->
                <SpanBold class="col-span-2 mt-4">Items</SpanBold>
                <InputContainer>
                    <Label>View Items List</Label>
                    <LabelXS
                        >- User can view the list of all available
                        items.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Create New Items</Label>
                    <LabelXS
                        >- User can create new items for inventory or
                        ordering.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Edit Items</Label>
                    <LabelXS
                        >- User can modify details of existing items.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>View Item</Label>
                    <LabelXS
                        >- User can view detailed information about a specific
                        item.</LabelXS
                    >
                </InputContainer>

                <!-- Menu -->
                <SpanBold class="col-span-2 mt-4">Menu</SpanBold>
                <InputContainer>
                    <Label>View Menu List</Label>
                    <LabelXS
                        >- User can view the list of all available
                        menus.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>View Menu</Label>
                    <LabelXS
                        >- User can view details of a specific menu.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Create Menu</Label>
                    <LabelXS>- User can create new menu entries.</LabelXS>
                </InputContainer>
                <InputContainer>
                    <Label>Edit Menu</Label>
                    <LabelXS
                        >- User can update or modify existing menu
                        entries.</LabelXS
                    >
                </InputContainer>

                <!-- Stock Management -->
                <SpanBold class="col-span-2 mt-4">Stock Management</SpanBold>
                <InputContainer>
                    <Label>View Stock Management</Label>
                    <LabelXS
                        >- User can view stock levels and manage inventory
                        history.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Log Stock Usage</Label>
                    <LabelXS
                        >- User can log the usage of stock items in the
                        system.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>Add Stock Quantity</Label>
                    <LabelXS
                        >- User can add new quantities of stock items to the
                        inventory.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>View Stock Management History</Label>
                    <LabelXS
                        >- User can view the transaction history for stock
                        items.</LabelXS
                    >
                </InputContainer>

                <!-- Items Order Summary -->
                <SpanBold class="col-span-2 mt-4">Items Order Summary</SpanBold>
                <InputContainer>
                    <Label>View Items Order Summary</Label>
                    <LabelXS
                        >- User can view summaries of orders for various
                        items.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>View Ice Cream Orders</Label>
                    <LabelXS
                        >- User can view orders specifically for ice
                        cream.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>View Salmon Orders</Label>
                    <LabelXS
                        >- User can view orders specifically for
                        salmon.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>View Fruits and Vegetables Orders</Label>
                    <LabelXS
                        >- User can view orders for fruits and
                        vegetables.</LabelXS
                    >
                </InputContainer>

                <!-- User -->
                <SpanBold class="col-span-2 mt-4">User</SpanBold>
                <InputContainer>
                    <Label>Create Users</Label>
                    <LabelXS
                        >- User can create new users for the system.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>View Users</Label>
                    <LabelXS>- User can view the list of all users.</LabelXS>
                </InputContainer>
                <InputContainer>
                    <Label>Edit Users</Label>
                    <LabelXS
                        >- User can edit details of existing users.</LabelXS
                    >
                </InputContainer>
                <InputContainer>
                    <Label>View User</Label>
                    <LabelXS
                        >- User can view detailed information about a specific
                        user.</LabelXS
                    >
                </InputContainer>

                <!-- Manage References -->
                <SpanBold class="col-span-2 mt-4">Manage References</SpanBold>
                <InputContainer>
                    <Label>Manage References</Label>
                    <LabelXS
                        >- User can manage reference data for the
                        system.</LabelXS
                    >
                </InputContainer>
            </section>
        </Dialog>
    </Layout>
</template>
