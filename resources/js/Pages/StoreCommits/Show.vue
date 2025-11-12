<script setup>
import { useBackButton } from "@/Composables/useBackButton";
import { router } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { useForm } from "@inertiajs/vue3";
import { ref, watch, computed } from 'vue';
import { Edit, Save, X } from "lucide-vue-next";
import { useAuth } from "@/Composables/useAuth";
import { Badge } from '@/Components/ui/badge';

const confirm = useConfirm();
const { toast } = useToast();
const { hasAccess } = useAuth();

const { backButton } = useBackButton(route("store-commits.index"));

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

const storeName = (order) => {
    return order.store_branch?.name ||
           order.store_branch?.branch_name ||
           order.store_name ||
           'Unknown Store'
}

const fromStoreName = (order) => {
    return order.from_store_name ||
           order.sending_store?.name ||
           order.sending_store?.branch_name ||
           order.sending_store?.brand_name ||
           'Unknown From Store'
}

const toStoreName = (order) => {
    return order.to_store_name ||
           order.store_branch?.name || // This is likely the receiving store
           order.store_branch?.branch_name ||
           'Unknown To Store'
}

const statusBadgeColor = (status) => {
    switch (status?.toUpperCase()) {
        case "APPROVED":
            return "bg-blue-500 text-white";
        case "COMMITTED":
            return "bg-yellow-500 text-white";
        case "PENDING":
            return "bg-gray-500 text-white";
        case "DECLINED":
            return "bg-red-500 text-white";
        case "OPEN":
            return "bg-gray-500 text-white";
        case "IN_TRANSIT":
            return "bg-purple-500 text-white";
        case "RECEIVED":
            return "bg-green-500 text-white";
        default:
            return "bg-gray-500 text-white";
    }
};

// Helper functions for item display (from Interco Show.vue pattern)
const getItemDescription = (item) => {
    return item.item_description ||
           item.sapMasterfile?.ItemDescription ||
           item.sapMasterfile?.ItemName ||
           item.description || // fallback to current field
           'Description not available'
}

const getItemUOM = (item) => {
    return item.item_uom || item.uom || ''
}

const isLoading = ref(false);
const showCommitOrderForm = ref(false);
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

watch(showCommitOrderForm, (value) => {
    if (!value) {
        isLoading.value = false;
        remarksForm.reset();
        remarksForm.clearErrors();
    }
});

const remarksForm = useForm({
    order_id: null,
    action: 'commit',
    remarks: null,
});

