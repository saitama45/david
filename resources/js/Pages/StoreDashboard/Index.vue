<script setup>
import StatisticOverview from "../../Components/dashboard/StatisticOverview.vue";
import { router } from "@inertiajs/vue3";
import Chart from "primevue/chart";

const props = defineProps({
    branches: {
        type: Object,
        required: true,
    },
    orderCounts: {
        type: Object,
        required: true,
    },
    highStockProducts: {
        type: Object,
        required: true,
    },
    mostUsedProducts: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: {},
    },
    lowOnStockItems: {
        type: Object,
        default: {},
    },
    auth: {
        type: Object,
        required: true,
    },
});

console.log(props.mostUsedProducts);

import { useSelectOptions } from "@/Composables/useSelectOptions";

const { options: branchesOption } = useSelectOptions(props.branches);

onMounted(() => {
    pieChartData.value = setPieChartData();
    barChartData.value = setBarChartData();
    chartOptions.value = setChartOptions();
});

const pieChartData = ref();
const barChartData = ref();
const chartOptions = ref();

const setPieChartData = () => {
    const documentStyle = getComputedStyle(document.body);

    return {
        labels: props.highStockProducts.map((product) => product.name),
        datasets: [
            {
                data: props.highStockProducts.map((product) => product.stock),
                backgroundColor: [
                    documentStyle.getPropertyValue("--p-blue-500"),
                    documentStyle.getPropertyValue("--p-yellow-500"),
                    documentStyle.getPropertyValue("--p-green-500"),
                    documentStyle.getPropertyValue("--p-orange-500"),
                ],
                hoverBackgroundColor: [
                    documentStyle.getPropertyValue("--p-blue-400"),
                    documentStyle.getPropertyValue("--p-yellow-400"),
                    documentStyle.getPropertyValue("--p-green-400"),
                    documentStyle.getPropertyValue("--p-orange-400"),
                ],
            },
        ],
    };
};

const setBarChartData = () => {
    const documentStyle = getComputedStyle(document.body);

    return {
        labels: props.mostUsedProducts.map((product) => product.name),
        datasets: [
            {
                label: "Usage Count",
                data: props.mostUsedProducts.map((product) => product.used),
                backgroundColor: documentStyle.getPropertyValue("--p-blue-500"),
                hoverBackgroundColor:
                    documentStyle.getPropertyValue("--p-blue-400"),
            },
        ],
    };
};

const setChartOptions = () => {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue("--p-text-color");

    return {
        plugins: {
            legend: {
                labels: {
                    usePointStyle: true,
                    color: textColor,
                },
            },
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    color: textColor,
                },
                grid: {
                    color: documentStyle.getPropertyValue("--surface-border"),
                },
            },
            x: {
                ticks: {
                    color: textColor,
                },
                grid: {
                    color: documentStyle.getPropertyValue("--surface-border"),
                },
            },
        },
    };
};

const branchId = ref(
    props.filters.branchId ?? Object.values(branchesOption.value)[0].value + ""
);

watch(branchId, (value) => {
    router.get(
        route("dashboard"),
        {
            branchId: value,
        },
        {}
    );
});

import { Check, ClockArrowUp, BookX } from "lucide-vue-next";
</script>

<template>
    <Layout :heading="`Hello, ${auth.user.first_name}.`">
        <section>
            <Select
                filter
                placeholder="Select a Supplier"
                :options="branchesOption"
                optionLabel="label"
                optionValue="value"
                v-model="branchId"
            >
            </Select>
        </section>
        <section class="grid grid-cols-3 gap-5">
            <StatisticOverview
                heading="Approved Orders"
                :value="orderCounts.approved"
                subheading=""
                :icon="Check"
            />
            <StatisticOverview
                heading="Pending Orders"
                :value="orderCounts.pending"
                subheading=""
                :icon="ClockArrowUp"
            />
            <StatisticOverview
                heading="Rejected Orders"
                :value="orderCounts.rejected"
                subheading=""
                :icon="BookX"
            />
        </section>
        <section class="sm:grid sm:grid-cols-3 gap-5">
            <DivFlexCol class="gap-3">
                <SpanBold>High Stock Items</SpanBold>
                <Chart
                    type="pie"
                    :data="pieChartData"
                    :options="chartOptions"
                    class="w-full"
                />
            </DivFlexCol>
            <DivFlexCol class="gap-3 col-span-2">
                <SpanBold>Most Used Items</SpanBold>
                <Chart
                    type="bar"
                    :data="barChartData"
                    :options="chartOptions"
                />
            </DivFlexCol>
        </section>
        <section>
            <TableContainer>
                <TableHead>
                    <SpanBold>Low on stock items</SpanBold>
                </TableHead>
                <Table>
                    <TableHead>
                        <TH>Name</TH>
                        <TH>Inventory Code</TH>
                        <TH>UOM</TH>
                        <TH>Stock On Hand</TH>
                        <TH>System Estimated Used</TH>
                        <TH>Recorded Used</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-for="item in lowOnStockItems.data">
                            <TD>{{ item.name }}</TD>
                            <TD>{{ item.inventory_code }}</TD>
                            <TD>{{ item.uom }}</TD>
                            <TD>{{ item.stock_on_hand }}</TD>
                            <TD>{{ item.estimated_used }}</TD>
                            <TD>{{ item.recorded_used }}</TD>
                        </tr>
                    </TableBody>
                </Table>
                <MobileTableContainer>
                    <MobileTableRow v-for="item in lowOnStockItems.data">
                        <MobileTableHeading
                            :title="`${item.name} (${item.inventory_code})`"
                        >
                        </MobileTableHeading>
                        <LabelXS
                            >Stock On Hand: {{ item.stock_on_hand }}</LabelXS
                        >
                    </MobileTableRow>
                </MobileTableContainer>
                <Pagination :data="lowOnStockItems" />
            </TableContainer>
        </section>
    </Layout>
</template>
