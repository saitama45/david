<script setup>
import { router, usePage } from "@inertiajs/vue3";
import { ref, watch, computed } from "vue";
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
import { throttle } from "lodash";
import { Eye } from "lucide-vue-next";
import { useAuth } from "@/Composables/useAuth";

const confirm = useConfirm();
const { toast } = useToast();
const { hasAccess } = useAuth();

let filter = ref(usePage().props.filters.currentFilter || "open");
let search = ref(usePage().props.filters.search);

watch(filter, function (value) {
    router.get(
        route("interco-approval.index"),
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
            route("interco-approval.index"),
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
        required: true,
    },
    counts: {
        type: Object,
        required: true,
    },
});

const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "APPROVED":
            return "bg-green-500 text-white";
        case "DISAPPROVED":
            return "bg-red-400 text-white";
        case "OPEN":
            return "bg-blue-400 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
};

const showOrderDetails = (id) => {
    router.get(`/interco-approval/show/${id}`);
};

const exportRoute = computed(() =>
    route("interco-approval.export", {
        search: search.value,
        filter: filter.value,
    })
);

// Helper functions copied from Interco/Index.vue for consistent data access
const formatDate = (dateString) => {
    if (!dateString) return 'N/A'
    return new Date(dateString).toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    })
}

const fromStoreName = (order) => {
    return order.from_store_name ||
           order.sendingStore?.name ||
           order.sendingStore?.branch_name ||
           order.sendingStore?.brand_name ||
           'Unknown Sending Store'
}

const toStoreName = (order) => {
    return order.to_store_name ||
           order.store_branch?.name ||
           order.store_branch?.branch_name ||
           'Unknown Receiving Store'
}
</script>

<template>
    <Layout
        heading="Interco Approvals"
        :hasExcelDownload="false"
    >
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
                :class="isFilterActive('open')"
                @click="changeFilter('open')"
            >OPEN
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('open')"
                >{{ counts.open }}</Badge>
            </Button>

            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('disapproved')"
                @click="changeFilter('disapproved')"
            >DISAPPROVED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('disapproved')"
                >{{ counts.disapproved }}</Badge>
            </Button>
        </FilterTab>

        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        v-model="search"
                        placeholder="Search by Interco Number, Reason, or Store"
                    />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>From Store</TH>
                    <TH>To Store</TH>
                    <TH>Interco #</TH>
                    <TH>Transfer Date</TH>
                    <TH>Status</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders.data" :key="order.id">
                        <TD>{{ order.id }}</TD>
                        <TD>{{ fromStoreName(order) }}</TD>
                        <TD>{{ toStoreName(order) }}</TD>
                        <TD>{{ order.interco_number }}</TD>
                        <TD>{{ formatDate(order.order_date) }}</TD>
                        <TD>
                            <Badge
                                :class="statusBadgeColor(order.interco_status)"
                                class="font-bold"
                            >{{ order.interco_status?.toUpperCase() ?? "N/A" }}</Badge>
                        </TD>
                        <TD class="flex">
                            <Button
                                v-if="hasAccess('view interco approvals')"
                                @click="showOrderDetails(order.id)"
                                variant="link"
                            >
                                <Eye />
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <MobileTableContainer>
                <MobileTableRow v-for="order in orders.data" :key="order.id">
                    <MobileTableHeading :title="order.interco_number">
                        <ShowButton
                            v-if="hasAccess('view interco approvals')"
                            @click="showOrderDetails(order.id)"
                        />
                    </MobileTableHeading>
                    <LabelXS
                        >From: {{ fromStoreName(order) }}</LabelXS
                    >
                    <LabelXS
                        >To: {{ toStoreName(order) }}</LabelXS
                    >
                    <LabelXS
                        >Status: {{ order.interco_status?.toUpperCase() }}</LabelXS
                    >
                    <LabelXS>Transfer Date: {{ formatDate(order.order_date) }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>