<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import StatisticOverview from "../../components/dashboard/StatisticOverview.vue";
import Chart from "primevue/chart";
import MultiSelect from "primevue/multiselect";
import { router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/composables/useSelectOptions";
import Knob from "primevue/knob";
import { Chart as ChartJS } from "chart.js";
import { Check, ClockArrowUp, BookX, Target, TrendingUp, Users, Receipt } from "lucide-vue-next";

const props = defineProps({
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
        achievement: {
            type: String,
            required: false,
            default: '0%'
        },
        growth: {
            type: String,
            required: false,
            default: '0%'
        },
        transactionCount: {
            type: String,
            required: false,
            default: '0'
        },
        atv: {
            type: String,
            required: false,
            default: '0.00'
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
        salesChartData: {
            type: Object,
            required: false,
            default: () => ({ labels: [], datasets: [] })
        },
        wastageChartData: {
            type: Object,
            required: false,
            default: () => ({ labels: [], datasets: [] })
        },
    });

const { options: branchesOptions } = useSelectOptions(props.branches);
const { options: timePeriodOptions } = useSelectOptions(props.timePeriods);

const chart_time_period = ref(parseInt(props.filters.chart_time_period ?? 0));
console.log(chart_time_period);
const chartsOption = [
    {
        value: 0,
        label: "YTD",
    },
    {
        value: 1,
        label: "Monthly",
    },
];

const inventoryOptions = [
    {
        value: "quantity",
        label: "Quantity",
    },
    {
        value: "cost",
        label: "Cost",
    },
];

onMounted(() => {
    registerDoughnutLabelPlugin();
    registerTopLabelsPlugin();

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

const salesCharts = computed(() => {
    if (!props.salesChartData?.labels || props.salesChartData.labels.length === 0) return [];
    
    const labels = props.salesChartData.labels;
    const datasets = props.salesChartData.datasets;
    const chunkSize = 20; // Increased to 20 stores per row for large screens
    const charts = [];
    
    // Find global max for Y-axis consistency across all rows
    let maxVal = 6;
    datasets.forEach(ds => {
        if (ds.data && Array.isArray(ds.data)) {
            ds.data.forEach(v => { 
                const num = parseFloat(v);
                if (!isNaN(num) && num > maxVal) maxVal = num; 
            });
        }
    });
    const maxY = Math.ceil(maxVal / 0.5) * 0.5;

    for (let i = 0; i < labels.length; i += chunkSize) {
        const chunkLabels = labels.slice(i, i + chunkSize);
        const chunkData = {
            labels: chunkLabels,
            datasets: datasets.map(ds => ({
                ...ds,
                data: ds.data.slice(i, i + chunkSize),
                type: "bar",
                borderWidth: 0
            }))
        };
        charts.push({
            data: chunkData,
            options: setChartOptionsWithMax(maxY)
        });
    }
    return charts;
});

const wastageCharts = computed(() => {
    if (!props.wastageChartData?.labels || props.wastageChartData.labels.length === 0) return [];
    
    const labels = props.wastageChartData.labels;
    const datasets = props.wastageChartData.datasets;
    const chunkSize = 20; 
    const charts = [];
    
    // Find global max for consistency across all rows
    let maxAmount = 1000;
    let maxQty = 100;
    
    datasets.forEach(ds => {
        if (ds.data && Array.isArray(ds.data)) {
            ds.data.forEach(v => { 
                const num = parseFloat(v);
                if (!isNaN(num)) {
                    if (ds.label === 'Wastage Amount' && num > maxAmount) maxAmount = num;
                    if (ds.label === 'Wastage Quantity' && num > maxQty) maxQty = num;
                }
            });
        }
    });

    const maxAmountY = Math.ceil(maxAmount / 100) * 100 + 100;
    const maxQtyY = Math.ceil(maxQty / 10) * 10 + 10;

    for (let i = 0; i < labels.length; i += chunkSize) {
        const chunkLabels = labels.slice(i, i + chunkSize);
        const chunkData = {
            labels: chunkLabels,
            datasets: datasets.map(ds => ({
                ...ds,
                data: ds.data.slice(i, i + chunkSize),
            }))
        };
        charts.push({
            data: chunkData,
            options: setWastageChartOptions(maxAmountY, maxQtyY)
        });
    }
    return charts;
});

const setWastageChartOptions = (maxAmountY, maxQtyY) => {
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
            tooltip: {
                mode: 'index',
                intersect: false
            }
        },
        scales: {
            x: {
                ticks: {
                    autoSkip: false,
                    maxRotation: 45,
                    minRotation: 45,
                    color: textColorSecondary,
                    font: {
                        size: 11
                    }
                },
                grid: {
                    display: false,
                },
            },
            y: { // Quantity
                type: 'linear',
                display: true,
                position: 'left',
                min: 0,
                max: maxQtyY,
                ticks: {
                    color: textColorSecondary,
                },
                grid: {
                    color: surfaceBorder,
                },
                title: {
                    display: true,
                    text: 'Quantity',
                    color: textColorSecondary,
                }
            },
            y1: { // Amount
                type: 'linear',
                display: true,
                position: 'right',
                min: 0,
                max: maxAmountY,
                ticks: {
                    color: textColorSecondary,
                },
                grid: {
                    drawOnChartArea: false, // only want the grid lines for one axis
                },
                title: {
                    display: true,
                    text: 'Amount (Php)',
                    color: textColorSecondary,
                }
            },
        },
    };
};

const setChartOptionsWithMax = (maxY) => {
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
        layout: {
            padding: {
                bottom: 20 // Add padding to prevent label clipping
            }
        },
        plugins: {
            legend: {
                labels: {
                    color: textColor,
                },
            },
            topLabels: {
                enabled: true,
                isSales: true
            }
        },
        scales: {
            x: {
                ticks: {
                    autoSkip: false,
                    maxRotation: 45,
                    minRotation: 45, // Enforce rotation to fit more labels
                    color: textColorSecondary,
                    font: {
                        size: 11 // Slightly smaller font to fit more stores
                    }
                },
                grid: {
                    display: false, // Cleaner look for many bars
                },
            },
            y: {
                min: 0,
                max: maxY,
                ticks: {
                    stepSize: 0.5,
                    color: textColorSecondary,
                },
                grid: {
                    color: surfaceBorder,
                },
                title: {
                    display: true,
                    text: 'Php Sales (in M)',
                    color: textColorSecondary,
                }
            },
        },
    };
};

const chartData = ref();
const chartOptions = ref();

const setChartData = () => {
    const documentStyle = getComputedStyle(document.documentElement);

    if (props.salesChartData && props.salesChartData.labels) {
        return {
            labels: props.salesChartData.labels,
            datasets: props.salesChartData.datasets.map(dataset => ({
                ...dataset,
                type: "bar",
                borderWidth: 0,
            }))
        };
    }

    return {
        labels: [],
        datasets: []
    };
};
const setChartOptions = () => {
    // This method is now secondary to setChartOptionsWithMax but kept for backward compatibility if needed
    return setChartOptionsWithMax(6);
};

// Doughnut
const chartDataDoughnut = ref();
const chartOptionsDoughnut = ref(null);

const setChartDataDoughnut = () => {
    const documentStyle = getComputedStyle(document.body);

    return {
        labels: [`Days Inventory Outstanding (${props.dio.toFixed(0)})`],
        datasets: [
            {
                data: [props.dio?.toFixed(0)],
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
        cutout: "50%",
        plugins: {
            legend: {
                labels: {
                    color: textColor,
                },
            },
            tooltip: {
                callbacks: {
                    label: function (context) {
                        return `${context.dataset.label}: ${context.raw}`;
                    },
                },
            },
            doughnutlabel: {
                labels: [
                    {
                        text: props.dio.toFixed(0),
                        font: {
                            size: "30px",
                            weight: "bold",
                        },
                        color: textColor,
                    },
                ],
            },
        },
    };
};

const chartDataDoughnutAccountPayable = ref();
const chartOptionsDoughnutAccountPayable = ref(null);

const setChartDataDoughnutAccountPayable = () => {
    const documentStyle = getComputedStyle(document.body);

    return {
        labels: [`Days Payable Outstanding (${props.dpo.toFixed(0)})`],
        datasets: [
            {
                data: [props.dpo.toFixed(0)],
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
        cutout: "50%", // Increase cutout to make room for text
        plugins: {
            legend: {
                labels: {
                    color: textColor,
                },
            },
            tooltip: {
                callbacks: {
                    label: function (context) {
                        return `${context.dataset.label}: ${context.raw}`;
                    },
                },
            },
            // Add the center text plugin
            doughnutlabel: {
                labels: [
                    {
                        text: props.dpo.toFixed(0),
                        font: {
                            size: "30px",
                            weight: "bold",
                        },
                        color: textColor,
                    },
                ],
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
        labels: props.top_10.map((item) => item.name),
        datasets: [
            {
                label: "Top 10 Inventory Value by Item",
                backgroundColor: documentStyle.getPropertyValue("--p-cyan-500"),
                borderColor: documentStyle.getPropertyValue("--p-cyan-500"),
                data: props.top_10.map((item) =>
                    inventory_type.value == "quantity"
                        ? item.quantity
                        : item.total_cost
                ),
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

// Add a watch to update chart data when salesChartData prop changes
watch(() => props.salesChartData, (newData) => {
    chartData.value = setChartData();
    chartOptions.value = setChartOptions();
}, { deep: true });

// Initialize branch as an array for MultiSelect. Support string or array from filters.
const initializeBranch = () => {
    const filterBranch = props.filters.branch;
    if (!filterBranch) {
        return [branchesOptions.value[0].value]; // Default to ['all']
    }
    if (Array.isArray(filterBranch)) {
        return filterBranch.map(v => isNaN(Number(v)) ? v : Number(v));
    }
    return [isNaN(Number(filterBranch)) ? filterBranch : Number(filterBranch)];
};

const branch = ref(initializeBranch());
const time_period = ref(
    props.filters.time_period ? parseInt(props.filters.time_period) : timePeriodOptions.value[0].value
);

const inventory_type = ref(props.filters.inventory_type ?? "quantity");

const handleSearch = () => {
    router.get(
        route("dashboard"),
        {
            branch: branch.value,
            time_period: time_period.value,
            chart_time_period: time_period.value == 0 ? 0 : 1,
            inventory_type: inventory_type.value,
        },
        {
            preserveScroll: true,
        }
    );
};

const goToDPO = () => {
    router.get(route("days-payable-outstanding.index"), {
        branchId: branch.value,
        chart_time_period: chart_time_period.value,
    });
};

const goToTop10 = () => {
    router.get(route("top-10-inventories.index"), {
        branchId: branch.value,
        inventory_type: inventory_type.value,
    });
};

const goToDIO = () => {
    router.get(route("days-inventory-outstanding.index"), {
        branchId: branch.value,
        chart_time_period: chart_time_period.value,
    });
};

const registerTopLabelsPlugin = () => {
    ChartJS.register({
        id: "topLabels",
        afterDatasetsDraw: function (chart) {
            const pluginOptions = chart.config.options.plugins?.topLabels;
            if (!pluginOptions?.enabled) return;
            
            const ctx = chart.ctx;
            chart.data.datasets.forEach((dataset, i) => {
                const meta = chart.getDatasetMeta(i);
                if (meta.hidden) return;

                meta.data.forEach((bar, index) => {
                    const data = dataset.data[index];
                    if (data === null || data === undefined) return;
                    
                    ctx.fillStyle = "#444";
                    ctx.textAlign = "center";
                    ctx.textBaseline = "bottom";
                    ctx.font = "bold 10px Arial";
                    
                    const padding = 5;
                    const position = bar.tooltipPosition();
                    
                    // Format the display value (add M for millions if it's a sales chart)
                    const displayValue = pluginOptions.isSales ? data.toString() : data.toString();
                    
                    ctx.fillText(displayValue, position.x, position.y - padding);
                });
            });
        },
    });
};

const registerDoughnutLabelPlugin = () => {
    ChartJS.register({
        id: "doughnutLabel",
        beforeDraw: function (chart) {
            if (chart.config.type === "doughnut") {
                // Get ctx from chart
                const ctx = chart.ctx;

                // Get options from the center object in options
                const centerConfig = chart.config.options.plugins.doughnutlabel;
                if (centerConfig) {
                    const fontStyle = centerConfig.labels[0].font || {};
                    const txt = centerConfig.labels[0].text;
                    const color = centerConfig.labels[0].color;
                    const sidePadding = 20;
                    const sidePaddingCalculated =
                        (sidePadding / 100) * (chart.innerRadius * 2);

                    ctx.font = `${fontStyle.weight || "bold"} ${
                        fontStyle.size || "30px"
                    } ${fontStyle.family || "Arial"}`;
                    ctx.textBaseline = "middle";
                    ctx.textAlign = "center";

                    const centerX =
                        (chart.chartArea.left + chart.chartArea.right) / 2;
                    const centerY =
                        (chart.chartArea.top + chart.chartArea.bottom) / 2;

                    // Draw text in center
                    ctx.fillStyle = color || "#000";
                    ctx.fillText(txt, centerX, centerY);
                }
            }
        },
    });
};
</script>
<template>
    <Layout heading="Dashboard">
        <DivFlexCenter class="gap-3">
            <InputContainer>
                <MultiSelect
                    v-model="branch"
                    filter
                    placeholder="Select branch(es)"
                    :options="branchesOptions"
                    optionLabel="label"
                    optionValue="value"
                ></MultiSelect>
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
            <Button @click="handleSearch">Search</Button>
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
                    :value="props.sales"
                    :icon="Check"
                />

                <!-- New KPI Boxes -->
                <StatisticOverview
                    heading="ACHIEVEMENT"
                    :value="props.achievement"
                    :icon="Target"
                />
                <StatisticOverview
                    heading="GROWTH (YoY)"
                    :value="props.growth"
                    :icon="TrendingUp"
                />
                <StatisticOverview
                    heading="TRANSACTIONS"
                    :value="props.transactionCount"
                    :icon="Users"
                />
                <StatisticOverview
                    heading="AVG TICKET"
                    :value="props.atv"
                    :icon="Receipt"
                />

                <!-- Hiding Inventories per request
                <StatisticOverview
                    :isLink="true"
                    :href="
                        route('inventories-report.index', {
                            time_period: time_period,
                            branchId: branch,
                        })
                    "
                    heading="INVENTORIES"
                    :value="props.inventories"
                    :icon="ClockArrowUp"
                />
                -->

                <!-- Hiding Upcoming Inventories per request
                <StatisticOverview
                    :isLink="true"
                    :href="
                        route('upcoming-inventories.index', {
                            time_period: time_period,
                            branchId: branch,
                        })
                    "
                    heading="UPCOMING INVENTORIES"
                    :value="props.upcomingInventories"
                    :icon="BookX"
                />
                -->

                <!-- Hiding Account Payable per request
                <StatisticOverview
                    :isLink="true"
                    :href="
                        route('account-payable.index', {
                            time_period: time_period,
                            branchId: branch,
                        })
                    "
                    heading="ACCOUNT PAYABLE"
                    :value="props.accountPayable"
                    :icon="BookX"
                />
                -->

                <!-- Hiding COGS per request
                <StatisticOverview
                    heading="COGS"
                    :value="props.cogs"
                    :icon="BookX"
                    :isLink="true"
                    :href="
                        route('cost-of-goods.index', {
                            time_period: time_period,
                            branchId: branch,
                        })
                    "
                />
                -->
            </div>

            <div class="sm:grid sm:grid-cols-3 gap-4">
                <!-- Full width charts (Multi-line support for stores) -->
                <template v-for="(chart, index) in wastageCharts" :key="'wastage-chart-' + index">
                    <Chart
                        type="bar"
                        :data="chart.data"
                        :options="chart.options"
                        class="h-[30rem] col-span-3 mb-4"
                    />
                </template>

                <template v-for="(chart, index) in salesCharts" :key="'sales-chart-' + index">
                    <Chart
                        type="bar"
                        :data="chart.data"
                        :options="chart.options"
                        class="h-[30rem] col-span-3 mb-4"
                    />
                </template>

                <!-- Temporarily hidden: lower dashboard details below the sales bar chart. -->
                <template v-if="false">
                    <!-- First row after full width -->
                    <!-- For DIO -->
                    <Chart
                        type="doughnut"
                        :data="chartDataDoughnut"
                        :options="chartOptionsDoughnut"
                        class="h-[30rem]"
                        @click="goToDIO"
                    />

                    <div class="flex flex-col row-span-2">
                        <Select
                            v-model="inventory_type"
                            placeholder="Inventory Type"
                            :options="inventoryOptions"
                            optionLabel="label"
                            optionValue="value"
                        ></Select>
                        <Chart
                            class="w-full h-full"
                            type="bar"
                            :data="chartDataHorizontal"
                            :options="chartOptionsHorizontal"
                            @click="goToTop10"
                        />
                    </div>
                    <!-- For DPO -->
                    <Chart
                        type="doughnut"
                        :data="chartDataDoughnutAccountPayable"
                        :options="chartOptionsDoughnutAccountPayable"
                        class="h-[30rem]"
                        @click="goToDPO"
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
                </template>
            </div>
        </section>
    </Layout>
</template>
