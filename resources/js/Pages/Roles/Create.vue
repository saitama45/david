<script setup>
import Checkbox from "primevue/checkbox";
import Dialog from "primevue/dialog";

import { CircleHelp } from "lucide-vue-next";

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

const isPermissionGuideModalVisible = ref(false);
</script>

<template>
    <Layout heading="Create Role">
        <Card class="p-5 space-y-5">
            <InputContainer>
                <LabelXS>Name</LabelXS>
                <Input v-model="form.name" />
                <FormError>{{ form.errors.name }}</FormError>
            </InputContainer>
            <InputContainer>
                <DivFlexCenter class="justify-between">
                    <LabelXS> Permissions</LabelXS>
                    <button @click="isPermissionGuideModalVisible = true">
                        <CircleHelp />
                    </button>
                </DivFlexCenter>
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
            </InputContainer>
            <DivFlexCenter class="justify-end">
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
