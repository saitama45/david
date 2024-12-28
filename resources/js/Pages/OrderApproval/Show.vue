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
    console.log(id);
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
        onError: () => {
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
        item_cost: item.product_inventory.cost,
        total_cost: item.total_cost,
    })
);

const lessQuantityApproved = (id) => {
    const itemIndex = itemsDetail.value.findIndex((item) => item.id === id);

    if (itemIndex !== -1) {
        const currentItem = itemsDetail.value[itemIndex];

        if (currentItem.quantity_approved > 0) {
            currentItem.quantity_approved--;
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
        currentItem.quantity_approved++;
        currentItem.total_cost = parseFloat(
            currentItem.item_cost * currentItem.quantity_approved
        ).toFixed(2);
    }
};
</script>

<template>
    <Layout heading="Order Details">
        <TableContainer>
            <DivFlexCenter class="justify-between">
                <DivFlexCenter class="gap-5">
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
                                    order.order_request_status.toUpperCase()
                                )
                            "
                        >
                            {{ order.order_request_status.toUpperCase() }}
                        </Badge>
                    </span>
                </DivFlexCenter>

                <DivFlexCenter class="gap-5">
                    <Button
                        v-if="order.order_request_status === 'pending'"
                        variant="secondary"
                        @click="updateDetails(order.order_number)"
                    >
                        Update Details
                    </Button>
                    <Button
                        class="bg-blue-500 hover:bg-blue-300"
                        @click="copyOrderAndCreateAnother(order.id)"
                    >
                        Copy Order And Create
                    </Button>
                    <Button
                        v-if="order.order_request_status === 'pending'"
                        variant="destructive"
                        @click="rejectOrder(order.id)"
                    >
                        Decline Order
                    </Button>
                    <Button
                        v-if="order.order_request_status === 'pending'"
                        class="bg-green-500 hover:bg-green-300"
                        @click="approveOrder(order.id)"
                    >
                        Approve Order
                    </Button>
                </DivFlexCenter>
            </DivFlexCenter>

            <TableHeader>
                <SearchBar />
            </TableHeader>

            <Table>
                <TableHead>
                    <TH> Item Code </TH>
                    <TH> Name </TH>
                    <TH> Unit </TH>
                    <TH> Quantity </TH>
                    <TH> Cost </TH>
                    <TH> Total Cost </TH>
                    <!-- <TH> Actions </TH> -->
                </TableHead>
                <TableBody>
                    <tr v-for="order in orderedItems" :key="order.id">
                        <TD>{{ order.product_inventory.inventory_code }}</TD>
                        <TD>{{ order.product_inventory.name }}</TD>
                        <TD>{{
                            order.product_inventory.unit_of_measurement.name
                        }}</TD>
                        <TD class="flex items-center gap-3">
                            {{
                                itemsDetail.find((item) => item.id === order.id)
                                    ?.quantity_approved || 0
                            }}
                            <DivFlexCenter class="gap-2">
                                <button @click="lessQuantityApproved(order.id)">
                                    <Minus class="size-4 text-red-500" />
                                </button>
                                <button @click="addQuantityApproved(order.id)">
                                    <Plus class="size-4 text-green-500" />
                                </button>
                            </DivFlexCenter>
                        </TD>
                        <TD>{{ order.product_inventory.cost }}</TD>
                        <TD>
                            {{
                                itemsDetail.find((item) => item.id === order.id)
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
                </InputContainer>
                <DialogFooter>
                    <Button
                        :disabled="isLoading"
                        @click="confirmApproveOrder"
                        type="submit"
                        class="gap-2"
                    >
                        Approve
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
    </Layout>
</template>
