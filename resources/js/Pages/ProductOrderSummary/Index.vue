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

const exportRoute = computed(() =>
    route("product-orders-summary.export", {
        dateRange: dateRange.value,
        supplierId: supplierId.value,
        branchId: branchId.value,
        search: search.value,
    })
);
const isExportModalVisible = ref(true);
const openExportModal = () => {
    isExportModalVisible.value = true;
};
</script>

<template>
    <Layout
        heading="Item Orders Summary"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <TableContainer>
            <TableHeader class="justify-between gap-5">
                <SearchBar>
                    <Input
                        v-model="search"
                        class="pl-10"
                        placeholder="Search..."
                    />
                </SearchBar>
                <section class="sm:hidden">
                    <Popover>
                        <PopoverTrigger> <Filter /> </PopoverTrigger>
                        <PopoverContent>
                            <DivFlexCol class="gap-3">
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
                            </DivFlexCol>
                        </PopoverContent>
                    </Popover>
                </section>

                <DivFlexCenter class="sm:flex hidden gap-3">
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
                    <!-- <TH>Quantity Delivered</TH> -->
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
                            item.store_order_items_sum_quantity_commited
                        }}</TD>
                        <!-- <TD>{{
                            item.store_order_items_sum_quantity_received
                        }}</TD> -->
                        <TD>
                            <button @click="showProductOrdersDetails(item.id)">
                                <Eye class="size-5" />
                            </button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <MobileTableContainer>
                <MobileTableRow v-for="item in items.data">
                    <MobileTableHeading
                        :title="`${item.name} (${item.inventory_code})`"
                    >
                        <ShowButton
                            @click="showProductOrdersDetails(item.id)"
                        />
                    </MobileTableHeading>
                    <LabelXS
                        >Quantity Ordered:
                        {{
                            item.store_order_items_sum_quantity_approved
                        }}</LabelXS
                    >
                    <LabelXS
                        >Quantity Received:
                        {{
                            item.store_order_items_sum_quantity_received
                        }}</LabelXS
                    >
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="items" />
        </TableContainer>
    </Layout>
</template>
