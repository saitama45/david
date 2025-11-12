<script setup>
import { useBackButton } from "@/Composables/useBackButton";
import { router } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { useForm } from "@inertiajs/vue3";
import { ref, watch, computed } from 'vue';
import { Edit, Save, X } from "lucide-vue-next";
import { useAuth } from "@/Composables/useAuth";

const confirm = useConfirm();
const { toast } = useToast();
const { hasAccess } = useAuth();

const { backButton } = useBackButton(route("interco-approval.index"));

const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
    items: {
        type: Object,
        required: true,
    },
});

// Helper functions for consistent data display
const formatDate = (dateString) => {
    if (!dateString) return 'N/A'
    return new Date(dateString).toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    })
}

const fromStoreName = (order) => {
    return order.from_store_name ||
           order.sending_store?.name ||
           order.sending_store?.branch_name ||
           order.sending_store?.brand_name ||
           'Unknown Sending Store'
}

const toStoreName = (order) => {
    return order.to_store_name ||
           order.receiving_store?.name ||
           order.receiving_store?.branch_name ||
           'Unknown Receiving Store'
}

const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "APPROVED":
            return "bg-green-500 text-white";
        case "OPEN":
            return "bg-blue-500 text-white";
        case "DISAPPROVED":
            return "bg-red-500 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
};

const isLoading = ref(false);
const showApproveOrderForm = ref(false);
const showRejectOrderForm = ref(false);

// Edit state variables for quantity editing
const editingItem = ref(null); // { id: number, originalValue: number }
const editValue = ref('');
const editInput = ref(null);

// Focus directive for auto-selecting text when editing
const vFocusSelect = {
    mounted: (el) => {
        const input = el.tagName === 'INPUT' ? el : el.querySelector('input');
        if (input) {
            input.focus();
            input.select();
        }
    }
}

watch(showApproveOrderForm, (value) => {
    if (!value) {
        isLoading.value = false;
        remarksForm.reset();
        remarksForm.clearErrors();
    }
});

const remarksForm = useForm({
    order_id: null,
    remarks: null,
});

const approveOrder = (id) => {
    confirm.require({
        message: "Are you sure you want to approve this interco order?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "info",
        },
        accept: () => {
            isLoading.value = true;
            remarksForm.order_id = id;
            remarksForm.post(route("interco-approval.approve"), {
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Interco Order Approved Successfully.",
                        life: 3000,
                    });
                    isLoading.value = false;
                },
                onError: () => {
                    isLoading.value = false;
                },
            });
        },
    });
};

const rejectOrder = (id) => {
    confirm.require({
        message: "Are you sure you want to disapprove this interco order?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Confirm",
            severity: "danger",
        },
        accept: () => {
            isLoading.value = true;
            remarksForm.order_id = id;
            remarksForm.post(route("interco-approval.disapprove"), {
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Interco Order Disapproved Successfully.",
                        life: 3000,
                    });
                    isLoading.value = false;
                },
                onError: () => {
                    isLoading.value = false;
                },
            });
        },
    });
};

// Quantity editing functionality
const itemsDetail = ref([]);
props.items.forEach((item) =>
    itemsDetail.value.push({
        id: item.id,
        quantity_ordered: item.quantity_ordered,
        quantity_approved: item.quantity_approved || item.quantity_ordered,
        quantity_commited: item.quantity_commited || item.quantity_approved || item.quantity_ordered,
        item_code: item.item_code,
        description: item.description,
        soh_stock: item.soh_stock,
        uom: item.uom,
    })
);

const startEdit = (itemId) => {
    const item = itemsDetail.value.find(item => item.id === itemId);
    if (item) {
        editingItem.value = { id: itemId, originalValue: item.quantity_approved };
        editValue.value = item.quantity_approved.toString();
    }
};

const saveEdit = () => {
    if (!editingItem.value) return;

    const quantity = parseFloat(editValue.value);
    if (isNaN(quantity) || quantity < 0) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Please enter a valid quantity.",
            life: 3000,
        });
        return;
    }

    const newQuantity = Number(quantity.toFixed(2));
    const editingItemId = editingItem.value.id;
    const originalQuantity = editingItem.value.originalValue;

    // Update the local itemsDetail array immediately for reactive display
    const itemInDetails = itemsDetail.value.find(item => item.id === editingItemId);
    if (itemInDetails) {
        itemInDetails.quantity_approved = newQuantity;
        itemInDetails.quantity_commited = newQuantity;
    }

    // Update the item in props.items as well for consistency
    const itemInProps = props.items.find(item => item.id === editingItemId);
    if (itemInProps) {
        itemInProps.quantity_approved = newQuantity;
        itemInProps.quantity_commited = newQuantity;
    }

    updateItemQuantity(editingItemId, newQuantity);
    editingItem.value = null;
    editValue.value = '';
};

const cancelEdit = () => {
    editingItem.value = null;
    editValue.value = '';
};

const updateItemQuantity = (itemId, quantity) => {
    router.post(
        route("interco-approval.update-quantity", itemId),
        {
            quantity_approved: quantity,
            quantity_commited: quantity
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: "Quantity updated successfully.",
                    life: 2000,
                });
            },
            onError: () => {
                // Revert the itemsDetail array to original value on API failure
                const itemInDetails = itemsDetail.value.find(item => item.id === itemId);
                const itemInProps = props.items.find(item => item.id === itemId);

                // Find the original value from the backend (refresh the specific item)
                // For now, we'll show an error and let the user know to refresh
                toast.add({
                    severity: "error",
                    summary: "Error",
                    detail: "Failed to update quantity. Please refresh the page.",
                    life: 3000,
                });
            },
        }
    );
};
</script>

