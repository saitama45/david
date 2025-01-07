<script setup>
import StatisticOverview from "../../Components/dashboard/StatisticOverview.vue";
import Chart from "primevue/chart";
import { router } from "@inertiajs/vue3";

const props = defineProps({
    orderCounts: {
        type: Object,
        required: true,
    },
});
onMounted(() => {
    chartData.value = setChartData();
    chartOptions.value = setChartOptions();
});

const chartData = ref();
const chartOptions = ref();

const setChartData = () => {
    const documentStyle = getComputedStyle(document.body);

    return {
        labels: ["Apple Chie", "Almond Crunch", "Ice Cream", "Knorr"],
        datasets: [
            {
                data: [540, 325, 702, 200],
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
    };
};

import { Check, ClockArrowUp, BookX } from "lucide-vue-next";

defineOptions({
    layout: {
        props: {
            heading: "My Page Title",
            hasButton: true,
            buttonName: "Add New",
            handleClick: () => {
                console.log("Button clicked!");
            },
        },
    },
});
</script>
<template>
    <Layout heading="Dashboard">
        <section class="flex flex-col gap-5">
            <div class="grid gap-5 grid-cols-3">
                <StatisticOverview
                    heading="Approved Orders"
                    :value="orderCounts.approved_count"
                    :icon="Check"
                />
                <StatisticOverview
                    heading="Pending Orders"
                    :value="orderCounts.pending_count"
                    :icon="ClockArrowUp"
                />
                <StatisticOverview
                    heading="Rejected Orders"
                    :value="orderCounts.rejected_count"
                    :icon="BookX"
                />
            </div>
            <div class="grid grid-cols-3">
                <Chart
                    type="pie"
                    :data="chartData"
                    :options="chartOptions"
                    class="w-full"
                />
                <Chart
                    type="bar"
                    :data="chartData"
                    :options="chartOptions"
                    class="col-span-2"
                />
            </div>
        </section>
    </Layout>
</template>
