<script setup>
import StatisticOverview from "../../Components/dashboard/StatisticOverview.vue";
import Chart from "primevue/chart";

onMounted(() => {
    chartData.value = setChartData();
    chartOptions.value = setChartOptions();
});

const chartData = ref();
const chartOptions = ref();

const setChartData = () => {
    const documentStyle = getComputedStyle(document.body);

    return {
        labels: ["Apple Chie", "Almond Crunch", "Ice Cream"],
        datasets: [
            {
                data: [540, 325, 702],
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
</script>
<template>
    <Layout heading="Dashboard">
        <section class="flex flex-col gap-5">
            <div class="grid gap-4 md:grid-cols-2 md:gap-8 lg:grid-cols-4">
                <StatisticOverview
                    heading="Total Revenue"
                    value="$45,231.89"
                    subheading="+20.1% from last month"
                />
                <StatisticOverview
                    heading="Total Revenue"
                    value="$45,231.89"
                    subheading="+20.1% from last month"
                />
                <StatisticOverview
                    heading="Total Revenue"
                    value="$45,231.89"
                    subheading="+20.1% from last month"
                />
                <StatisticOverview
                    heading="Total Revenue"
                    value="$45,231.89"
                    subheading="+20.1% from last month"
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
