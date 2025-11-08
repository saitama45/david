<script setup>
import { ref, watch, computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";

import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { throttle } from "lodash";

// Lucide icons
import { Eye } from "lucide-vue-next"; 

const confirm = useConfirm();
const { toast } = useToast();

const props = defineProps({
    orders: {
        type: Object,
        required: true,
    },
    counts: {
        type: Object,
        required: true,
    },
    assignedSuppliers: { // Prop to receive assigned suppliers
        type: Array,
        default: () => [],
    },
});

// Initialize filters with current values from props, defaulting to 'all' for supplier and 'approved' for status
let currentSupplierFilter = ref(usePage().props.filters.currentSupplierFilter || "all");
// Status filter is now static to 'approved' as per request
let currentStatusFilter = ref('approved'); 
let search = ref(usePage().props.filters.search);

// Watch for changes in any filter and update the URL
watch(
    [currentSupplierFilter, search], // Removed currentStatusFilter from watch as it's static
    throttle(([newSupplierFilter, newSearch]) => {
        router.get(
            route("cs-approvals.index"),
            {
                currentSupplierFilter: newSupplierFilter,
                // currentStatusFilter is no longer passed as a dynamic URL parameter
                search: newSearch
            },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

// Function to change the active supplier filter tab
const changeSupplierFilter = (supplierCode) => {
    currentSupplierFilter.value = supplierCode;
};

// Function to change the active status filter tab (no longer used for UI, but kept for consistency if needed elsewhere)
const changeStatusFilter = (status) => {
    // This function will technically still work but won't affect the backend query
    // as currentStatusFilter is now hardcoded in the controller/service.
    // For this specific request, this function is effectively deprecated.
    console.warn("changeStatusFilter called, but status filter is now static to 'approved'.");
};

// Computed property to determine active supplier filter class
const isSupplierFilterActive = (supplierCode) => {
    return currentSupplierFilter.value === supplierCode ? "bg-primary text-white" : "";
};

// Computed property to determine active status filter class (no longer used for UI)
const isStatusFilterActive = (status) => {
    return currentStatusFilter.value === status ? "bg-primary text-white" : "";
};

// Computed property for the export route, including all current filters
const exportRoute = computed(() =>
    route("cs-approvals.export", {
        search: search.value,
        currentSupplierFilter: currentSupplierFilter.value,
        currentStatusFilter: currentStatusFilter.value, // Still pass 'approved' for export
    })
);

// Function to determine status badge color
const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "APPROVED":
            return "bg-green-500 text-white";
        case "RECEIVED":
            return "bg-green-500 text-white";
        case "PENDING":
            return "bg-yellow-500 text-white";
        case "COMMITTED":
            return "bg-blue-500 text-white";
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
</script>

<template>
    <Layout
        heading="CS Review List"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <!-- Supplier Filter Tabs -->
        <FilterTab>
            <!-- "All" tab for suppliers -->
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isSupplierFilterActive('all')"
                @click="changeSupplierFilter('all')"
            >
                ALL
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isSupplierFilterActive('all')"
                >{{ counts.all_approved }}</Badge> <!-- Changed to all_approved -->
            </Button>

            <!-- Dynamic Supplier Tabs -->
            <Button
                v-for="supplier in assignedSuppliers"
                :key="supplier.value"
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isSupplierFilterActive(supplier.value)"
                @click="changeSupplierFilter(supplier.value)"
            >
                {{ supplier.label }}
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isSupplierFilterActive(supplier.value)"
                >{{ counts[`${supplier.value}_approved`] || 0 }}</Badge> <!-- Changed to _approved -->
            </Button>
        </FilterTab>

        <!-- Status Filter Tabs (REMOVED as per request) -->
        <!-- <FilterTab class="mt-4">
            ... (Removed content) ...
        </FilterTab> -->


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
                                :class="statusBadgeColor(order.order_status)"
                                class="font-bold"
                            >{{ order.order_status.toUpperCase() }}</Badge>
                        </TD>
                        <TD class="flex">
                            <Button
                                v-if="hasAccess('view order for cs approval')"
                                @click="showOrderDetails(order.order_number)"
                                variant="link"
                            >
                                <Eye />
                            </Button>
                            <!-- Popover and other actions remain commented out as they were in your original code -->
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <MobileTableContainer>
                <MobileTableRow v-for="order in orders.data" :key="order.id">
                    <MobileTableHeading :title="order.order_number">
                        <ShowButton
                            v-if="hasAccess('view order for cs approval')"
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
