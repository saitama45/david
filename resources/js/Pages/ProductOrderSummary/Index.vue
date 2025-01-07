<script setup>
import { router, usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import dayjs from "dayjs";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import Dialog from "primevue/dialog";

let dateRange = ref(usePage().props.filters.dateRange);
let supplierId = ref(usePage().props.filters.supplierId);
let search = ref(usePage().props.filters.search);
let branchId = ref(usePage().props.filters.branchId);
const isLoading = false;
const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
    suppliers: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
});

const { options: suppliersOption } = useSelectOptions(props.suppliers);
const { options: branchesOption } = useSelectOptions(props.branches);

const showProductOrdersDetails = (id) => {
    router.get(
        `/product-orders-summary/show/${id}`,
        {
            dateRange: dateRange.value,
            supplierId: supplierId.value,
            branchId: branchId.value,
        },
        {
            preserveState: true,
        }
    );
};

watch(branchId, (value) => {
    console.log(value);
    router.get(
        route("product-orders-summary.index"),
        {
            dateRange: dateRange.value,
            search: search.value,
            supplierId: supplierId.value,
            branchId: value,
        },
        {
            preserveState: true,
            replace: true,
        }
    );
});

watch(dateRange, (value) => {
    router.get(
        route("product-orders-summary.index"),
        {
            dateRange: value,
            search: search.value,
            supplierId: supplierId.value,
            branchId: branchId.value,
        },
        {
            preserveState: true,
            replace: true,
        }
    );
});

watch(supplierId, (value) => {
    router.get(
        route("product-orders-summary.index"),
        {
            supplierId: value,
            dateRange: dateRange.value,
            search: search.value,
            branchId: branchId.value,
        },
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
            route("product-orders-summary.index"),
            {
                search: value,
                dateRange: dateRange.value,
                supplierId: supplierId.value,
                branchId: branchId.value,
            },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

onMounted(() => {
    const filters = usePage().props.filters;
    if (filters.dateRange) {
        if (typeof filters.dateRange === "string") {
            const [start, end] = filters.dateRange.split(",");
            dateRange.value = [dayjs(start).toDate(), dayjs(end).toDate()];
        } else {
            dateRange.value = filters.dateRange.map((date) =>
                dayjs(date).toDate()
            );
        }
    } else {
        const today = dayjs();
        dateRange.value = [today.toDate(), today.toDate()];
    }
});

const downloadPdf = () => {
    const data = {
        data: {
            dateRange: dateRange.value,
            supplierId: supplierId.value,
            branchId: branchId.value,
            search: search.value,
        },
        preserveState: true,
        preserveScroll: true,
        replace: true,
    };

    window.open(
        route("product-orders-summary.download-orders-summary-pdf", data.data),
        "_blank"
    );
};

const isExportModalVisible = ref(true);
const openExportModal = () => {
    isExportModalVisible.value = true;
};
</script>

<template>
    <Layout
        heading="Item Orders Summary"
        :hasButton="true"
        buttonName="Export to Excel"
        :handleClick="downloadPdf"
    >
        <TableContainer>
            <TableHeader class="justify-between">
                <SearchBar>
                    <Input
                        v-model="search"
                        class="pl-10"
                        placeholder="Search..."
                    />
                </SearchBar>
                <DivFlexCenter class="gap-3">
                    <MultiSelect
                        filter
                        placeholder="Filter By Store"
                        :options="branchesOption"
                        optionLabel="label"
                        optionValue="value"
                        v-model="branchId"
                        showClear
                        class="max-w-64"
                    >
                    </MultiSelect>
                    <Select
                        placeholder="Filter By Supplier"
                        :options="suppliersOption"
                        optionLabel="label"
                        optionValue="value"
                        v-model="supplierId"
                        showClear
                    >
                    </Select>
                    <DatePicker
                        class="min-w-64"
                        selectionMode="range"
                        v-model="dateRange"
                        :manualInput="false"
                        :format="'YYYY-MM-DD'"
                    />
                </DivFlexCenter>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Item</TH>
                    <TH>Inventory Code</TH>
                    <TH>Conversion</TH>
                    <TH>UOM</TH>
                    <TH>Quantity Ordered</TH>
                    <TH>Quantity Delivered</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.id }}</TD>
                        <TD>{{ item.name }}</TD>
                        <TD>{{ item.inventory_code }}</TD>
                        <TD>{{ item.conversion }}</TD>
                        <TD>{{ item.unit_of_measurement.name }}</TD>
                        <TD>{{
                            item.store_order_items_sum_quantity_ordered
                        }}</TD>
                        <TD>{{
                            item.store_order_items_sum_quantity_received
                        }}</TD>
                        <TD>
                            <button @click="showProductOrdersDetails(item.id)">
                                <Eye class="size-5" />
                            </button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="items" />
        </TableContainer>
    </Layout>
</template>
