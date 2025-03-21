<script setup>
import StatisticOverview from "../../Components/dashboard/StatisticOverview.vue";
import Chart from "primevue/chart";
import { router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/Composables/useSelectOptions";

const { branches, timePeriods, filters, sales, cogs, dio, top_10, dpo } =
    defineProps({
        branches: {
            type: Object,
            required: true,
        },
        timePeriods: {
            type: Object,
            required: true,
        },
        filters: {
            type: Object,
            required: true,
        },
        sales: {
            type: String,
            required: true,
        },
        inventories: {
            type: String,
            required: true,
        },
        upcomingInventories: {
            type: String,
            required: true,
        },
        accountPayable: {
            type: String,
            required: true,
        },
        cogs: {
            type: String,
            required: true,
        },
        dio: {
            type: Number,
            required: true,
        },
        dpo: {
            type: Number,
            required: true,
        },
        top_10: {
            type: Object,
            required: true,
        },
    });
console.log(top_10);

const { options: branchesOptions } = useSelectOptions(branches);
const { options: timePeriodOptions } = useSelectOptions(timePeriods);
onMounted(() => {
    chartData.value = setChartData();
    chartOptions.value = setChartOptions();

    chartDataDoughnut.value = setChartDataDoughnut();
    chartOptionsDoughnut.value = setChartOptionsDoughnut();

    chartDataDoughnutAccountPayable.value =
        setChartDataDoughnutAccountPayable();
    chartOptionsDoughnutAccountPayable.value =
        setChartOptionsDoughnutAccountPayable();

    chartDataHorizontal.value = setChartDataHorizontal();
    chartOptionsHorizontal.value = setChartOptionsHorizontal();

    chartDataLine.value = setChartDataLine();
    chartOptionsLine.value = setChartOptionsLine();

    chartDataStacked.value = setChartDataStacked();
    chartOptionsStacked.value = setChartOptionsStacked();
});

const chartData = ref();
const chartOptions = ref();

const setChartData = () => {
    const documentStyle = getComputedStyle(document.documentElement);

    return {
        labels: [
            "January",
            "February",
            "March",
            "April",
            "May",
            "June",
            "July",
            "August",
            "September",
            "October",
            "November",
            "December",
        ],
        datasets: [
            {
                type: "line",
                label: "Dataset 1",
                borderColor: documentStyle.getPropertyValue("--p-orange-500"),
                borderWidth: 2,
                fill: false,
                tension: 0.4,
                data: [50, 25, 12, 48, 56, 76, 42, 42, 42, 42, 42, 42],
            },
            {
                type: "bar",
                label: "Dataset 2",
                backgroundColor: documentStyle.getPropertyValue("--p-gray-500"),
                data: [21, 84, 24, 75, 37, 65, 34, 42, 42, 42, 42, 42],
                borderColor: "white",
                borderWidth: 2,
            },
            {
                type: "bar",
                label: "Dataset 3",
                backgroundColor: documentStyle.getPropertyValue("--p-cyan-500"),
                data: [41, 52, 24, 74, 23, 21, 32, 42, 42, 42, 42, 42],
            },
        ],
    };
};
const setChartOptions = () => {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue("--p-text-color");
    const textColorSecondary = documentStyle.getPropertyValue(
        "--p-text-muted-color"
    );
    const surfaceBorder = documentStyle.getPropertyValue(
        "--p-content-border-color"
    );

    return {
        maintainAspectRatio: false,
        aspectRatio: 0.6,
        plugins: {
            legend: {
                labels: {
                    color: textColor,
                },
            },
        },
        scales: {
            x: {
                ticks: {
                    color: textColorSecondary,
                },
                grid: {
                    color: surfaceBorder,
                },
            },
            y: {
                ticks: {
                    color: textColorSecondary,
                },
                grid: {
                    color: surfaceBorder,
                },
            },
        },
    };
};

// Doughnut
const chartDataDoughnut = ref();
const chartOptionsDoughnut = ref(null);

const setChartDataDoughnut = () => {
    const documentStyle = getComputedStyle(document.body);

    return {
        labels: [`Days Inventory Outstanding (${dio.toFixed(0)})`],
        datasets: [
            {
                data: [dio?.toFixed(0)],
                backgroundColor: [
                    documentStyle.getPropertyValue("--p-cyan-500"),
                    documentStyle.getPropertyValue("--p-orange-500"),
                    documentStyle.getPropertyValue("--p-gray-500"),
                ],
                hoverBackgroundColor: [
                    documentStyle.getPropertyValue("--p-cyan-400"),
                    documentStyle.getPropertyValue("--p-orange-400"),
                    documentStyle.getPropertyValue("--p-gray-400"),
                ],
            },
        ],
    };
};

const setChartOptionsDoughnut = () => {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue("--p-text-color");

    return {
        plugins: {
            legend: {
                labels: {
                    cutout: "60%",
                    color: textColor,
                },
            },
        },
    };
};

const chartDataDoughnutAccountPayable = ref();
const chartOptionsDoughnutAccountPayable = ref(null);

const setChartDataDoughnutAccountPayable = () => {
    const documentStyle = getComputedStyle(document.body);

    return {
        labels: [`Days Payable Outstanding (${dpo.toFixed(0)})`],
        datasets: [
            {
                data: [dpo.toFixed(0)],
                backgroundColor: [
                    documentStyle.getPropertyValue("--p-cyan-500"),
                    documentStyle.getPropertyValue("--p-orange-500"),
                    documentStyle.getPropertyValue("--p-gray-500"),
                ],
                hoverBackgroundColor: [
                    documentStyle.getPropertyValue("--p-cyan-400"),
                    documentStyle.getPropertyValue("--p-orange-400"),
                    documentStyle.getPropertyValue("--p-gray-400"),
                ],
            },
        ],
    };
};

const setChartOptionsDoughnutAccountPayable = () => {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue("--p-text-color");

    return {
        plugins: {
            legend: {
                labels: {
                    cutout: "60%",
                    color: textColor,
                },
            },
        },
    };
};

// Horizontal

const chartDataHorizontal = ref();
const chartOptionsHorizontal = ref();

const setChartDataHorizontal = () => {
    const documentStyle = getComputedStyle(document.documentElement);

    return {
        labels: top_10.map((item) => item.name),
        datasets: [
            {
                label: "Top 10 Inventory Value by Item",
                backgroundColor: documentStyle.getPropertyValue("--p-cyan-500"),
                borderColor: documentStyle.getPropertyValue("--p-cyan-500"),
                data: top_10.map((item) => item.total_cost),
            },
        ],
    };
};

const setChartOptionsHorizontal = () => {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue("--p-text-color");
    const textColorSecondary = documentStyle.getPropertyValue(
        "--p-text-muted-color"
    );
    const surfaceBorder = documentStyle.getPropertyValue(
        "--p-content-border-color"
    );

    return {
        indexAxis: "y",
        maintainAspectRatio: false,
        aspectRatio: 0.8,
        plugins: {
            legend: {
                labels: {
                    color: textColor,
                },
            },
        },
        scales: {
            x: {
                ticks: {
                    color: textColorSecondary,
                    font: {
                        weight: 500,
                    },
                },
                grid: {
                    display: false,
                    drawBorder: false,
                },
            },
            y: {
                ticks: {
                    color: textColorSecondary,
                },
                grid: {
                    color: surfaceBorder,
                    drawBorder: false,
                },
            },
        },
    };
};

// Line
const chartDataLine = ref();
const chartOptionsLine = ref();

const setChartDataLine = () => {
    const documentStyle = getComputedStyle(document.documentElement);

    return {
        labels: [
            "January",
            "February",
            "March",
            "April",
            "May",
            "June",
            "July",
        ],
        datasets: [
            {
                label: "First Dataset",
                data: [65, 59, 80, 81, 56, 55, 40],
                fill: false,
                borderColor: documentStyle.getPropertyValue("--p-cyan-500"),
                tension: 0.4,
            },
            {
                label: "Second Dataset",
                data: [28, 48, 40, 19, 86, 27, 90],
                fill: false,
                borderColor: documentStyle.getPropertyValue("--p-gray-500"),
                tension: 0.4,
            },
        ],
    };
};
const setChartOptionsLine = () => {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue("--p-text-color");
    const textColorSecondary = documentStyle.getPropertyValue(
        "--p-text-muted-color"
    );
    const surfaceBorder = documentStyle.getPropertyValue(
        "--p-content-border-color"
    );

    return {
        maintainAspectRatio: false,
        aspectRatio: 0.6,
        plugins: {
            legend: {
                labels: {
                    color: textColor,
                },
            },
        },
        scales: {
            x: {
                ticks: {
                    color: textColorSecondary,
                },
                grid: {
                    color: surfaceBorder,
                },
            },
            y: {
                ticks: {
                    color: textColorSecondary,
                },
                grid: {
                    color: surfaceBorder,
                },
            },
        },
    };
};

// Stacked

const chartDataStacked = ref();
const chartOptionsStacked = ref();

const setChartDataStacked = () => {
    const documentStyle = getComputedStyle(document.documentElement);

    return {
        labels: [
            "January",
            "February",
            "March",
            "April",
            "May",
            "June",
            "July",
        ],
        datasets: [
            {
                type: "bar",
                label: "Dataset 1",
                backgroundColor: documentStyle.getPropertyValue("--p-cyan-500"),
                data: [50, 25, 12, 48, 90, 76, 42],
            },
            {
                type: "bar",
                label: "Dataset 2",
                backgroundColor: documentStyle.getPropertyValue("--p-gray-500"),
                data: [21, 84, 24, 75, 37, 65, 34],
            },
            {
                type: "bar",
                label: "Dataset 3",
                backgroundColor:
                    documentStyle.getPropertyValue("--p-orange-500"),
                data: [41, 52, 24, 74, 23, 21, 32],
            },
        ],
    };
};
const setChartOptionsStacked = () => {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue("--p-text-color");
    const textColorSecondary = documentStyle.getPropertyValue(
        "--p-text-muted-color"
    );
    const surfaceBorder = documentStyle.getPropertyValue(
        "--p-content-border-color"
    );

    return {
        maintainAspectRatio: false,
        aspectRatio: 0.8,
        plugins: {
            tooltips: {
                mode: "index",
                intersect: false,
            },
            legend: {
                labels: {
                    color: textColor,
                },
            },
        },
        scales: {
            x: {
                stacked: true,
                ticks: {
                    color: textColorSecondary,
                },
                grid: {
                    color: surfaceBorder,
                },
            },
            y: {
                stacked: true,
                ticks: {
                    color: textColorSecondary,
                },
                grid: {
                    color: surfaceBorder,
                },
            },
        },
    };
};

import { Check, ClockArrowUp, BookX } from "lucide-vue-next";

const branch = ref(filters.branch || branchesOptions.value[0].value);
const time_period = ref(
    filters.time_period || timePeriodOptions.value[0].value
);
watch(branch, (value) => {
    router.get(route("dashboard"), {
        branch: value,
        time_period: time_period.value,
    });
});
watch(time_period, (value) => {
    router.get(route("dashboard"), {
        branch: branch.value,
        time_period: value,
    });
});
</script>
<template>
    <Layout heading="Dashboard">
        <DivFlexCenter class="gap-3">
            <InputContainer>
                <Select
                    v-model="branch"
                    filter
                    placeholder="Select a branch"
                    :options="branchesOptions"
                    optionLabel="label"
                    optionValue="value"
                ></Select>
            </InputContainer>
            <InputContainer>
                <Select
                    v-model="time_period"
                    filter
                    placeholder="Time Periods"
                    :options="timePeriodOptions"
                    optionLabel="label"
                    optionValue="value"
                ></Select>
            </InputContainer>
            <!-- <DatePicker showIcon /> -->
        </DivFlexCenter>
        <section class="flex flex-col gap-5">
            <div class="grid gap-5 sm:grid-cols-5">
                <StatisticOverview
                    :isLink="true"
                    :href="
                        route('sales-report.index', {
                            time_period: time_period,
                            branchId: branch,
                        })
                    "
                    heading="SALES"
                    :value="sales"
                    :icon="Check"
                />
                <StatisticOverview
                    :isLink="true"
                    :href="
                        route('inventories-report.index', {
                            time_period: time_period,
                            branchId: branch,
                        })
                    "
                    heading="INVENTORIES"
                    :value="inventories"
                    :icon="ClockArrowUp"
                />
                <StatisticOverview
                    :isLink="true"
                    :href="
                        route('upcoming-inventories.index', {
                            time_period: time_period,
                            branchId: branch,
                        })
                    "
                    heading="UPCOMING INVENTORIES"
                    :value="upcomingInventories"
                    :icon="BookX"
                />
                <StatisticOverview
                    :isLink="true"
                    :href="
                        route('account-payable.index', {
                            time_period: time_period,
                            branchId: branch,
                        })
                    "
                    heading="ACCOUNT PAYABLE"
                    :value="accountPayable"
                    :icon="BookX"
                />
                <StatisticOverview
                    heading="COGS"
                    :value="cogs"
                    :icon="BookX"
                    :isLink="true"
                    :href="
                        route('cost-of-goods.index', {
                            time_period: time_period,
                            branchId: branch,
                        })
                    "
                />
            </div>
            <div class="sm:grid sm:grid-cols-3 sm:grid-rows-3 gap-4">
                <!-- Full width chart -->
                <Chart
                    type="bar"
                    :data="chartData"
                    :options="chartOptions"
                    class="h-[30rem] col-span-3"
                />

                <!-- First row after full width -->
                <Chart
                    type="doughnut"
                    :data="chartDataDoughnut"
                    :options="chartOptionsDoughnut"
                    class="h-[30rem]"
                />

                <Chart
                    type="bar"
                    :data="chartDataHorizontal"
                    :options="chartOptionsHorizontal"
                    class="row-span-2"
                />

                <Chart
                    type="doughnut"
                    :data="chartDataDoughnutAccountPayable"
                    :options="chartOptionsDoughnutAccountPayable"
                    class="h-[30rem]"
                />

                <!-- Last row -->
                <Chart
                    type="line"
                    :data="chartDataLine"
                    :options="chartOptionsLine"
                    class="h-[30rem]"
                />

                <Chart
                    type="bar"
                    :data="chartDataStacked"
                    :options="chartOptionsStacked"
                    class="h-[30rem]"
                />
            </div>
        </section>
    </Layout>
</template>
