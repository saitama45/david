<script setup>
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { useForm } from "@inertiajs/vue3";
import Dialog from "primevue/dialog";
import { useSelectOptions } from "@/Composables/useSelectOptions";

import { throttle } from "lodash";
import { ref, watch, computed } from 'vue';

const props = defineProps({
    orders: {
        type: Object,
    },
    branches: {
        type: Object,
    },
});

const { options: branchesOptions } = useSelectOptions(props.branches);

let filterQuery = ref(
    (usePage().props.filters.filterQuery || "pending").toString() // Change 'all' to 'pending'
);

const showOrderDetails = (id) => {
    router.get(`/dts-orders/show/${id}`);
};

const editOrderDetails = (id) => {
    router.get(`/dts-orders/edit/${id}`);
};

let from = ref(usePage().props.filters.from ?? null);
let to = ref(usePage().props.filters.to ?? null);
let branchId = ref(usePage().props.filters.branchId);
let search = ref(usePage().props.filters.search);

// Update route to use dts-orders.index
const updateFilters = throttle(() => {
    router.get(
        route("dts-orders.index"),
        {
            search: search.value || null,
            filterQuery: filterQuery.value === 'all' ? undefined : filterQuery.value, // Send undefined for 'all'
            from: from.value || null,
            to: to.value || null,
            branchId: branchId.value || null,
        },
        {
            preserveState: true,
            replace: true,
        }
    );
}, 500);

watch(from, updateFilters);
watch(to, updateFilters);
watch(branchId, updateFilters);
watch(search, updateFilters);
watch(filterQuery, updateFilters);

const statusBadgeColor = (status) => {
    if (!status) {
        return "bg-gray-400 text-white"; // Or some default color for unknown status
    }
    switch (status.toUpperCase()) {
        case "APPROVED":
        case "RECEIVED":
            return "bg-green-500 text-white";
        case "PENDING":
        case "COMMITED":
            return "bg-yellow-500 text-white";
        case "REJECTED":
            return "bg-red-400 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
};

const resetFilter = () => {
    from.value = null;
    to.value = null;
    branchId.value = null;
    search.value = null;
    filterQuery.value = 'all';
};

const changeFilter = (currentFilter) => {
    filterQuery.value = currentFilter;
    if (currentFilter === 'all') {
        // Reset other filters when 'All' is selected
        search.value = null;
        from.value = null;
        to.value = null;
        branchId.value = null;
    }
};

import { useAuth } from "@/Composables/useAuth";
const { hasAccess } = useAuth();

// Update export route to use dts-orders.export
const exportRoute = computed(() =>
    route("dts-orders.export", {
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

// Update PDF route to a DTS specific one if it exists
const pdfRoute = computed(() =>
    route("pdf-export.dts-orders", {
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
            pdfForm.errors.end_date = "End date cannot be earlier than start date";
        }
    }
    if (Object.keys(pdfForm.errors).length > 0) {
        return;
    }
    window.open(pdfRoute.value, "_blank");
    isPdfModalVisible.value = false;
};

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

const navigateToCreate = () => {
    router.get(route('dts-orders.create'));
};

</script>

<template>
    <Layout
        heading="DTS Orders"
        :hasButton="hasAccess('create dts orders')"
        buttonName="Create DTS Order"
        :handleClick="navigateToCreate"
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
                            >{{ order.order_status.toUpperCase() }}</Badge>
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
