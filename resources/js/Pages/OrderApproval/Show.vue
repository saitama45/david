<script setup>
import { useBackButton } from "@/Composables/useBackButton";
import { router } from "@inertiajs/vue3";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";

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

const approveOrder = (id) => {
    confirm.require({
        message: "Are you sure you want to approve this order?",
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
            router.post(
                route("orders-approval.approve", id),
                {},
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: "Order Approved Successfully.",
                            life: 3000,
                        });
                    },
                }
            );
        },
    });
};

const rejectOrder = (id) => {
    confirm.require({
        message: "Are you sure you want to reject this order?",
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
            router.post(
                route("orders-approval.reject", id),
                {},
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        toast.add({
                            severity: "success",
                            summary: "Success",
                            detail: "Order Rejected Successfully.",
                            life: 3000,
                        });
                    },
                }
            );
        },
    });
};

const copyOrderAndCreateAnother = (id) => {
    router.get("/store-orders/create", { orderId: id });
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
                    <TH> Actions </TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orderedItems" :key="order.id">
                        <TD>{{ order.product_inventory.inventory_code }}</TD>
                        <TD>{{ order.product_inventory.name }}</TD>
                        <TD>{{
                            order.product_inventory.unit_of_measurement.name
                        }}</TD>
                        <TD class="flex items-center gap-3"
                            >{{ order.quantity_ordered }}

                            <div class="flex items-center gap-1">
                                <button class="text-red-500">
                                    <Minus />
                                </button>
                                <button class="text-green-500">
                                    <Plus />
                                </button>
                            </div>
                        </TD>
                        <TD>{{ order.product_inventory.cost }}</TD>
                        <TD>{{ order.total_cost }}</TD>
                        <TD>
                            <LinkButton class="text-blue-500"
                                >Add Remarks</LinkButton
                            >
                        </TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>

        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>
