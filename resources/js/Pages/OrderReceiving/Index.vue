<script setup>
import { ref, watch, computed } from "vue"; // Ensure ref, watch, computed are imported from vue
import { useSearch } from "@/Composables/useSearch";
import { router, usePage } from "@inertiajs/vue3";

const props = defineProps({
    orders: {
        type: Object,
        required: true,
    },
    counts: {
        type: Object,
        required: true,
    },
});

// Initialize filter with currentFilter from props, defaulting to 'all'
let filter = ref(usePage().props.filters.currentFilter || "all");
const { search } = useSearch("orders-receiving.index");

// Watch for changes in filter or search and update the URL
watch(
    [filter, search],
    ([newFilter, newSearch]) => {
        router.get(
            route("orders-receiving.index"),
            { currentFilter: newFilter, search: newSearch },
            {
                preserveState: true,
                replace: true,
            }
        );
    }
);

const changeFilter = (currentFilter) => {
    filter.value = currentFilter;
};

const isFilterActive = (currentFilter) => {
    return filter.value === currentFilter ? "bg-primary text-white" : "";
};

const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "RECEIVED":
            return "bg-green-500 text-white";
        case "PENDING": // This status might not appear in this list, but keeping for completeness
            return "bg-yellow-500 text-white";
        case "INCOMPLETE":
            return "bg-orange-500 text-white";
        case "COMMITED": // Keeping this case, and the tab is now re-added
            return "bg-blue-400 text-white";
        default:
            return "bg-gray-500 text-white"; // Fallback for other statuses
    }
};

const viewDetails = (id) => {
    router.get(`/orders-receiving/show/${id}`);
};

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();

const exportRoute = computed(() => {
    return route("orders-receiving.export", {
        search: search.value,
        currentFilter: filter.value, // Pass the current filter to the export route
    });
});

const getSupplierDisplayName = (supplier, variant) => {
    if (!supplier?.name) return 'N/A';
    return supplier.name === 'DROPSHIPPING' && variant === 'mass regular' ? 'FRUITS AND VEGETABLES' : supplier.name;
};

// Debug logging to check orders data
watch(() => props.orders, (newOrders) => {
    console.log('OrderReceiving Index - Orders data updated:', newOrders);
    if (newOrders.data) {
        newOrders.data.forEach((order, index) => {
            console.log(`Order ${index + 1}:`, {
                id: order.id,
                supplier_id: order.supplier_id,
                supplier_name: order.supplier?.name,
                variant: order.variant,
                remarks: order.remarks,
                is_dropshipping: order.supplier_id === 5
            });
        });
    }
}, { immediate: true, deep: true });
</script>

<template>
    <Layout
        heading="Inbound Orders"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <FilterTab>
            <!-- "All" tab -->
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('all')"
                @click="changeFilter('all')"
            >ALL
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('all')"
                >{{ counts.all }}</Badge>
            </Button>

            <!-- "Received" tab -->
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('received')"
                @click="changeFilter('received')"
            >COMPLETE
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('received')"
                >{{ counts.received }}</Badge>
            </Button>

            <!-- "Incomplete" tab -->
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('incomplete')"
                @click="changeFilter('incomplete')"
            >INCOMPLETE
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('incomplete')"
                >{{ counts.incomplete }}</Badge>
            </Button>
            
            <!-- Re-added "COMMITED" tab as per request -->
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('commited')"
                @click="changeFilter('commited')"
            >COMMITED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('commited')"
                >{{ counts.commited }}</Badge>
            </Button>
        </FilterTab>

        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        v-model="search"
                        placeholder="Search..."
                    />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Supplier</TH>
                    <TH>Store</TH>
                    <TH>SO/PO Number</TH>
                    <TH>Order #</TH>
                    <TH>Delivery Date</TH>
                    <TH>Order Placed Date</TH>
                    <TH>Variant</TH>
                    <TH>Receiving Status</TH>
                    <TH v-if="hasAccess('view approved order')">Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders.data" :key="order.id">
                        <TD>{{ order.id }}</TD>
                        <TD>{{ getSupplierDisplayName(order.supplier, order.variant) }}</TD>
                        <TD>{{ order.store_branch?.name ?? "N/A" }}</TD>
                        <TD>{{ order.delivery_receipts && order.delivery_receipts.length > 0 ? order.delivery_receipts[0].sap_so_number : "N/A" }}</TD>
                        <TD>{{ order.order_number }}</TD>
                        <TD>{{ order.order_date }}</TD>
                        <TD>{{ order.created_at }}</TD>
                        <TD>
                            <span v-if="String(order.supplier_id) === '5' && order.variant && order.variant !== 'N/A' && order.variant !== 'mass dts'">
                                {{ order.variant }}
                            </span>
                        </TD>
                        <TD>
                            <Badge
                                :class="statusBadgeColor(order.order_status)"
                                class="font-bold"
                            >{{
                                order.order_status.toUpperCase() === 'RECEIVED' ? 'COMPLETE' : order.order_status.toUpperCase().replace("_", " ")
                            }}</Badge>
                        </TD>
                        <TD>
                            <Button
                                v-if="hasAccess('view approved order')"
                                variant="outline"
                                @click="viewDetails(order.order_number)"
                            >
                                <Eye />
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="order in orders.data" :key="order.id">
                    <MobileTableHeading :title="order.order_number">
                        <ShowButton
                            v-if="hasAccess('view approved order')"
                            @click="viewDetails(order.order_number)"
                        />
                    </MobileTableHeading>
                    <LabelXS>Store: {{ order.store_branch?.name ?? "N/A" }}</LabelXS>
                    <LabelXS>SO/PO Number: {{ order.delivery_receipts && order.delivery_receipts.length > 0 ? order.delivery_receipts[0].sap_so_number : "N/A" }}</LabelXS>
                    <LabelXS
                        >Receiving Status:
                        {{ order.order_status.toUpperCase() === 'RECEIVED' ? 'COMPLETE' : order.order_status.toUpperCase() }}</LabelXS
                    >
                    <LabelXS>Order Date: {{ order.order_date }}</LabelXS>
                    <LabelXS v-if="String(order.supplier_id) === '5' && order.variant && order.variant !== 'N/A' && order.variant !== 'mass dts'">
                        Variant: {{ order.variant }}
                    </LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>
