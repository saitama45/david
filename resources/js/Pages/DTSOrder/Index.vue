<script setup>
import { router, usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";

let filterQuery = ref(usePage().props.filters.filterQuery || "pending");
let search = ref(usePage().props.filters.search);
const changeFilter = (currentFilter) => {
    filterQuery.value = currentFilter;
};
let branchId = ref(usePage().props.filters.branchId);

let from = ref(usePage().props.from ?? null);

let to = ref(usePage().props.to ?? null);

const variant = ref("");
const isLoading = false;

const isVariantChoicesVisible = ref(false);
const showVariantChoices = () => {
    isVariantChoicesVisible.value = true;
};

watch(
    search,
    throttle(function (value) {
        router.get(
            route("store-orders.index"),
            {
                search: value,
                filterQuery: filterQuery.value,
                from: from.value,
                to: to.value,
                branchId: branchId.value,
            },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

watch(filterQuery, function (value) {
    router.get(
        route("dts-orders.index"),
        {
            search: search.value,
            filterQuery: value,
            from: from.value,
            to: to.value,
            branchId: branchId.value,
        },
        {
            preserveState: true,
            replace: true,
        }
    );
});

watch(from, (value) => {
    router.get(
        route("dts-orders.index"),
        {
            search: search.value,
            filterQuery: filterQuery.value,
            from: value,
            to: to.value,
            branchId: branchId.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

watch(to, (value) => {
    router.get(
        route("dts-orders.index"),
        {
            search: search.value,
            filterQuery: filterQuery.value,
            from: from.value,
            to: value,
            branchId: branchId.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

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

const resetFilter = () => {
    (from.value = null),
        (to.value = null),
        (branchId.value = null),
        (search.value = null);
};

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

const exportRoute = computed(() =>
    route("dts-orders.export", {
        search: search.value,
        branchId: branchId.value,
        filterQuery: filterQuery.value,
        from: from.value,
        to: to.value,
    })
);
</script>
<template>
    <Layout
        heading="DTS Orders"
        :hasButton="hasAccess('create dts orders')"
        :handleClick="showVariantChoices"
        buttonName="Create New Order"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <FilterTab>
            <FilterTabButton
                label="All"
                filter="all"
                :currentFilter="filterQuery"
                @click="changeFilter('all')"
            />
            <FilterTabButton
                label="Pending"
                filter="pending"
                :currentFilter="filterQuery"
                @click="changeFilter('pending')"
            />
            <FilterTabButton
                label="Rejected"
                filter="rejected"
                :currentFilter="filterQuery"
                @click="changeFilter('rejected')"
            />
        </FilterTab>

        <TableContainer>
            <TableHeader>
                <!-- Search Bar-->
                <SearchBar>
                    <Input
                        v-model="search"
                        id="search"
                        type="text"
                        placeholder="Search for order number"
                        class="pl-10 sm:max-w-full max-w-64"
                    />
                </SearchBar>
                <!-- Filters -->
                <DivFlexCenter class="gap-5">
                    <Popover>
                        <PopoverTrigger> <Filter /> </PopoverTrigger>
                        <PopoverContent>
                            <div class="flex justify-end">
                                <Button
                                    @click="resetFilter"
                                    variant="link"
                                    class="text-end text-red-500 text-xs"
                                >
                                    Reset Filter
                                </Button>
                            </div>
                            <label class="text-xs">From</label>
                            <Input type="date" v-model="from" />
                            <label class="text-xs">To</label>
                            <Input type="date" v-model="to" />
                            <label class="text-xs">Store</label>
                            <Select v-model="branchId">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a store" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectLabel>Stores</SelectLabel>
                                        <SelectItem
                                            v-for="(value, key) in branches"
                                            :key="key"
                                            :value="key"
                                        >
                                            {{ value }}
                                        </SelectItem>
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </PopoverContent>
                    </Popover>
                </DivFlexCenter>
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

            <MobileTableContainer>
                <MobileTableRow v-for="order in orders.data">
                    <MobileTableHeading :title="order.order_number">
                        <ShowButton
                            class="size-5"
                            v-if="hasAccess('view dts order')"
                            @click="showOrderDetails(order.order_number)"
                        />
                        <EditButton
                            class="size-5"
                            v-if="
                                order.order_request_status === 'pending' &&
                                hasAccess('edit dts orders')
                            "
                            @click="editOrderDetails(order.order_number)"
                        />
                    </MobileTableHeading>
                    <LabelXS>
                        Status:
                        {{ order.order_request_status.toUpperCase() }}</LabelXS
                    >
                    <LabelXS>
                        Order Date:
                        {{ order.order_date }}</LabelXS
                    >
                </MobileTableRow>
            </MobileTableContainer>
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
