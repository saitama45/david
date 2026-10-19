<script setup>
import { router, usePage } from "@inertiajs/vue3";
import { ref, watch, computed } from "vue";
import { Eye } from "lucide-vue-next";
import { useAuth } from "@/composables/useAuth";

const { hasAccess } = useAuth();

let filter = ref(usePage().props.filters.currentFilter || "pending");
let search = ref(usePage().props.filters.search);

watch(filter, function (value) {
    router.get(
        route("mass-orders-approval.index"),
        { filter: value, search: search.value },
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
    orders: Object,
    counts: Object,
    filters: Object,
    // The most approval levels any template uses (Ordering Template Approval).
    approvalLevels: {
        type: Number,
        default: 1,
    },
});

// One "For Level N" tab per level, only while a template approves in more than one.
const levelTabs = computed(() =>
    props.approvalLevels > 1 ? Array.from({ length: props.approvalLevels }, (_, index) => index + 1) : []
);

const statusBadgeColor = (status) => {
    switch (status?.toUpperCase()) {
        case "RECEIVED": return "bg-green-500 text-white";
        case "APPROVED": return "bg-teal-500 text-white";
        case "INCOMPLETE": return "bg-orange-500 text-white";
        case "PENDING": return "bg-yellow-500 text-white";
        case "COMMITTED": return "bg-blue-500 text-white";
        case "REJECTED": return "bg-red-500 text-white";
        default: return "bg-gray-500 text-white";
    }
};

const formatDisplayDate = (dateString) => {
    if (!dateString || !dateString.includes('-')) return 'N/A';
    try {
        const [year, month, day] = dateString.substring(0, 10).split('-');
        const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
        const monthName = monthNames[parseInt(month, 10) - 1];
        return `${monthName} ${parseInt(day, 10)}, ${year}`;
    } catch (e) {
        return dateString;
    }
};

const formatDisplayDateTime = (dateString) => {
    if (!dateString) return 'N/A';
    try {
        const [datePart, timePart] = dateString.split(' ');
        const [year, month, day] = datePart.split('-');
        const [hourStr, minute] = timePart.split(':');
        let hour = parseInt(hourStr, 10);
        let ampm = 'A.M.';
        if (hour >= 12) {
            ampm = 'P.M.';
            if (hour > 12) hour -= 12;
        }
        if (hour === 0) hour = 12;
        return `${parseInt(month, 10)}/${parseInt(day, 10)}/${year} ${hour}:${minute} ${ampm}`;
    } catch (e) {
        return dateString;
    }
};

const showOrderDetails = (id) => {
    router.get(route('mass-orders-approval.show', id));
};
</script>

<template>
    <Layout heading="Mass Orders Approval">
        <FilterTab>
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

            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('pending')"
                @click="changeFilter('pending')"
            >PENDING
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('pending')"
                >{{ counts.pending }}</Badge>
            </Button>

            <Button
                v-for="level in levelTabs"
                :key="`level_${level}`"
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive(`level_${level}`)"
                :title="`Pending orders waiting for their Level ${level} approval`"
                @click="changeFilter(`level_${level}`)"
            >FOR LEVEL {{ level }}
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive(`level_${level}`)"
                >{{ counts[`level_${level}`] ?? 0 }}</Badge>
            </Button>

            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('approved')"
                @click="changeFilter('approved')"
            >APPROVED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('approved')"
                >{{ counts.approved }}</Badge>
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
                    <TH>Order #</TH>
                    <TH>Delivery Date</TH>
                    <TH>Order Placed Date</TH>
                    <TH>Order Status</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders.data" :key="order.id">
                        <TD>{{ order.id }}</TD>
                        <TD>
                            {{ order.supplier?.name ?? "N/A" }}
                            <span v-if="order.cpo_vendor_name" class="block text-xs text-gray-500">Vendor: {{ order.cpo_vendor_name }}</span>
                        </TD>
                        <TD>{{ order.store_branch?.name ?? "N/A" }}</TD>
                        <TD>{{ order.order_number }}</TD>
                        <TD>{{ formatDisplayDate(order.order_date) }}</TD>
                        <TD>{{ formatDisplayDateTime(order.created_at) }}</TD>
                        <TD>
                            <Badge
                                :class="statusBadgeColor(order.order_status)"
                                class="font-bold"
                            >{{ order.order_status ? order.order_status.toUpperCase() : 'N/A' }}</Badge>
                            <span
                                v-if="order.approval_progress && order.approval_progress.of > 1"
                                class="block text-xs text-gray-500 mt-1"
                            >Level {{ order.approval_progress.level }} of {{ order.approval_progress.of }}</span>
                        </TD>
                        <TD class="flex">
                            <Button
                                v-if="hasAccess('view mass order approval')"
                                @click="showOrderDetails(order.id)"
                                variant="link"
                            >
                                <Eye />
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>
