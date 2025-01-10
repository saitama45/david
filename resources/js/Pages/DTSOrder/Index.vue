<script setup>
import { router, usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";

let filter = ref(usePage().props.filters.currentFilter || "pending");
let search = ref(usePage().props.filters.search);
const changeFilter = (currentFilter) => {
    filter.value = currentFilter;
};
const variant = ref("");
const isLoading = false;
console.log(usePage().props.filters);
const isVariantChoicesVisible = ref(false);
const showVariantChoices = () => {
    isVariantChoicesVisible.value = true;
};

watch(
    search,
    throttle(function (value) {
        router.get(
            route("dts-orders.index"),
            { search: value, currentFilter: filter.value },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

const proceed = () => {
    if (!variant.value) return;
    router.get(`/dts-orders/create/${variant.value}`);
};

const variants = [
    {
        value: "ice cream",
        label: "Ice Cream",
    },
    {
        value: "salmon",
        label: "Salmon",
    },
    {
        value: "fruits and vegetables",
        label: "Fruits and Vegetables",
    },
];

watch(filter, function (value) {
    router.get(
        route("dts-orders.index"),
        { currentFilter: value, search: search.value },
        {
            preserveState: true,
            replace: true,
        }
    );
});

const props = defineProps({
    orders: {
        type: Object,
    },
    branches: {
        type: Object,
    },
    auth: {
        type: Object,
    },
});

const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "APPROVED":
            return "bg-green-500 text-white";
        case "PENDING":
            return "bg-yellow-500 text-white";
        case "REJECTED":
            return "bg-red-400 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
};

const showOrderDetails = (id) => {
    router.get(`/dts-orders/show/${id}`);
};

const editOrderDetails = (id) => {
    router.get(`/dts-orders/edit/${id}`);
};

const { roles, is_admin } = usePage().props.auth;

const now = new Date();
const isCutOff = ref(now.getDay() >= 3);
import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();
</script>
<template>
    <Layout
        heading="DTS Orders"
        :hasButton="hasAccess('create dts orders')"
        :handleClick="showVariantChoices"
        buttonName="Create New Order"
    >
        <FilterTab>
            <FilterTabButton
                label="All"
                filter="all"
                :currentFilter="filter"
                @click="changeFilter('all')"
            />
            <FilterTabButton
                label="Approved"
                filter="approved"
                :currentFilter="filter"
                @click="changeFilter('approved')"
            />
            <FilterTabButton
                label="Pending"
                filter="pending"
                :currentFilter="filter"
                @click="changeFilter('pending')"
            />
            <FilterTabButton
                label="Rejected"
                filter="rejected"
                :currentFilter="filter"
                @click="changeFilter('rejected')"
            />
        </FilterTab>

        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        v-model="search"
                        id="search"
                        placeholder="Search..."
                        class="pl-10"
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
                    <TH>Order Approval Status</TH>
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
                        <TD>
                            <DivFlexCenter class="gap-3">
                                <button
                                    v-if="hasAccess('view dts order')"
                                    @click="
                                        showOrderDetails(order.order_number)
                                    "
                                >
                                    <Eye class="size-5" />
                                </button>
                                <button
                                    v-if="
                                        order.order_request_status ===
                                            'pending' &&
                                        hasAccess('edit dts orders')
                                    "
                                    class="text-blue-500"
                                    @click="
                                        editOrderDetails(order.order_number)
                                    "
                                >
                                    <Pencil class="size-5" />
                                </button>
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="orders" />
        </TableContainer>

        <Dialog v-model:open="isVariantChoicesVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Order Variant</DialogTitle>
                    <DialogDescription>
                        Please select an order variant to proceed.
                    </DialogDescription>
                </DialogHeader>
                <SelectShad v-model="variant">
                    <SelectTrigger>
                        <SelectValue placeholder="Select a variant" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectLabel>Variants</SelectLabel>
                            <SelectItem
                                v-for="variant in variants"
                                :value="variant.value"
                            >
                                {{ variant.label }}
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </SelectShad>

                <DialogFooter>
                    <Button
                        :disabled="isLoading"
                        @click="proceed"
                        type="submit"
                        class="gap-2"
                    >
                        Proceed
                        <span><Loading v-if="isLoading" /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
