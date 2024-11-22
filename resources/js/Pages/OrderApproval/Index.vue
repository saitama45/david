<script setup>
import { useSearch } from "@/Composables/useSearch";
import { router } from "@inertiajs/vue3";
import { useToast } from "primevue/usetoast";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/components/ui/popover";
const toast = useToast();
const props = defineProps({
    orders: {
        type: Object,
    },
});

const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "APPROVED":
            return "bg-green-500 text-white";
        case "REJECTED":
            return "bg-red-400 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
};
const { search } = useSearch("orders-approval.index");

const approveOrder = (id) => {
    router.post(route("orders-approval.approve", id), {
        onSuccess: (page) => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Order Approved Successfully.",
                life: 3000,
            });
        },
    });
};

const rejectOrder = (id) => {
    router.post(route("orders-approval.reject", id), {
        onSuccess: (page) => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Order Approved Successfully.",
                life: 3000,
            });
        },
    });
};

const showOrderDetails = (id) => {
    router.get(`/orders-approval/show/${id}`);
};
</script>
<template>
    <Layout heading="Orders For Approval List">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        v-model="search"
                        placeholder="Order Number Search"
                    />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Supplier</TH>
                    <TH>Store</TH>
                    <TH>Order #</TH>
                    <TH>Order Date</TH>
                    <TH>Order Placed Date</TH>
                    <TH>Order Status</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders.data" :key="order.id">
                        <TD>{{ order.id }}</TD>
                        <TD>{{ order.supplier?.name ?? "N/A" }}</TD>
                        <TD>{{ order.store_branch?.name ?? "N/A" }}</TD>
                        <TD>{{ order.order_number }}</TD>
                        <TD>{{ order.order_date }}</TD>
                        <TD>{{ order.created_at }}</TD>
                        <TD>
                            <Badge
                                :class="
                                    statusBadgeColor(order.order_request_status)
                                "
                                class="font-bold"
                                >{{
                                    order.order_request_status.toUpperCase()
                                }}</Badge
                            >
                        </TD>
                        <TD class="flex">
                            <Button
                                @click="showOrderDetails(order.order_number)"
                                variant="link"
                            >
                                <Eye />
                            </Button>
                            <Popover
                                v-if="order.order_request_status === 'pending'"
                            >
                                <PopoverTrigger>
                                    <EllipsisVertical />
                                </PopoverTrigger>
                                <PopoverContent class="w-fit">
                                    <DivFlexCol>
                                        <Button
                                            class="text-green-500"
                                            @click="approveOrder(order.id)"
                                            variant="link"
                                        >
                                            Approve
                                        </Button>
                                        <Button
                                            v-if="
                                                order.order_request_status ===
                                                'pending'
                                            "
                                            class="text-red-500"
                                            @click="rejectOrder(order.id)"
                                            variant="link"
                                        >
                                            Reject
                                        </Button>
                                    </DivFlexCol>
                                </PopoverContent>
                            </Popover>
                        </TD>
                    </tr></TableBody
                >
            </Table>
            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>
