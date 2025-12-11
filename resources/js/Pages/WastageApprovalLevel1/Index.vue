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

let filter = ref(usePage().props.filters.currentFilter || "pending");
let search = ref(usePage().props.filters.search);

watch(filter, function (value) {
    router.get(
        route("wastage-approval-lvl1.index"),
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
            route("wastage-approval-lvl1.index"),
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
    wastages: {
        type: Object,
        required: true,
    },
    counts: {
        type: Object,
        required: true,
    },
    stores: {
        type: Array,
        required: true,
    },
});

const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "APPROVED_LVL1":
            return "bg-blue-500 text-white";
        case "CANCELLED":
            return "bg-red-400 text-white";
        case "PENDING":
            return "bg-yellow-500 text-white";
        default:
            return "bg-gray-500 text-white";
    }
};

const showWastageDetails = (id) => {
    router.get(`/wastage-approval-level1/show/${id}`);
};

// Helper functions
const formatDate = (dateString) => {
    if (!dateString) return 'N/A'
    return new Date(dateString).toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    })
}

const storeName = (wastage) => {
    return wastage.store_branch_name ||
           wastage.storeBranch?.name ||
           wastage.storeBranch?.branch_name ||
           wastage.storeBranch?.brand_name ||
           'Unknown Store'
}

const formatCurrency = (amount) => {
    if (!amount) return '₱0.00';
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    }).format(amount);
}
</script>

<template>
    <Layout
        heading="Wastage Approval 1st Level"
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
                :class="isFilterActive('pending')"
                @click="changeFilter('pending')"
            >PENDING
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('pending')"
                >{{ counts.pending }}</Badge>
            </Button>

            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('cancelled')"
                @click="changeFilter('cancelled')"
            >CANCELLED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('cancelled')"
                >{{ counts.cancelled }}</Badge>
            </Button>
        </FilterTab>

        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        v-model="search"
                        placeholder="Search by Wastage Number, Reason, or Store"
                    />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Wastage #</TH>
                    <TH>Store</TH>
                    <TH>Total Qty</TH>
                    <TH>Items</TH>
                    <TH v-if="hasAccess('view total cost in wastage approval level 1')">Total Cost</TH>
                    <TH>Status</TH>
                    <TH>Date</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="wastage in wastages.data" :key="wastage.id">
                        <TD>{{ wastage.id }}</TD>
                        <TD>{{ wastage.wastage_no }}</TD>
                        <TD>{{ storeName(wastage) }}</TD>
                        <TD>{{ wastage.total_quantity || 0 }}</TD>
                        <TD>{{ wastage.items_count || 0 }}</TD>
                        <TD v-if="hasAccess('view total cost in wastage approval level 1')">{{ formatCurrency(wastage.total_cost) }}</TD>
                        <TD :class="hasAccess('view total cost in wastage approval level 1') ? '' : 'w-32'">
                            <Badge
                                :class="statusBadgeColor(wastage.wastage_status)"
                                class="font-bold"
                            >{{ wastage.wastage_status?.toUpperCase().replace('_', ' ') ?? "N/A" }}</Badge>
                        </TD>
                        <TD v-if="hasAccess('view total cost in wastage approval level 1')">{{ formatDate(wastage.created_at) }}</TD>
                        <TD v-if="hasAccess('view total cost in wastage approval level 1')" class="flex">
                            <Button
                                v-if="hasAccess('view wastage approval level 1')"
                                @click="showWastageDetails(wastage.id)"
                                variant="link"
                            >
                                <Eye />
                            </Button>
                        </TD>
                        <TD v-if="!hasAccess('view total cost in wastage approval level 1')">{{ formatDate(wastage.created_at) }}</TD>
                        <TD v-if="!hasAccess('view total cost in wastage approval level 1')" class="flex">
                            <Button
                                v-if="hasAccess('view wastage approval level 1')"
                                @click="showWastageDetails(wastage.id)"
                                variant="link"
                            >
                                <Eye />
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <MobileTableContainer>
                <MobileTableRow v-for="wastage in wastages.data" :key="wastage.id">
                    <MobileTableHeading :title="wastage.wastage_no">
                        <ShowButton
                            v-if="hasAccess('view wastage approval level 1')"
                            @click="showWastageDetails(wastage.id)"
                        />
                    </MobileTableHeading>
                    <LabelXS
                        >Store: {{ storeName(wastage) }}</LabelXS
                    >
                    <LabelXS
                        >Status: {{ wastage.wastage_status?.toUpperCase().replace('_', ' ') }}</LabelXS
                    >
                    <LabelXS
                        v-if="hasAccess('view total cost in wastage approval level 1')"
                    >Total: {{ formatCurrency(wastage.total_cost) }} ({{ wastage.total_quantity || 0 }} items)</LabelXS
                    >
                    <LabelXS>Date: {{ formatDate(wastage.created_at) }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="wastages" />
        </TableContainer>
    </Layout>
</template>