<template>
    <Layout heading="Interco Order Details">
        <TableContainer>
            <section class="flex flex-col gap-5">
                <Card class="p-4 sm:p-6 bg-white shadow-sm rounded-lg">
                    <CardTitle class="text-lg font-semibold mb-4">Interco Transfer Details</CardTitle>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
                        <div class="flex flex-col">
                            <span class="text-gray-500">Interco Number:</span>
                            <span class="font-bold text-gray-900">{{ order.interco_number }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-gray-500">From Store:</span>
                            <span class="font-bold text-gray-900">{{ fromStoreName(order) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-gray-500">To Store:</span>
                            <span class="font-bold text-gray-900">{{ toStoreName(order) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-gray-500">Transfer Date:</span>
                            <span class="font-bold text-gray-900">{{ formatDate(order.transfer_date || order.order_date) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-gray-500">Status:</span>
                            <Badge
                                :class="statusBadgeColor(order.interco_status)"
                                class="font-bold w-fit"
                            >
                                {{ order.interco_status?.toUpperCase() ?? "N/A" }}
                            </Badge>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-gray-500">Reason:</span>
                            <span class="font-bold text-gray-900">{{ order.interco_reason ?? "N/A" }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-gray-500">Remarks:</span>
                            <span class="font-bold text-gray-900">{{ order.remarks ?? "N/A" }}</span>
                        </div>
                    </div>
                </Card>

                <DivFlexCenter class="gap-5">
                    <Button
                        v-if="order.interco_status === 'open' && hasAccess('approve interco requests')"
                        variant="destructive"
                        @click="rejectOrder(order.id)"
                        :disabled="isLoading"
                    >
                        Disapprove Order
                    </Button>
                    <Button
                        v-if="order.interco_status === 'open' && hasAccess('approve interco requests')"
                        class="bg-green-500 hover:bg-green-300"
                        @click="approveOrder(order.id)"
                        :disabled="isLoading"
                    >
                        Approve Order
                    </Button>
                </DivFlexCenter>
            </section>

            <TableHeader>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH> Item Code </TH>
                    <TH> Description </TH>
                    <TH> UOM </TH>
                    <TH> Ordered Qty </TH>
                    <TH> SOH Stock </TH>
                    <TH v-if="order.interco_status === 'open'">Approved Qty</TH>
                    <TH v-else>Approved Qty</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items" :key="item.id">
                        <TD>{{ item.item_code }}</TD>
                        <TD>{{ item.description }}</TD>
                        <TD>{{ item.uom ?? "N/A" }}</TD>
                        <TD>{{ item.quantity_ordered }}</TD>
                        <TD>{{ item.soh_stock ?? 0 }}</TD>
                        <TD class="flex items-center gap-3" v-if="order.interco_status === 'open'">
                        <div v-if="editingItem && editingItem.id === item.id">
                            <Input
                                v-focus-select
                                type="number"
                                v-model="editValue"
                                class="w-20 text-right"
                                @keyup.enter="saveEdit"
                                @keyup.esc="cancelEdit"
                            />
                            <DivFlexCenter class="gap-1 ml-2">
                                <Save class="size-4 text-green-500 cursor-pointer hover:text-green-600" @click="saveEdit" />
                                <X class="size-4 text-red-500 cursor-pointer hover:text-red-600" @click="cancelEdit" />
                            </DivFlexCenter>
                        </div>
                        <div v-else class="flex items-center gap-2">
                            {{
                                itemsDetail.find((data) => data.id === item.id)
                                    ?.quantity_approved || 0
                            }}
                            <Edit
                                v-if="hasAccess('approve interco requests')"
                                class="size-4 text-blue-500 cursor-pointer hover:text-blue-600"
                                @click="startEdit(item.id)"
                            />
                        </div>
                    </TD>
                        <TD v-else>
                            {{ item.quantity_approved }}
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="item in items" :key="item.id">
                    <MobileTableHeading
                        :title="`${item.description} (${item.item_code})`"
                    >
                        <div v-if="order.interco_status === 'open' && hasAccess('approve interco requests')">
                            <div v-if="editingItem && editingItem.id === item.id">
                                <Input
                                    v-focus-select
                                    type="number"
                                    v-model="editValue"
                                    class="w-20 text-right"
                                    @keyup.enter="saveEdit"
                                    @keyup.esc="cancelEdit"
                                />
                                <DivFlexCenter class="gap-1 ml-2">
                                    <Save class="size-4 text-green-500 cursor-pointer hover:text-green-600" @click="saveEdit" />
                                    <X class="size-4 text-red-500 cursor-pointer hover:text-red-600" @click="cancelEdit" />
                                </DivFlexCenter>
                            </div>
                            <div v-else>
                                <Edit
                                    class="size-4 text-blue-500 cursor-pointer hover:text-blue-600"
                                    @click="startEdit(item.id)"
                                />
                            </div>
                        </div>
                    </MobileTableHeading>
                    <LabelXS>UOM: {{ item.uom ?? "N/A" }}</LabelXS>
                    <LabelXS>Ordered: {{ item.quantity_ordered }}</LabelXS>
                    <LabelXS>SOH Stock: {{ item.soh_stock ?? 0 }}</LabelXS>
                    <LabelXS>
                        Approved: {{
                            itemsDetail.find((data) => data.id === item.id)
                                ?.quantity_approved || 0
                        }}
                    </LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
        </TableContainer>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>