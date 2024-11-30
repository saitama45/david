<script setup>
import { useSearch } from "@/Composables/useSearch";
import { router, usePage } from "@inertiajs/vue3";


import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";

const confirm = useConfirm();
const { toast } = useToast();

let filter = ref(usePage().props.filter || "pending");

watch(filter, function (value) {
    router.get(
        route("orders-approval.index"),
        { filter: value },
        {
            preserveState: true,
            replace: true,
        }
    );
});

const changeFilter = (currentFilter) => {
    filter.value = currentFilter;
};

const isFilterActive = (currentFilter) => {
    return filter.value == currentFilter ? "bg-primary text-white" : "";
};

const props = defineProps({
    orders: {
        type: Object,
    },
    counts: {
        type: Object,
    },
});

console.log(props);

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

const showOrderDetails = (id) => {
    router.get(`/orders-approval/show/${id}`);
};
</script>
<template>
    <Layout heading="Orders For Approval List">
        <DivFlexCenter
            class="gap-2 w-fit mx-auto px-5 py-2 bg-white rounded-lg shadow-lg"
        >
            <Button
                class="px-10 bg-white/10 text-gray-800 hover:text-white gap-3"
                :class="isFilterActive('pending')"
                @click="changeFilter('pending')"
                >PENDING
                <Badge
                    class="border border-gray bg-transparent text-gray-900"
                    :class="isFilterActive('pending')"
                    >{{ counts.pending }}</Badge
                >
            </Button>
            <Button
                class="px-10 bg-white/10 text-gray-800 hover:text-white gap-5"
                :class="isFilterActive('approved')"
                @click="changeFilter('approved')"
                >APPROVED
                <Badge
                    class="border border-gray bg-transparent text-gray-900"
                    :class="isFilterActive('approved')"
                    >{{ counts.approved }}</Badge
                ></Button
            >
            <Button
                class="px-10 bg-white/10 text-gray-800 hover:text-white gap-5"
                :class="isFilterActive('rejected')"
                @click="changeFilter('rejected')"
                >REJECTED
                <Badge
                    class="border border-gray bg-transparent text-gray-900"
                    :class="isFilterActive('rejected')"
                    >{{ counts.rejected }}</Badge
                ></Button
            >
        </DivFlexCenter>
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