const commitOrder = (id) => {
    confirm.require({
        message: "Are you sure you want to transit this store order?",
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
            remarksForm.action = 'commit';
            remarksForm.post(route("store-commits.commit"), {
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Store Order Transited Successfully.",
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
        message: "Are you sure you want to decline this store order?",
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
            remarksForm.action = 'decline';
            remarksForm.post(route("store-commits.commit"), {
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Store Order Declined Successfully.",
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
        quantity_approved: item.quantity_approved,
        quantity_commited: item.quantity_commited || item.quantity_approved,
        item_code: item.item_code,
        description: item.description,
        soh_stock: item.soh_stock,
        uom: item.uom,
    })
);

const startEdit = (itemId) => {
    const item = itemsDetail.value.find(item => item.id === itemId);
    if (item) {
        editingItem.value = { id: itemId, originalValue: item.quantity_commited };
        editValue.value = item.quantity_commited.toString();
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
        itemInDetails.quantity_commited = newQuantity;
    }

    // Update the item in props.items as well for consistency
    const itemInProps = props.items.find(item => item.id === editingItemId);
    if (itemInProps) {
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
    console.log('Updating quantity for item:', itemId, 'quantity:', quantity);

    router.post(
        route("store-commits.update-quantity", itemId),
        { quantity_commited: quantity },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                console.log('Quantity update successful');
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: "Quantity updated successfully.",
                    life: 2000,
                });
            },
            onError: (errors) => {
                console.log('Quantity update failed with errors:', errors);

                // Display the actual backend error messages
                let errorMessage = "Failed to update quantity. Please refresh the page.";

                if (errors && typeof errors === 'object') {
                    // Extract the first error message if available
                    const errorValues = Object.values(errors);
                    if (errorValues.length > 0 && errorValues[0]) {
                        errorMessage = Array.isArray(errorValues[0]) ? errorValues[0][0] : errorValues[0];
                    }
                }

                // Log detailed error information
                console.error('Detailed error information:', {
                    errors,
                    itemId,
                    quantity,
                    timestamp: new Date().toISOString()
                });

                // Show specific error message
                toast.add({
                    severity: "error",
                    summary: "Update Failed",
                    detail: errorMessage,
                    life: 5000, // Longer display time for error details
                });

                // Optionally revert the local state if needed
                // (This would require fetching fresh data from backend)
            },
        }
    );
};
</script>

<template>
    <Layout heading="Store Order Details">
        <TableContainer>
            <section class="flex flex-col gap-5">
                <Card class="p-4 sm:p-6 bg-white shadow-sm rounded-lg">
                    <CardTitle class="text-lg font-semibold mb-4">Store Transfer Details</CardTitle>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
                        <div class="flex flex-col">
                            <span class="text-gray-500">Transfer Number:</span>
                            <span class="font-bold text-gray-900">{{ order.order_number }}</span>
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
                            <span class="font-bold text-gray-900">{{ formatDate(order.order_date) }}</span>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-gray-500">Interco Status:</span>
                            <Badge
                                :class="statusBadgeColor(order.interco_status)"
                                class="font-bold w-fit"
                            >
                                {{ order.interco_status?.toUpperCase() ?? "N/A" }}
                            </Badge>
                        </div>
                        <div class="flex flex-col">
                            <span class="text-gray-500">Interco Reason:</span>
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
                        v-if="order.interco_status === 'approved' && hasAccess('commit store orders')"
                        class="bg-green-500 hover:bg-green-300"
                        @click="commitOrder(order.id)"
                        :disabled="isLoading"
                    >
                        Transit Orders
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
                    <TH> Approved Qty </TH>
                    <TH> SOH Stock </TH>
                    <TH v-if="order.interco_status === 'approved'">Committed Qty</TH>
                    <TH v-else>Committed Qty</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items" :key="item.id">
                        <TD>{{ item.item_code }}</TD>
                        <TD>
                            <div>
                                <p class="font-medium">{{ getItemDescription(item) }}</p>
                                <Badge v-if="getItemUOM(item)" variant="outline" class="text-xs mt-1">
                                    {{ getItemUOM(item) }}
                                </Badge>
                            </div>
                        </TD>
                        <TD>{{ getItemUOM(item) ?? "N/A" }}</TD>
                        <TD>{{ item.quantity_approved }}</TD>
                        <TD>{{ item.soh_stock ?? 0 }}</TD>
                        <TD class="flex items-center gap-3" v-if="order.interco_status === 'approved'">
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
                                    ?.quantity_commited || 0
                            }}
                            <Edit
                                v-if="hasAccess('commit store orders')"
                                class="size-4 text-blue-500 cursor-pointer hover:text-blue-600"
                                @click="startEdit(item.id)"
                            />
                        </div>
                    </TD>
                        <TD v-else>
                            {{ item.quantity_commited }}
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="item in items" :key="item.id">
                    <MobileTableHeading
                        :title="`${getItemDescription(item)} (${item.item_code})`"
                    >
                        <div v-if="order.interco_status === 'approved' && hasAccess('commit store orders')">
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
                    <LabelXS>UOM: {{ getItemUOM(item) ?? "N/A" }}</LabelXS>
                    <LabelXS>Approved: {{ item.quantity_approved }}</LabelXS>
                    <LabelXS>SOH Stock: {{ item.soh_stock ?? 0 }}</LabelXS>
                    <LabelXS>
                        Committed: {{
                            itemsDetail.find((data) => data.id === item.id)
                                ?.quantity_commited || 0
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