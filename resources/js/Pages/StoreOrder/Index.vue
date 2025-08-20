<script setup>
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { useForm } from "@inertiajs/vue3";
import Dialog from "primevue/dialog";
import { useSelectOptions } from "@/Composables/useSelectOptions";

import { throttle } from "lodash";
import { ref, watch, computed } from 'vue'; // Added 'computed' import

const handleClick = () => {
    router.get("/store-orders/create");
};

const props = defineProps({
    orders: {
        type: Object,
    },
    branches: {
        type: Object,
    },
});

const { options: branchesOptions } = useSelectOptions(props.branches);
console.log(branchesOptions);

let filterQuery = ref(
    (usePage().props.filters.filterQuery || "pending").toString()
);

const showOrderDetails = (id) => {
    router.get(`/store-orders/show/${id}`);
};

const editOrderDetails = (id) => {
    router.get(`/store-orders/edit/${id}`);
};

let from = ref(
    usePage().props.from ??
        // new Intl.DateTimeFormat("en-CA", {
        //     year: "numeric",
        //     month: "2-digit",
        //     day: "2-digit",
        // }).format(new Date())
        null
);

let to = ref(
    usePage().props.to ??
        // new Intl.DateTimeFormat("en-CA", {
        //     year: "numeric",
        //     month: "2-digit",
        //     day: "2-digit",
        // }).format(new Date())
        null
);

let branchId = ref(usePage().props.filters.branchId);
let search = ref(usePage().props.filters.search);

