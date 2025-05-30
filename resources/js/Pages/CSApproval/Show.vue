<script setup>
import { useBackButton } from "@/Composables/useBackButton";
import { router } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { useForm } from "@inertiajs/vue3";

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
    console.log(status.toUpperCase());
    switch (status.toUpperCase()) {
        case "APPROVED":
            return "bg-green-500 text-white";
        case "REJECTED":
            return "bg-red-400 text-white";
        case "COMMITED":
            return "bg-blue-400 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
};

// const approveOrder = (id) => {
//     confirm.require({
//         message: "Are you sure you want to approve this order?",
//         header: "Confirmation",
//         icon: "pi pi-exclamation-triangle",
//         rejectProps: {
//             label: "Cancel",
//             severity: "secondary",
//             outlined: true,
//         },
//         acceptProps: {
//             label: "Confirm",
//             severity: "info",
//         },
//         accept: () => {
//             router.post(
//                 route("orders-approval.approve", id),
//                 {},
//                 {
//                     preserveScroll: true,
//                     onSuccess: () => {
//                         toast.add({
//                             severity: "success",
//                             summary: "Success",
//                             detail: "Order Approved Successfully.",
//                             life: 3000,
//                         });
//                     },
//                 }
//             );
//         },
//     });
// };

// const rejectOrder = (id) => {
//     confirm.require({
//         message: "Are you sure you want to reject this order?",
//         header: "Confirmation",
//         icon: "pi pi-exclamation-triangle",
//         rejectProps: {
//             label: "Cancel",
//             severity: "secondary",
//             outlined: true,
//         },
//         acceptProps: {
//             label: "Confirm",
//             severity: "danger",
//         },
//         accept: () => {
//             router.post(
//                 route("orders-approval.reject", id),
//                 {},
//                 {
//                     preserveScroll: true,
//                     onSuccess: () => {
//                         toast.add({
//                             severity: "success",
//                             summary: "Success",
//                             detail: "Order Rejected Successfully.",
//                             life: 3000,
//                         });
//                     },
//                 }
//             );
//         },
//     });
// };

const itemRemarksForm = useForm({
    remarks: null,
});

const addRemarks = (id) => {
    itemRemarksForm.post(route("orders-approval.add-remarks", id), {
        onSuccess: () => {
            console.log("success");
        },
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
    remarksForm.post(route("cs-approvals.approve"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Order Confirmed Successfully.",
                life: 3000,
            });
            isLoading.value = false;
        },
        onError: () => {
            isLoading.value = false;
        },
    });
};

const confirmRejectOrder = () => {
    isLoading.value = true;
    remarksForm.post(route("cs-approvals.reject"), {
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
        quantity_approved: item.quantity_approved,
        item_cost: item.product_inventory.cost,
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
        // if (
        //     currentItem.quantity_approved < 1 &&
        //     currentItem.quantity_approved >= 0
        // ) {
        //     currentItem.quantity_approved = Number(
        //         parseFloat(currentItem.quantity_approved + 0.1).toFixed(2)
        //     );
        // } else {
        //     currentItem.quantity_approved++;
        // }

        currentItem.total_cost = parseFloat(
            currentItem.item_cost * currentItem.quantity_approved
        ).toFixed(2);
    }
};

import { useEditQuantity } from "@/Composables/useEditQuantity";
const { isEditQuantityModalOpen, formQuantity, editOrderQuantity } =
    useEditQuantity(null, itemsDetail, props.order);

const openEditQuantityModal = (id, quantity) => {
    formQuantity.id = id;
    formQuantity.quantity = quantity;
    isEditQuantityModalOpen.value = true;
};
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
                    <!-- <Button
                        v-if="order.order_request_status === 'pending'"
                        variant="secondary"
                        @click="updateDetails(order.order_number)"
                    >
                        Update Details
                    </Button> -->
                    <!-- <Button
                        class="bg-blue-500 hover:bg-blue-300"
                        @click="copyOrderAndCreateAnother(order.id)"
                    >
                        Copy Order And Create
                    </Button> -->
                    <!-- <Button
                        v-if="order.order_request_status === 'pending'"
                        variant="destructive"
                        @click="rejectOrder(order.id)"
                    >
                        Decline Order
                    </Button> -->
                    <Button
                        v-if="order.order_status === 'approved'"
                        class="bg-green-500 hover:bg-green-300"
                        @click="approveOrder(order.id)"
                    >
                        Confirm Order
                    </Button>
                </DivFlexCenter>
            </section>

            <TableHeader>
                <!-- <SearchBar /> -->
            </TableHeader>

            <Table>
                <TableHead>
                    <TH> Item Code </TH>
                    <TH> Name </TH>
                    <TH> Unit </TH>
                    <TH> Quantity </TH>
                    <TH v-if="order.order_status === 'committed'">Commited</TH>
                    <TH> Cost </TH>
                    <TH> Total Cost </TH>
                    <!-- <TH> Actions </TH> -->
                </TableHead>
                <TableBody>
                    <tr v-for="item in orderedItems" :key="order.id">
                        <TD>{{ item.product_inventory.inventory_code }}</TD>
                        <TD>{{ item.product_inventory.name }}</TD>
                        <TD>{{
                            item.product_inventory.unit_of_measurement.name
                        }}</TD>
                        <TD class="flex items-center gap-3">
                            {{
                                itemsDetail.find((data) => data.id === item.id)
                                    ?.quantity_approved || 0
                            }}
                            <LinkButton
                                v-if="order.order_status === 'approved'"
                                @click="
                                    openEditQuantityModal(
                                        item.id,
                                        item.quantity_approved
                                    )
                                "
                            >
                                Edit Quantity
                            </LinkButton>
                        </TD>
                        <TD v-if="order.order_request_status === 'approved'">{{
                            item.quantity_commited
                        }}</TD>
                        <TD>{{ item.product_inventory.cost }}</TD>
                        <TD>
                            {{
                                itemsDetail.find((data) => data.id === item.id)
                                    ?.total_cost || 0
                            }}
                        </TD>
                        <!-- <TD>
                            <LinkButton
                                class="text-blue-500"
                                @click="addRemarks(order.id)"
                                >Add Remarks</LinkButton
                            >
                        </TD> -->
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="item in orderedItems" :key="order.id">
                    <MobileTableHeading
                        :title="`${item.product_inventory.name} (${item.product_inventory.inventory_code})`"
                    >
                        <DivFlexCenter
                            class="gap-2"
                            v-if="order.order_request_status === 'pending'"
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
                            item.product_inventory.unit_of_measurement.name
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
                    <DialogTitle>Confirm Order</DialogTitle>
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
                        Commit
                        <span><Loading v-if="isLoading" /></span>
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
