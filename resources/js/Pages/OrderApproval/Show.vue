<script setup>
import { useBackButton } from "@/Composables/useBackButton";
import { router } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { useForm } from "@inertiajs/vue3";
import { ref, watch, computed } from 'vue'; // Explicitly import Vue reactivity APIs
import { Minus, Plus } from "lucide-vue-next"; // Import icons

const confirm = useConfirm();
const { toast } = useToast();

const { backButton } = useBackButton(route("orders-approval.index"));
const props = defineProps({
    order: {
        type: Object,
    },
    orderedItems: {
        type: Object,
    },
});

const updateDetails = (order_number) => {
    router.get(`/store-orders/edit/${order_number}`);
};
const search = ref(null);
const statusBadgeColor = (status) => {
    switch (status) {
        case "APPROVED":
            return "bg-green-500 text-white";
        case "PENDING":
            return "bg-yellow-500 text-white";
        case "REJECTED":
            return "bg-red-500 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
};

const itemRemarksForm = useForm({
    remarks: null,
});

const addRemarks = (id) => {
    itemRemarksForm.post(route("orders-approval.add-remarks", id), {
        onSuccess: () => {
            console.log("success");
        },
        onError: (e) => {},
    });
};

const copyOrderAndCreateAnother = (id) => {
    router.get("/store-orders/create", { orderId: id });
};

const isLoading = ref(false);
const showApproveOrderForm = ref(false);
const showRejectOrderForm = ref(false);

watch(showApproveOrderForm, (value) => {
    if (!value) {
        isLoading.value = false;
        remarksForm.reset();
        remarksForm.clearErrors();
    }
});
const remarksForm = useForm({
    id: null,
    remarks: null,
    updatedOrderedItemDetails: null,
});

const approveOrder = (id) => {
    showApproveOrderForm.value = true;
    remarksForm.id = id;
};
const rejectOrder = (id) => {
    showRejectOrderForm.value = true;
    remarksForm.id = id;
};

const confirmApproveOrder = () => {
    isLoading.value = true;
    remarksForm.updatedOrderedItemDetails = itemsDetail.value;
    remarksForm.post(route("orders-approval.approve"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Order Approved Successfully.",
                life: 3000,
            });
            isLoading.value = false;
        },
        onError: (e) => {
            console.log(e);
            isLoading.value = false;
        },
    });
};

const confirmRejectOrder = () => {
    isLoading.value = true;
    remarksForm.post(route("orders-approval.reject"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Order Reject Successfully.",
                life: 3000,
            });
            isLoading.value = false;
        },
        onError: () => {
            isLoading.value = false;
        },
    });
};

const itemsDetail = ref([]);
props.orderedItems.forEach((item) =>
    itemsDetail.value.push({
        id: item.id,
        quantity_ordered: item.quantity_ordered,
        quantity_approved: item.quantity_ordered,
        item_cost: item.cost_per_quantity, // Changed to item.cost_per_quantity
        total_cost: item.total_cost,
    })
);

const lessQuantityApproved = (id) => {
    const itemIndex = itemsDetail.value.findIndex((item) => item.id === id);

    if (itemIndex !== -1) {
        const currentItem = itemsDetail.value[itemIndex];

        if (currentItem.quantity_approved > 0) {
            currentItem.quantity_approved = Number(
                parseFloat(currentItem.quantity_approved - 0.1).toFixed(2)
            );
            currentItem.total_cost = parseFloat(
                currentItem.item_cost * currentItem.quantity_approved
            ).toFixed(2);
        }
    }
};

const addQuantityApproved = (id) => {
    const itemIndex = itemsDetail.value.findIndex((item) => item.id === id);

    if (itemIndex !== -1) {
        const currentItem = itemsDetail.value[itemIndex];
        currentItem.quantity_approved = Number(
            parseFloat(currentItem.quantity_approved + 0.1).toFixed(2)
        );
        currentItem.total_cost = parseFloat(
            currentItem.item_cost * currentItem.quantity_approved
        ).toFixed(2);
    }
};

import { useEditQuantity } from "@/Composables/useEditQuantity";
const {
    isEditQuantityModalOpen,
    formQuantity,
    openEditQuantityModal,
    editOrderQuantity,
} = useEditQuantity(null, itemsDetail, props.order);
</script>