watch(from, (value) => {
    router.get(
        route("store-orders.index"),
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
        route("store-orders.index"),
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

watch(branchId, (value) => {
    router.get(
        route("store-orders.index"),
        {
            search: search.value,
            filterQuery: filterQuery.value,
            from: from.value,
            to: to.value,
            branchId: value,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

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

const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "APPROVED":
            return "bg-green-500 text-white";
        case "RECEIVED":
            return "bg-green-500 text-white";
        case "PENDING":
            return "bg-yellow-500 text-white";
        case "COMMITED":
            return "bg-blue-500 text-white";
        case "REJECTED":
            return "bg-red-400 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
};
const resetFilter = () => {
    (from.value = null),
        (to.value = null),
        (branchId.value = null),
        (search.value = null);
};

watch(filterQuery, function (value) {
    router.get(
        route("store-orders.index"),
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

const changeFilter = (currentFilter) => {
    filterQuery.value = currentFilter;
};

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();

const exportRoute = computed(() =>
    route("store-orders.export", {
        search: search.value,
        branchId: branchId.value,
        filterQuery: filterQuery.value,
        from: from.value,
        to: to.value,
    })
);

const pdfForm = useForm({
    branch: null,
    start_date: null,
    end_date: null,
});

const isPdfModalVisible = ref(false);
const openPDFModal = () => {
    isPdfModalVisible.value = true;
};
const pdfRoute = computed(() =>
    route("pdf-export.store-orders", {
        branch: pdfForm.branch,
        start_date: pdfForm.start_date,
        end_date: pdfForm.end_date,
    })
);

const exportPdf = () => {
    pdfForm.clearErrors();
    if (!pdfForm.branch) {
        pdfForm.errors.branch = "Branch is required";
    }
    if (!pdfForm.start_date) {
        pdfForm.errors.start_date = "Start date is required";
    }
    if (!pdfForm.end_date) {
        pdfForm.errors.end_date = "End date is required";
    }
    if (pdfForm.start_date && pdfForm.end_date) {
        const startDate = new Date(pdfForm.start_date);
        const endDate = new Date(pdfForm.end_date);

        if (endDate < startDate) {
            pdfForm.errors.end_date =
                "End date cannot be earlier than start date";
        }
    }
    if (Object.keys(pdfForm.errors).length > 0) {
        return;
    }
    window.open(pdfRoute.value, "_blank");
    isPdfModalVisible.value = false;
};

// Function to format date and time for consistent display (now only date)
const formatDisplayDateTime = (dateString) => {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) {
            console.warn("Invalid date string for formatting:", dateString);
            return dateString;
        }
        const options = {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        };
        return new Intl.DateTimeFormat('en-US', options).format(date);
    } catch (e) {
        console.error("Error formatting date:", dateString, e);
        return dateString;
    }
};

console.log(props.branches);
</script>

<template>
    <Layout
        heading="Store Orders"
        :hasButton="hasAccess('create store orders')"
        buttonName="Create New Order"
        :handleClick="handleClick"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <Dialog
            v-model:visible="isPdfModalVisible"
            modal
            header="Export to PDF"
            :style="{ width: '25rem' }"
        >
            <DivFlexCol>
                <InputContainer>
                    <LabelXS>Branch</LabelXS>
                    <Select
                        v-model="pdfForm.branch"
                        filter
                        class="w-full"
                        placeholder="Select a branch"
                        :options="branchesOptions"
                        optionLabel="label"
                        optionValue="value"
                    />
                    <FormError>{{ pdfForm.errors.branch }}</FormError>
                </InputContainer>

                <InputContainer>
                    <LabelXS>Start Date</LabelXS>
                    <DatePicker showIcon v-model="pdfForm.start_date" />
                    <FormError>{{ pdfForm.errors.start_date }}</FormError>
                </InputContainer>
                <InputContainer>
                    <LabelXS>End Date</LabelXS>
                    <DatePicker showIcon v-model="pdfForm.end_date" />
                    <FormError>{{ pdfForm.errors.end_date }}</FormError>
                </InputContainer>

                <div class="flex justify-center mt-3">
                    <Button @click="exportPdf" class="w-full">Export</Button>
                </div>
            </DivFlexCol>
        </Dialog>
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
                label="Approved"
                filter="approved"
                :currentFilter="filterQuery"
                @click="changeFilter('approved')"
            />
            <FilterTabButton
                label="Commited"
                filter="committed"
                :currentFilter="filterQuery"
                @click="changeFilter('committed')"
            />
            <FilterTabButton
                label="Received"
                filter="received"
                :currentFilter="filterQuery"
                @click="changeFilter('received')"
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
                    <Button @click="openPDFModal">Export To PDF</Button>
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
                            <SelectShad v-model="branchId">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a store" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectLabel>Stores</SelectLabel>
                                        <SelectItem
                                            v-for="(value, key) in branches"
                                            :value="key"
                                        >
                                            {{ value }}
                                        </SelectItem>
                                    </SelectGroup>
                                </SelectContent>
                            </SelectShad>
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
                    <TH>Delivery Date</TH>
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
                        <TD>{{ formatDisplayDateTime(order.order_date) }}</TD>
                        <TD>{{ formatDisplayDateTime(order.created_at) }}</TD>
                        <TD>
                            <Badge
                                :class="statusBadgeColor(order.order_status)"
                                class="font-bold"
                                >{{ order.order_status.toUpperCase() }}</Badge
                            >
                        </TD>
                        <TD>
                            <DivFlexCenter class="gap-3">
                                <button
                                    v-if="hasAccess('view store order')"
                                    @click="
                                        showOrderDetails(order.order_number)
                                    "
                                >
                                    <Eye class="size-5" />
                                </button>
                                <button
                                    v-if="
                                        order.order_status === 'pending' &&
                                        hasAccess('edit store orders')
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
                            v-if="hasAccess('view store order')"
                            @click="showOrderDetails(order.order_number)"
                        />
                        <EditButton
                            class="size-5"
                            v-if="
                                order.order_status === 'pending' &&
                                hasAccess('edit store orders')
                            "
                            @click="editOrderDetails(order.order_number)"
                        />
                    </MobileTableHeading>
                    <LabelXS>
                        Status:
                        {{ order.order_status.toUpperCase() }}</LabelXS
                    >
                    <LabelXS>
                        Delivery Date:
                        {{ formatDisplayDateTime(order.order_date) }}</LabelXS
                    >
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>
