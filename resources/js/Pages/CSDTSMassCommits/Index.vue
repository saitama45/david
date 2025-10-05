<script setup>
import { router, Head, usePage } from "@inertiajs/vue3";
import { ref, watch } from 'vue';
import { Pencil } from 'lucide-vue-next';
import { throttle } from "lodash";
import { useAuth } from "@/Composables/useAuth";

const props = defineProps({
    batches: {
        type: Object,
        default: () => ({ data: [] })
    },
    filters: {
        type: Object,
        default: () => ({})
    },
    counts: {
        type: Object,
        default: () => ({})
    }
});

const { hasAccess } = useAuth();

// Filter logic
let filterQuery = ref((usePage().props.filters?.filterQuery || "approved").toString());

const performFilter = throttle(() => {
    router.get(
        route("cs-dts-mass-commits.index"),
        {
            filterQuery: filterQuery.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    );
}, 500);

watch(filterQuery, performFilter);

const changeFilter = (currentFilter) => {
    filterQuery.value = currentFilter;
};

const isFilterActive = (filter) => {
    return filterQuery.value === filter ? "bg-primary text-white" : "";
};

const statusBadgeColor = (status) => {
    switch (status?.toUpperCase()) {
        case "APPROVED": return "bg-teal-500 text-white";
        case "COMMITTED": return "bg-blue-500 text-white";
        case "INCOMPLETE": return "bg-orange-500 text-white";
        case "RECEIVED": return "bg-green-500 text-white";
        case "PENDING": return "bg-yellow-500 text-white";
        case "COMPLETED": return "bg-green-500 text-white";
        case "CANCELLED": return "bg-red-500 text-white";
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

const editBatchDetails = (batchNumber) => router.get(route('cs-dts-mass-commits.edit', batchNumber));

</script>

<template>
    <Head title="CS DTS Mass Commits" />

    <Layout
        heading="CS DTS Mass Commits"
        :hasButton="false"
    >

        <FilterTab>
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('all')"
                @click="changeFilter('all')"
            >
                ALL
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('all')"
                >{{ counts.all || 0 }}</Badge>
            </Button>

            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('approved')"
                @click="changeFilter('approved')"
            >
                APPROVED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('approved')"
                >{{ counts.approved || 0 }}</Badge>
            </Button>

            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('committed')"
                @click="changeFilter('committed')"
            >
                COMMITTED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('committed')"
                >{{ counts.committed || 0 }}</Badge>
            </Button>
        </FilterTab>

        <TableContainer>
            <div class="hidden md:block">
                <Table>
                    <TableHead>
                        <TH>Batch #</TH>
                        <TH>Variant</TH>
                        <TH>Delivery Dates</TH>
                        <TH>Total Orders</TH>
                        <TH>Total Quantity</TH>
                        <TH>Status</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-if="!batches.data || batches.data.length === 0">
                            <td colspan="7" class="text-center py-4">No mass orders found.</td>
                        </tr>
                        <tr v-for="batch in batches.data" :key="batch.id">
                            <TD>{{ batch.batch_number }}</TD>
                            <TD><span class="font-semibold text-blue-600">{{ batch.variant }}</span></TD>
                            <TD>{{ formatDisplayDate(batch.date_from) }} - {{ formatDisplayDate(batch.date_to) }}</TD>
                            <TD>{{ batch.total_orders }}</TD>
                            <TD>{{ batch.total_quantity }}</TD>
                            <TD>
                                <Badge :class="statusBadgeColor(batch.status)" class="font-bold">{{ batch.status ? batch.status.toUpperCase() : 'N/A' }}</Badge>
                            </TD>
                            <TD>
                                <DivFlexCenter class="gap-3">
                                    <button v-if="hasAccess('edit cs dts mass commit') && batch.can_edit" class="text-blue-500" @click="editBatchDetails(batch.batch_number)">
                                        <Pencil class="size-5" />
                                    </button>
                                </DivFlexCenter>
                            </TD>
                        </tr>
                    </TableBody>
                </Table>
            </div>

            <MobileTableContainer class="md:hidden">
                <MobileTableRow v-for="batch in batches.data" :key="batch.id">
                    <MobileTableHeading :title="batch.batch_number">
                        <button v-if="hasAccess('edit cs dts mass commit') && batch.can_edit" class="text-blue-500" @click="editBatchDetails(batch.batch_number)">
                            <Pencil class="size-5" />
                        </button>
                    </MobileTableHeading>
                    <LabelXS>Variant: <span class="font-semibold text-blue-600">{{ batch.variant }}</span></LabelXS>
                    <LabelXS>Delivery Dates: {{ formatDisplayDate(batch.date_from) }} - {{ formatDisplayDate(batch.date_to) }}</LabelXS>
                    <LabelXS>Total Orders: {{ batch.total_orders }}</LabelXS>
                    <LabelXS>Total Quantity: {{ batch.total_quantity }}</LabelXS>
                    <LabelXS>Status: <span :class="statusBadgeColor(batch.status)" class="font-semibold p-1 rounded text-white">{{ batch.status ? batch.status.toUpperCase() : 'N/A' }}</span></LabelXS>
                </MobileTableRow>
            </MobileTableContainer>

            <Pagination :data="batches" />
        </TableContainer>
    </Layout>
</template>