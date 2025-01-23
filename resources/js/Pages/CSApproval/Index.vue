<script setup>
import { router, usePage } from "@inertiajs/vue3";

import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { throttle } from "lodash";

const confirm = useConfirm();
const { toast } = useToast();

let filter = ref(usePage().props.filters.currentFilter || "pending");
let search = ref(usePage().props.filters.search);
watch(filter, function (value) {
    router.get(
        route("cs-approvals.index"),
        { currentFilter: value, search: search.value },
        {
            preserveState: true,
            replace: true,
        }
    );
});

watch(
    search,
    throttle(function (value) {
        router.get(
            route("cs-approvals.index"),
            { search: value, currentFilter: filter.value },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

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
                route("cs-approvals.approve", id),
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
                route("cs-approvals.reject", id),
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
    router.get(`/cs-approvals/show/${id}`);
};

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();

const exportRoute = route("cs-approvals.export", {
    search: search.value,
    currentFilter: filter.value,
});
</script>
<template>
    <Layout
        heading="CS Approval List"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <FilterTab>
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('pending')"
                @click="changeFilter('pending')"
                >PENDING
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('pending')"
                    >{{ counts.pending }}</Badge
                >
            </Button>
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('approved')"
                @click="changeFilter('approved')"
                >APPROVED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('approved')"
                    >{{ counts.approved }}</Badge
                ></Button
            >
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('rejected')"
                @click="changeFilter('rejected')"
                >REJECTED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('rejected')"
                    >{{ counts.rejected }}</Badge
                ></Button
            >
        </FilterTab>
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
                                v-if="hasAccess('view order for cs approval')"
                                @click="showOrderDetails(order.order_number)"
                                variant="link"
                            >
                                <Eye />
                            </Button>
                            <!-- <Popover
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
                            </Popover> -->
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <MobileTableContainer>
                <MobileTableRow v-for="order in orders.data">
                    <MobileTableHeading :title="order.order_number">
                        <ShowButton
                            v-if="hasAccess('view cs order for approval')"
                            @click="showOrderDetails(order.order_number)"
                        />
                    </MobileTableHeading>
                    <LabelXS
                        >Order Status: {{ order.order_request_status }}</LabelXS
                    >
                    <LabelXS>Order Date: {{ order.order_date }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>