<template>
    <Layout heading="Order Details">
        <TableContainer>
            <section class="flex flex-col gap-5">
                <section class="sm:flex-row flex flex-col gap-5">
                    <span class="text-gray-700 text-sm">
                        Order Number:
                        <span class="font-bold"> {{ order.order_number }}</span>
                    </span>
                    <span class="text-gray-700 text-sm">
                        Order Date:
                        <span class="font-bold"> {{ order.order_date }}</span>
                    </span>
                    <span class="text-gray-700 text-sm">
                        Status:
                        <Badge
                            :class="
                                statusBadgeColor(
                                    order.order_status.toUpperCase()
                                )
                            "
                        >
                            {{ order.order_status.toUpperCase() }}
                        </Badge>
                    </span>
                </section>

                <DivFlexCenter class="gap-5">
                    <Button
                        v-if="order.order_status === 'pending'"
                        variant="destructive"
                        @click="rejectOrder(order.id)"
                    >
                        Decline Order
                    </Button>
                    <Button
                        v-if="order.order_status === 'pending'"
                        class="bg-green-500 hover:bg-green-300"
                        @click="approveOrder(order.id)"
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
                    <TH> Name </TH>
                    <TH> Unit </TH>
                    <TH> Quantity </TH>
                    <TH v-if="order.order_status === 'approved'">Approved</TH>
                    <TH> Cost </TH>
                    <TH> Total Cost </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in orderedItems" :key="order.id">
                        <TD>{{ item.supplier_item.ItemCode }}</TD>
                        <TD>{{ item.supplier_item.item_name }}</TD>
                        <TD>{{
                            item.supplier_item.uom
                        }}</TD>
                        <TD class="flex items-center gap-3">
                            {{
                                itemsDetail.find((data) => data.id === item.id)
                                    ?.quantity_approved || 0
                            }}
                            <LinkButton
                                v-if="order.order_status === 'pending'"
                                @click="
                                    openEditQuantityModal(
                                        item.id,
                                        item.quantity_ordered
                                    )
                                "
                            >
                                Edit Quantity
                            </LinkButton>
                        </TD>
                        <TD v-if="order.order_status === 'approved'">
                            {{ item.quantity_approved }}
                        </TD>
                        <TD>{{ parseFloat(item.cost_per_quantity).toFixed(2) }}</TD> <!-- Changed to item.cost_per_quantity -->
                        <TD>
                            {{
                                itemsDetail.find((data) => data.id === item.id)
                                    ?.total_cost || 0
                            }}
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="item in orderedItems" :key="order.id">
                    <MobileTableHeading
                        :title="`${item.supplier_item.item_name} (${item.supplier_item.ItemCode})`"
                    >
                        <DivFlexCenter
                            class="gap-2"
                            v-if="order.order_status === 'pending'"
                        >
                            <button @click="lessQuantityApproved(item.id)">
                                <Minus class="size-4 text-red-500" />
                            </button>
                            <button @click="addQuantityApproved(item.id)">
                                <Plus class="size-4 text-green-500" />
                            </button>
                        </DivFlexCenter>
                    </MobileTableHeading>
                    <LabelXS
                        >UOM:
                        {{
                            item.supplier_item.uom
                        }}</LabelXS
                    >
                    <LabelXS
                        >Quantity:
                        {{
                            itemsDetail.find((data) => data.id === item.id)
                                ?.quantity_approved || 0
                        }}</LabelXS
                    >
                </MobileTableRow>
            </MobileTableContainer>
        </TableContainer>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>

        <Dialog v-model:open="showApproveOrderForm">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Approve Order</DialogTitle>
                    <DialogDescription> </DialogDescription>
                </DialogHeader>
                <InputContainer>
                    <Label class="text-xs">Remarks</Label>
                    <Textarea v-model="remarksForm.remarks" />
                    <FormError>{{ remarksForm.errors.remarks }}</FormError>
                </InputContainer>
                <DialogFooter>
                    <Button
                        :disabled="isLoading"
                        @click="confirmApproveOrder"
                        type="submit"
                        class="gap-2"
                    >
                        Approve
                        <span v-if="isLoading"><Loading /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
            <div class="space-y-5"></div>
        </Dialog>

        <Dialog v-model:open="showRejectOrderForm">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Reject Order</DialogTitle>
                    <DialogDescription> </DialogDescription>
                </DialogHeader>
                <InputContainer>
                    <Label class="text-xs">Remarks</Label>
                    <Textarea v-model="remarksForm.remarks" />
                    <FormError>{{ remarksForm.errors.remarks }}</FormError>
                </InputContainer>
                <DialogFooter>
                    <Button
                        :disabled="isLoading"
                        @click="confirmRejectOrder"
                        type="submit"
                        class="gap-2"
                    >
                        Reject
                        <span><Loading v-if="isLoading" /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
            <div class="space-y-5"></div>
        </Dialog>

        <Dialog v-model:open="isEditQuantityModalOpen">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Edit Quantity</DialogTitle>
                    <DialogDescription>
                        Please input all the required fields.
                    </DialogDescription>
                </DialogHeader>
                <InputContainer>
                    <LabelXS>Quantity</LabelXS>
                    <Input type="number" v-model="formQuantity.quantity" />
                    <FormError>{{ formQuantity.errors.quantity }}</FormError>
                </InputContainer>

                <DialogFooter>
                    <Button
                        @click="editOrderQuantity"
                        :disabled="isLoading"
                        type="submit"
                        class="gap-2"
                    >
                        Confirm
                        <span v-if="isLoading"><Loading /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
