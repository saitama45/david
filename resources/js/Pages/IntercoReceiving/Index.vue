<script setup>
import { ref, watch, computed } from "vue"; // Ensure ref, watch, computed are imported from vue
import { useSearch } from "@/Composables/useSearch";
import { router, usePage } from "@inertiajs/vue3";
import { Eye, ArrowLeftRight } from "lucide-vue-next";
import TransferStatusBadge from '../Interco/Components/TransferStatusBadge.vue';

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
const { search } = useSearch("interco-receiving.index");

// Watch for changes in filter or search and update the URL
watch(
    [filter, search],
    ([newFilter, newSearch]) => {
        router.get(
            route("interco-receiving.index"),
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
    router.get(`/interco-receiving/show/${id}`);
};

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();

const exportRoute = computed(() => {
    return route("interco-receiving.export", {
        search: search.value,
        currentFilter: filter.value, // Pass the current filter to the export route
    });
});

// Helper functions to match main Interco page
const fromStoreName = (order) => {
    return order.from_store_name ||
           order.sendingStore?.name ||
           order.sendingStore?.branch_name ||
           order.sendingStore?.brand_name ||
           'Unknown Sending Store';
};

const toStoreName = (order) => {
    return order.to_store_name ||
           order.store_branch?.name ||
           order.store_branch?.branch_name ||
           'Unknown Receiving Store';
};

const calculateTotalQuantity = (items) => {
    if (!items || !Array.isArray(items)) return 0;
    return items.reduce((sum, item) => sum + (Number(item.quantity_ordered) || 0), 0);
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
};
</script>

<template>
    <Layout
        heading="Interco Receiving"
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
            >RECEIVED
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

            <!-- "In Transit" tab -->
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('in_transit')"
                @click="changeFilter('in_transit')"
            >IN TRANSIT
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('in_transit')"
                >{{ counts.in_transit }}</Badge>
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
                    <TH>Transfer #</TH>
                    <TH>From Store</TH>
                    <TH>To Store</TH>
                    <TH>Items</TH>
                    <TH>Quantity</TH>
                    <TH>Status</TH>
                    <TH>Date</TH>
                    <TH v-if="hasAccess('view interco receiving')">Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders.data" :key="order.id">
                        <TD>{{ order.interco_number }}</TD>
                        <TD>{{ fromStoreName(order) }}</TD>
                        <TD>{{ toStoreName(order) }}</TD>
                        <TD>{{ order.store_order_items ? order.store_order_items.length : 0 }}</TD>
                        <TD>{{ calculateTotalQuantity(order.store_order_items) }}</TD>
                        <TD>
                            <TransferStatusBadge :status="order.interco_status" />
                        </TD>
                        <TD>{{ formatDate(order.order_date) }}</TD>
                        <TD>
                            <Button
                                v-if="hasAccess('view interco receiving')"
                                variant="outline"
                                @click="viewDetails(order.interco_number)"
                            >
                                <Eye />
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <div
                    v-for="order in orders.data"
                    :key="order.id"
                    class="border-b last:border-b-0 p-4 space-y-3"
                >
                    <!-- Header Row -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-sm">{{ order.interco_number }}</span>
                            <TransferStatusBadge :status="order.interco_status" />
                        </div>
                        <div class="flex gap-1">
                            <Button variant="ghost" size="sm" @click="viewDetails(order.interco_number)">
                                <Eye class="w-4 h-4" />
                            </Button>
                        </div>
                    </div>

                    <!-- Store Information -->
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <span class="text-muted-foreground text-xs">From:</span>
                            <div class="font-medium">{{ fromStoreName(order) }}</div>
                        </div>
                        <div>
                            <span class="text-muted-foreground text-xs">To:</span>
                            <div class="font-medium">{{ toStoreName(order) }}</div>
                        </div>
                    </div>

                    <!-- Order Details -->
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <span class="text-muted-foreground text-xs">Items:</span>
                            <div class="font-medium">{{ order.store_order_items ? order.store_order_items.length : 0 }}</div>
                        </div>
                        <div>
                            <span class="text-muted-foreground text-xs">Quantity:</span>
                            <div class="font-medium">
                                {{ calculateTotalQuantity(order.store_order_items) }}
                            </div>
                        </div>
                    </div>

                    <!-- Date Information -->
                    <div class="flex items-center justify-between text-sm">
                        <div>
                            <span class="text-muted-foreground text-xs">Date:</span>
                            <div class="font-medium">{{ formatDate(order.order_date) }}</div>
                        </div>
                    </div>
                </div>
            </MobileTableContainer>
            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>