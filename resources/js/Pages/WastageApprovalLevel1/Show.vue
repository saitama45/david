<script setup>
import { useBackButton } from "@/Composables/useBackButton";
import { router, usePage } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { useForm } from "@inertiajs/vue3";
import { ref, watch, computed } from 'vue';
import { Edit, Save, X, Trash2 } from "lucide-vue-next";
import { useAuth } from "@/Composables/useAuth";

const confirm = useConfirm();
const { toast } = useToast();
const { hasAccess } = useAuth();

const { backButton } = useBackButton(route("wastage-approval-lvl1.index"));

const props = defineProps({
    wastage: {
        type: Object,
        required: true,
    },
    permissions: {
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

const storeName = (wastage) => {
    return wastage.store_branch_name ||
           wastage.storeBranch?.name ||
           wastage.storeBranch?.branch_name ||
           wastage.storeBranch?.brand_name ||
           'Unknown Store'
}

const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "APPROVED_LVL1":
            return "bg-blue-500 text-white";
        case "PENDING":
            return "bg-yellow-500 text-white";
        case "CANCELLED":
            return "bg-red-500 text-white";
        default:
            return "bg-gray-500 text-white";
    }
};

const isLoading = ref(false);

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

const remarksForm = useForm({
    order_id: null,
    remarks: null,
});

const approveWastage = (id) => {
    confirm.require({
        message: "Are you sure you want to approve this wastage record?",
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
            remarksForm.post(route("wastage-approval-lvl1.approve"), {
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Wastage record approved successfully.",
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

const cancelWastage = (id) => {
    confirm.require({
        message: "Are you sure you want to cancel this wastage record?",
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
            remarksForm.post(route("wastage-approval-lvl1.cancel"), {
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Wastage record cancelled successfully.",
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

// Initialize and watch for changes in props.wastage
watch(() => props.wastage, (newWastage) => {
    if (newWastage && newWastage.items) {
        itemsDetail.value = newWastage.items.map(item => ({
            id: item.id,
            wastage_qty: item.wastage_qty,
            approverlvl1_qty: item.approverlvl1_qty ?? item.wastage_qty,
            item_code: item.sap_masterfile?.ItemCode,
            description: item.sap_masterfile?.ItemDescription,
            cost: item.cost,
            uom: item.sap_masterfile?.BaseUOM,
        }));
    }
}, { immediate: true, deep: true });


const startEdit = (itemId) => {
    const item = itemsDetail.value.find(item => item.id === itemId);
    if (item) {
        editingItem.value = { id: itemId, originalValue: item.approverlvl1_qty };
        editValue.value = item.approverlvl1_qty.toString();
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
        itemInDetails.approverlvl1_qty = newQuantity;
    }

    updateItemQuantity(editingItemId, newQuantity, originalQuantity);
    editingItem.value = null;
    editValue.value = '';
};

const cancelEdit = () => {
    editingItem.value = null;
    editValue.value = '';
};

const updateItemQuantity = (itemId, quantity, originalQuantity) => {
    router.post(
        route("wastage-approval-lvl1.update-quantity", itemId),
        {
            approverlvl1_qty: quantity
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
            onError: (errors) => {
                // Revert the itemsDetail array to original value on API failure
                const itemInDetails = itemsDetail.value.find(item => item.id === itemId);
                if (itemInDetails) {
                    itemInDetails.approverlvl1_qty = originalQuantity;
                }

                // Show specific error message if available
                const errorMessage = errors.approverlvl1_qty ||
                                   errors.message ||
                                   "Failed to update quantity. Please refresh the page.";

                toast.add({
                    severity: "error",
                    summary: "Error",
                    detail: errorMessage,
                    life: 3000,
                });
            },
        }
    );
};

const deleteItem = (itemId) => {
    confirm.require({
        message: "Are you sure you want to delete this item?",
        header: "Confirmation",
        icon: "pi pi-exclamation-triangle",
        rejectProps: {
            label: "Cancel",
            severity: "secondary",
            outlined: true,
        },
        acceptProps: {
            label: "Delete",
            severity: "danger",
        },
        accept: () => {
            router.delete(route("wastage-approval-lvl1.destroy-item", itemId), {
                preserveScroll: true,
                onSuccess: () => {
                    toast.add({
                        severity: "success",
                        summary: "Success",
                        detail: "Item deleted successfully.",
                        life: 3000,
                    });
                },
                onError: (errors) => {
                    const errorMessage = Object.values(errors).join(' ');
                    toast.add({
                        severity: "error",
                        summary: "Error",
                        detail: errorMessage || "Failed to delete item.",
                        life: 3000,
                    });
                },
            });
        },
    });
};
</script>

<template>
    <Layout heading="Wastage Record Details">
        <TableContainer>
            <section class="flex flex-col gap-5">
                <section class="sm:flex-row flex flex-col gap-5">
                    <span class="text-gray-700 text-sm">
                        Wastage Number:
                        <span class="font-bold"> {{ wastage.wastage_no }}</span>
                    </span>
                    <span class="text-gray-700 text-sm">
                        Store:
                        <span class="font-bold"> {{ storeName(wastage) }}</span>
                    </span>
                </section>

                <section class="sm:flex-row flex flex-col gap-5">
                    <span class="text-gray-700 text-sm">
                        Status:
                        <Badge
                            :class="statusBadgeColor(wastage.wastage_status)"
                            class="font-bold"
                        >
                            {{ wastage.wastage_status?.toUpperCase().replace('_', ' ') ?? "N/A" }}
                        </Badge>
                    </span>
                    <span class="text-gray-700 text-sm">
                        Date:
                        <span class="font-bold"> {{ formatDate(wastage.created_at) }}</span>
                    </span>
                </section>

                <section class="sm:flex-row flex flex-col gap-5">
                    <span class="text-gray-700 text-sm">
                        Reason:
                        <span class="font-bold"> {{ wastage.wastage_reason ?? "N/A" }}</span>
                    </span>
                </section>

                <DivFlexCenter class="gap-5">
                    <Button
                        v-if="wastage.wastage_status === 'pending' && hasAccess('cancel wastage approval level 1')"
                        variant="destructive"
                        @click="cancelWastage(wastage.id)"
                        :disabled="isLoading"
                    >
                        Cancel Wastage
                    </Button>
                    <Button
                        v-if="wastage.wastage_status === 'pending' && hasAccess('approve wastage level 1')"
                        class="bg-green-500 hover:bg-green-300"
                        @click="approveWastage(wastage.id)"
                        :disabled="isLoading"
                    >
                        Approve Wastage
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
                    <TH> Wastage Qty </TH>
                    <TH v-if="wastage.wastage_status === 'pending'">Approved Qty</TH>
                    <TH v-else>Approved Qty</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in wastage.items" :key="item.id">
                        <TD>{{ item.sap_masterfile?.ItemCode || 'N/A' }}</TD>
                        <TD>{{ item.sap_masterfile?.ItemDescription || 'N/A' }}</TD>
                        <TD>{{ item.sap_masterfile?.BaseUOM ?? "N/A" }}</TD>
                        <TD>{{ item.wastage_qty }}</TD>
                        <TD class="flex items-center gap-3" v-if="wastage.wastage_status === 'pending'">
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
                        <div v-else class="flex items-center gap-4">
                            {{
                                itemsDetail.find((data) => data.id === item.id)
                                    ?.approverlvl1_qty ?? 0
                            }}
                            <Edit
                                v-if="hasAccess('edit wastage approval level 1')"
                                class="size-4 text-blue-500 cursor-pointer hover:text-blue-600"
                                @click="startEdit(item.id)"
                            />
                            <Trash2
                                v-if="hasAccess('delete wastage approval level 1')"
                                class="size-4 text-red-500 cursor-pointer hover:text-red-600"
                                @click="deleteItem(item.id)"
                            />
                        </div>
                    </TD>
                        <TD v-else>
                            {{ item.approverlvl1_qty }}
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="item in wastage.items" :key="item.id">
                    <MobileTableHeading
                        :title="`${item.sap_masterfile?.ItemDescription || 'N/A'} (${item.sap_masterfile?.ItemCode || 'N/A'})`"
                    >
                        <div v-if="wastage.wastage_status === 'pending' && hasAccess('edit wastage approval level 1')">
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
                                <Edit
                                    class="size-4 text-blue-500 cursor-pointer hover:text-blue-600"
                                    @click="startEdit(item.id)"
                                />
                            </div>
                        </div>
                    </MobileTableHeading>
                    <LabelXS>UOM: {{ item.sap_masterfile?.BaseUOM ?? "N/A" }}</LabelXS>
                    <LabelXS>Wastage: {{ item.wastage_qty }}</LabelXS>
                    <LabelXS>
                        Approved: {{
                            itemsDetail.find((data) => data.id === item.id)
                                ?.approverlvl1_qty ?? 0
                        }}
                    </LabelXS>
                    <div v-if="wastage.wastage_status === 'pending'" class="flex justify-end mt-2">
                        <Trash2
                            v-if="hasAccess('delete wastage approval level 1')"
                            class="size-4 text-red-500 cursor-pointer hover:text-red-600"
                            @click="deleteItem(item.id)"
                        />
                    </div>
                </MobileTableRow>
            </MobileTableContainer>
        </TableContainer>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>