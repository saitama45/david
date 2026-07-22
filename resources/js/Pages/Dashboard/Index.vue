<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import StatisticOverview from "../../components/dashboard/StatisticOverview.vue";
import Chart from "primevue/chart";
import MultiSelect from "primevue/multiselect";
import { router } from "@inertiajs/vue3";
import { useSelectOptions } from "@/composables/useSelectOptions";
import { useAuth } from "@/composables/useAuth";
import Knob from "primevue/knob";
import { Chart as ChartJS } from "chart.js";
import axios from "axios";
import { Check, ClockArrowUp, BookX, Target, TrendingUp, Users, Receipt, BarChart3, RotateCcw, Search, ExternalLink } from "lucide-vue-next";

const { hasAccess } = useAuth();

const props = defineProps({
        branches: {
            type: Object,
            required: true,
        },
        adoptionStores: {
            type: Array,
            default: () => [],
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
const { options: adoptionStoreOptions } = useSelectOptions(props.adoptionStores);
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
    registerSalesMixPercentLabelsPlugin();
    registerAdoptionBarLabelsPlugin();

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
const canViewOverview = computed(() => hasAccess("view dashboard overview"));
const canViewSalesMix = computed(() => hasAccess("view sales mix"));
const canViewAdoption = computed(() => hasAccess("view adoption rate dashboard"));
const canViewAdoptionReport = computed(() => hasAccess("view adoption rate tracking report"));

const activeDashboardTab = ref(
    hasAccess("view dashboard overview") ? "overview" :
    hasAccess("view sales mix") ? "sales-mix" :
    hasAccess("view adoption rate dashboard") ? "adoption-rate" :
    null
);
const activeSalesMixTab = ref("subcategories");

const formatDate = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
};

const today = new Date();
const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);

const salesMixBranch = ref([branchesOptions.value[0]?.value ?? "all"]);
const salesMixDateFrom = ref(formatDate(monthStart));
const salesMixDateTo = ref(formatDate(today));
const salesMixLoaded = ref(false);
const salesMixLoading = ref(false);
const salesMixError = ref("");
const showAllSalesMixRows = ref(false);
const salesMixRows = ref([]);
const salesMixMeta = ref({
    total_revenue: 0,
    date_from: salesMixDateFrom.value,
    date_to: salesMixDateTo.value,
    store_count: 0,
});
const productRevenueLoaded = ref(false);
const productRevenueLoading = ref(false);
const productRevenueError = ref("");
const showAllProductRevenueRows = ref(false);
const productRevenueOverallRows = ref([]);
const productRevenueByCategory = ref({
    Kitchen: [],
    "Beverages & Others": [],
    Bakery: [],
});
const productRevenueMeta = ref({
    total_revenue: 0,
    date_from: salesMixDateFrom.value,
    date_to: salesMixDateTo.value,
    store_count: 0,
});
const productRevenueCategories = ["Kitchen", "Beverages & Others", "Bakery"];
const productQuantityLoaded = ref(false);
const productQuantityLoading = ref(false);
const productQuantityError = ref("");
const showAllProductQuantityRows = ref(false);
const productQuantityOverallRows = ref([]);
const productQuantityByCategory = ref({
    Kitchen: [],
    "Beverages & Others": [],
    Bakery: [],
});
const productQuantityMeta = ref({
    total_quantity: 0,
    date_from: salesMixDateFrom.value,
    date_to: salesMixDateTo.value,
    store_count: 0,
});

// --- Adoption Rate tab state ---
const adoptionBranch = ref([adoptionStoreOptions.value[0]?.value ?? "all"]);
const adoptionDateFrom = ref(formatDate(monthStart));
const adoptionDateTo = ref(formatDate(today));
const adoptionViewMode = ref("combined"); // 'combined' | 'per_store'
const adoptionChartType = ref("line"); // 'line' | 'bar'
const adoptionLoaded = ref(false);
const adoptionLoading = ref(false);
const adoptionError = ref("");
const adoptionWeeks = ref([]);
const adoptionCombined = ref([]);
const adoptionPerStore = ref([]);
const adoptionMeta = ref({
    date_from: adoptionDateFrom.value,
    date_to: adoptionDateTo.value,
    store_ids: [],
    store_count: 0,
    overall_rate: null,
    indicator_rates: [],
});
const adoptionMatchingReportUrl = computed(() => route("reports.adoption-rate-tracking.index", {
    tab: "overall_adoption_rate",
    date_from: adoptionMeta.value.date_from,
    date_to: adoptionMeta.value.date_to,
    store_ids: adoptionMeta.value.store_ids || [],
    ordering_templates: [],
    search: "",
}));

const adoptionPalette = [
    "#06b6d4", "#f97316", "#8b5cf6", "#10b981", "#ef4444",
    "#3b82f6", "#eab308", "#ec4899", "#14b8a6", "#64748b",
];

const adoptionHasData = computed(() => {
    const series = adoptionViewMode.value === "combined" ? adoptionCombined.value : adoptionPerStore.value;

    return series.length > 0 && (adoptionViewMode.value === "combined" || adoptionWeeks.value.length > 0);
});

const adoptionChartLabels = computed(() => {
    if (adoptionViewMode.value !== "combined") {
        return adoptionWeeks.value.map((week) => week.label);
    }

    if (adoptionWeeks.value.length >= 2) {
        return adoptionWeeks.value.map((week) => week.label);
    }

    return [adoptionMeta.value.date_from, adoptionMeta.value.date_to];
});

const adoptionChartData = computed(() => {
    const series = adoptionViewMode.value === "combined" ? adoptionCombined.value : adoptionPerStore.value;

    return {
        labels: adoptionChartLabels.value,
        datasets: series.map((serie, index) => {
            const isOverall = adoptionViewMode.value === "combined" && serie.label === "Overall";
            const color = isOverall ? "#0f172a" : adoptionPalette[index % adoptionPalette.length];

            return {
                label: serie.label,
                data: serie.data,
                borderColor: color,
                backgroundColor: color,
                borderWidth: isOverall ? 3 : 2,
                tension: 0.35,
                spanGaps: true,
                pointRadius: 3,
                pointHoverRadius: 5,
                fill: false,
            };
        }),
    };
});

const adoptionChartOptions = computed(() => {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue("--p-text-color");
    const textColorSecondary = documentStyle.getPropertyValue("--p-text-muted-color");
    const surfaceBorder = documentStyle.getPropertyValue("--p-content-border-color");

    const isBar = adoptionChartType.value === "bar";

    return {
        maintainAspectRatio: false,
        interaction: { mode: "index", intersect: false },
        layout: { padding: { top: isBar ? 24 : 0 } },
        plugins: {
            legend: { labels: { color: textColor } },
            adoptionBarLabels: { enabled: isBar },
            tooltip: {
                callbacks: {
                    label: (context) => {
                        const value = context.parsed.y;
                        const display = value === null || value === undefined ? "N/A" : `${Number(value).toFixed(2)}%`;

                        return `${context.dataset.label}: ${display}`;
                    },
                },
            },
        },
        scales: {
            x: {
                ticks: { color: textColorSecondary },
                grid: { color: surfaceBorder },
            },
            y: {
                min: 0,
                max: 100,
                ticks: {
                    color: textColorSecondary,
                    callback: (value) => `${value}%`,
                },
                grid: { color: surfaceBorder },
                title: {
                    display: true,
                    text: "Adoption Rate (%)",
                    color: textColorSecondary,
                },
            },
        },
    };
});

const visibleSalesMixRows = computed(() => {
    return showAllSalesMixRows.value ? salesMixRows.value : salesMixRows.value.slice(0, 15);
});

const visibleProductRevenueOverallRows = computed(() => {
    return showAllProductRevenueRows.value
        ? productRevenueOverallRows.value
        : productRevenueOverallRows.value.filter((row) => Number(row.rank) <= 15);
});

const visibleProductRevenueRowsForCategory = (category) => {
    const rows = productRevenueByCategory.value[category] || [];

    return showAllProductRevenueRows.value ? rows : rows.filter((row) => Number(row.rank) <= 15);
};

const hasProductRevenueOverflow = computed(() => {
    return productRevenueOverallRows.value.some((row) => Number(row.rank) > 15)
        || productRevenueCategories.some((category) => (productRevenueByCategory.value[category] || []).some((row) => Number(row.rank) > 15));
});

const visibleProductQuantityOverallRows = computed(() => {
    return showAllProductQuantityRows.value
        ? productQuantityOverallRows.value
        : productQuantityOverallRows.value.filter((row) => Number(row.rank) <= 15);
});

const visibleProductQuantityRowsForCategory = (category) => {
    const rows = productQuantityByCategory.value[category] || [];

    return showAllProductQuantityRows.value ? rows : rows.filter((row) => Number(row.rank) <= 15);
};

const hasProductQuantityOverflow = computed(() => {
    return productQuantityOverallRows.value.some((row) => Number(row.rank) > 15)
        || productRevenueCategories.some((category) => (productQuantityByCategory.value[category] || []).some((row) => Number(row.rank) > 15));
});

const salesMixAnyLoading = computed(() => salesMixLoading.value || productRevenueLoading.value || productQuantityLoading.value);

const formatCurrency = (value) => {
    return new Intl.NumberFormat("en-PH", {
        style: "currency",
        currency: "PHP",
        maximumFractionDigits: 2,
    }).format(Number(value || 0));
};

const formatPercent = (value) => {
    return `${Number(value || 0).toFixed(2)}%`;
};

const formatQuantity = (value) => {
    return new Intl.NumberFormat("en-PH", {
        maximumFractionDigits: 2,
    }).format(Number(value || 0));
};

const salesMixChartData = computed(() => {
    const documentStyle = getComputedStyle(document.documentElement);
    const rows = visibleSalesMixRows.value;

    return {
        labels: rows.map((row) => `#${row.rank} ${row.sub_category}`),
        datasets: [
            {
                label: "Revenue",
                backgroundColor: documentStyle.getPropertyValue("--p-cyan-500") || "#06b6d4",
                borderColor: documentStyle.getPropertyValue("--p-cyan-600") || "#0891b2",
                data: rows.map((row) => row.revenue),
                revenuePercents: rows.map((row) => row.revenue_percent),
            },
        ],
    };
});

const salesMixChartOptions = computed(() => {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue("--p-text-color");
    const textColorSecondary = documentStyle.getPropertyValue("--p-text-muted-color");
    const surfaceBorder = documentStyle.getPropertyValue("--p-content-border-color");
    const maxRevenue = Math.max(0, ...visibleSalesMixRows.value.map((row) => Number(row.revenue || 0)));

    return {
        indexAxis: "y",
        maintainAspectRatio: false,
        layout: {
            padding: {
                right: 56,
            },
        },
        plugins: {
            legend: {
                display: false,
            },
            salesMixPercentLabels: {
                enabled: true,
            },
            tooltip: {
                callbacks: {
                    label: (context) => {
                        const row = visibleSalesMixRows.value[context.dataIndex];
                        if (!row) return "";

                        return [
                            `Revenue: ${formatCurrency(row.revenue)}`,
                            `Revenue %: ${formatPercent(row.revenue_percent)}`,
                        ];
                    },
                    title: (items) => {
                        const row = visibleSalesMixRows.value[items[0]?.dataIndex];
                        return row ? `#${row.rank} ${row.sub_category}` : "";
                    },
                },
            },
        },
        scales: {
            x: {
                beginAtZero: true,
                suggestedMax: maxRevenue > 0 ? maxRevenue * 1.18 : 1,
                ticks: {
                    color: textColorSecondary,
                    callback: (value) => formatCurrency(value),
                },
                title: {
                    display: true,
                    text: "Revenue (Php)",
                    color: textColorSecondary,
                },
                grid: {
                    color: surfaceBorder,
                },
            },
            y: {
                ticks: {
                    color: textColor,
                    autoSkip: false,
                    font: {
                        size: 11,
                    },
                },
                grid: {
                    display: false,
                },
            },
        },
    };
});

const salesMixChartHeight = computed(() => {
    return `${Math.max(360, visibleSalesMixRows.value.length * 34)}px`;
});

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

const loadSalesMixSubcategories = async () => {
    if (!canViewSalesMix.value) return;

    salesMixLoading.value = true;
    salesMixError.value = "";

    try {
        const response = await axios.get(route("dashboard.sales-mix.subcategories"), {
            params: {
                branch: salesMixBranch.value,
                date_from: salesMixDateFrom.value,
                date_to: salesMixDateTo.value,
            },
        });

        salesMixRows.value = response.data.data || [];
        salesMixMeta.value = response.data.meta || salesMixMeta.value;
        salesMixLoaded.value = true;
    } catch (error) {
        salesMixError.value = error.response?.data?.message || "Unable to load Sales Mix data.";
    } finally {
        salesMixLoading.value = false;
    }
};

const loadSalesMixProductsRevenue = async () => {
    if (!canViewSalesMix.value) return;

    productRevenueLoading.value = true;
    productRevenueError.value = "";

    try {
        const response = await axios.get(route("dashboard.sales-mix.products.revenue"), {
            params: {
                branch: salesMixBranch.value,
                date_from: salesMixDateFrom.value,
                date_to: salesMixDateTo.value,
            },
        });

        productRevenueOverallRows.value = response.data.overall || [];
        productRevenueByCategory.value = {
            Kitchen: response.data.by_category?.Kitchen || [],
            "Beverages & Others": response.data.by_category?.["Beverages & Others"] || [],
            Bakery: response.data.by_category?.Bakery || [],
        };
        productRevenueMeta.value = response.data.meta || productRevenueMeta.value;
        productRevenueLoaded.value = true;
    } catch (error) {
        productRevenueError.value = error.response?.data?.message || "Unable to load product revenue data.";
    } finally {
        productRevenueLoading.value = false;
    }
};

const loadSalesMixProductsQuantity = async () => {
    if (!canViewSalesMix.value) return;

    productQuantityLoading.value = true;
    productQuantityError.value = "";

    try {
        const response = await axios.get(route("dashboard.sales-mix.products.quantity"), {
            params: {
                branch: salesMixBranch.value,
                date_from: salesMixDateFrom.value,
                date_to: salesMixDateTo.value,
            },
        });

        productQuantityOverallRows.value = response.data.overall || [];
        productQuantityByCategory.value = {
            Kitchen: response.data.by_category?.Kitchen || [],
            "Beverages & Others": response.data.by_category?.["Beverages & Others"] || [],
            Bakery: response.data.by_category?.Bakery || [],
        };
        productQuantityMeta.value = response.data.meta || productQuantityMeta.value;
        productQuantityLoaded.value = true;
    } catch (error) {
        productQuantityError.value = error.response?.data?.message || "Unable to load product quantity data.";
    } finally {
        productQuantityLoading.value = false;
    }
};

const loadAdoptionRate = async () => {
    if (!canViewAdoption.value) return;

    adoptionLoading.value = true;
    adoptionError.value = "";

    try {
        const response = await axios.get(route("dashboard.adoption-rate"), {
            params: {
                branch: adoptionBranch.value,
                date_from: adoptionDateFrom.value,
                date_to: adoptionDateTo.value,
            },
        });

        adoptionWeeks.value = response.data.weeks || [];
        adoptionCombined.value = response.data.combined || [];
        adoptionPerStore.value = response.data.per_store || [];
        adoptionMeta.value = response.data.meta || adoptionMeta.value;
        adoptionLoaded.value = true;
    } catch (error) {
        adoptionError.value = error.response?.data?.message || "Unable to load Adoption Rate data.";
    } finally {
        adoptionLoading.value = false;
    }
};

const applyAdoptionFilters = () => {
    adoptionLoaded.value = false;
    loadAdoptionRate();
};

const resetAdoptionFilters = () => {
    adoptionBranch.value = [adoptionStoreOptions.value[0]?.value ?? "all"];
    adoptionDateFrom.value = formatDate(monthStart);
    adoptionDateTo.value = formatDate(today);
    adoptionViewMode.value = "combined";
    adoptionChartType.value = "line";
    adoptionLoaded.value = false;
    loadAdoptionRate();
};

const loadActiveSalesMixTab = () => {
    if (!canViewSalesMix.value) return;

    if (activeSalesMixTab.value === "subcategories") {
        loadSalesMixSubcategories();
    }

    if (activeSalesMixTab.value === "products-revenue") {
        loadSalesMixProductsRevenue();
    }

    if (activeSalesMixTab.value === "products-quantity") {
        loadSalesMixProductsQuantity();
    }
};

const selectDashboardTab = (tab) => {
    if (tab === "overview" && !canViewOverview.value) return;
    if (tab === "sales-mix" && !canViewSalesMix.value) return;
    if (tab === "adoption-rate" && !canViewAdoption.value) return;

    activeDashboardTab.value = tab;

    if (tab === "adoption-rate" && !adoptionLoaded.value) {
        loadAdoptionRate();
    }

    if (tab === "sales-mix" && activeSalesMixTab.value === "subcategories" && !salesMixLoaded.value) {
        loadSalesMixSubcategories();
    }

    if (tab === "sales-mix" && activeSalesMixTab.value === "products-revenue" && !productRevenueLoaded.value) {
        loadSalesMixProductsRevenue();
    }

    if (tab === "sales-mix" && activeSalesMixTab.value === "products-quantity" && !productQuantityLoaded.value) {
        loadSalesMixProductsQuantity();
    }
};

const selectSalesMixTab = (tab) => {
    if (!canViewSalesMix.value) return;

    activeSalesMixTab.value = tab;

    if (tab === "subcategories" && !salesMixLoaded.value) {
        loadSalesMixSubcategories();
    }

    if (tab === "products-revenue" && !productRevenueLoaded.value) {
        loadSalesMixProductsRevenue();
    }

    if (tab === "products-quantity" && !productQuantityLoaded.value) {
        loadSalesMixProductsQuantity();
    }
};

const applySalesMixFilters = () => {
    showAllSalesMixRows.value = false;
    showAllProductRevenueRows.value = false;
    showAllProductQuantityRows.value = false;
    salesMixLoaded.value = false;
    productRevenueLoaded.value = false;
    productQuantityLoaded.value = false;
    loadActiveSalesMixTab();
};

const resetSalesMixFilters = () => {
    salesMixBranch.value = [branchesOptions.value[0]?.value ?? "all"];
    salesMixDateFrom.value = formatDate(monthStart);
    salesMixDateTo.value = formatDate(today);
    showAllSalesMixRows.value = false;
    showAllProductRevenueRows.value = false;
    showAllProductQuantityRows.value = false;
    salesMixLoaded.value = false;
    productRevenueLoaded.value = false;
    productQuantityLoaded.value = false;
    loadActiveSalesMixTab();
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

const registerSalesMixPercentLabelsPlugin = () => {
    ChartJS.register({
        id: "salesMixPercentLabels",
        afterDatasetsDraw: function (chart) {
            const pluginOptions = chart.config.options.plugins?.salesMixPercentLabels;
            if (!pluginOptions?.enabled) return;

            const ctx = chart.ctx;
            const dataset = chart.data.datasets[0];
            const meta = chart.getDatasetMeta(0);
            if (!dataset || meta.hidden) return;

            ctx.save();
            ctx.fillStyle = "#374151";
            ctx.textAlign = "left";
            ctx.textBaseline = "middle";
            ctx.font = "600 11px Arial";

            meta.data.forEach((bar, index) => {
                const percent = dataset.revenuePercents?.[index];
                if (percent === null || percent === undefined) return;

                const position = bar.tooltipPosition();
                ctx.fillText(`${Number(percent).toFixed(2)}%`, position.x + 8, position.y);
            });

            ctx.restore();
        },
    });
};

const registerAdoptionBarLabelsPlugin = () => {
    ChartJS.register({
        id: "adoptionBarLabels",
        afterDatasetsDraw: function (chart) {
            const pluginOptions = chart.config.options.plugins?.adoptionBarLabels;
            if (!pluginOptions?.enabled) return;

            const ctx = chart.ctx;
            ctx.save();
            ctx.textAlign = "center";
            ctx.textBaseline = "bottom";
            ctx.font = "bold 10px Arial";

            chart.data.datasets.forEach((dataset, i) => {
                const meta = chart.getDatasetMeta(i);
                if (meta.hidden) return;

                meta.data.forEach((bar, index) => {
                    const value = dataset.data[index];
                    if (value === null || value === undefined) return;

                    ctx.fillStyle = dataset.backgroundColor || "#374151";
                    const position = bar.tooltipPosition();
                    ctx.fillText(`${Number(value).toFixed(2)}%`, position.x, position.y - 4);
                });
            });

            ctx.restore();
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
        <div class="mb-6 flex flex-wrap gap-2 border-b border-gray-200">
            <button
                v-if="canViewOverview"
                type="button"
                :class="[
                    'px-4 py-2 text-sm font-semibold transition-colors border-b-2',
                    activeDashboardTab === 'overview'
                        ? 'border-cyan-600 text-cyan-700'
                        : 'border-transparent text-gray-500 hover:text-gray-800'
                ]"
                @click="selectDashboardTab('overview')"
            >
                Overview
            </button>
            <button
                v-if="canViewSalesMix"
                type="button"
                :class="[
                    'px-4 py-2 text-sm font-semibold transition-colors border-b-2',
                    activeDashboardTab === 'sales-mix'
                        ? 'border-cyan-600 text-cyan-700'
                        : 'border-transparent text-gray-500 hover:text-gray-800'
                ]"
                @click="selectDashboardTab('sales-mix')"
            >
                Sales Mix
            </button>
            <button
                v-if="canViewAdoption"
                type="button"
                :class="[
                    'px-4 py-2 text-sm font-semibold transition-colors border-b-2',
                    activeDashboardTab === 'adoption-rate'
                        ? 'border-cyan-600 text-cyan-700'
                        : 'border-transparent text-gray-500 hover:text-gray-800'
                ]"
                @click="selectDashboardTab('adoption-rate')"
            >
                Adoption Rate
            </button>
        </div>

        <div v-if="activeDashboardTab === 'overview'">
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
        </div>

        <section v-else-if="activeDashboardTab === 'sales-mix'" class="flex flex-col gap-5">
            <div class="flex flex-wrap gap-2 border-b border-gray-200">
                <button
                    type="button"
                    :class="[
                        'px-4 py-2 text-sm font-semibold transition-colors border-b-2',
                        activeSalesMixTab === 'subcategories'
                            ? 'border-cyan-600 text-cyan-700'
                            : 'border-transparent text-gray-500 hover:text-gray-800'
                    ]"
                    @click="selectSalesMixTab('subcategories')"
                >
                    Top 15 SubCategories by Revenue
                </button>
                <button
                    type="button"
                    :class="[
                        'px-4 py-2 text-sm font-semibold transition-colors border-b-2',
                        activeSalesMixTab === 'products-revenue'
                            ? 'border-cyan-600 text-cyan-700'
                            : 'border-transparent text-gray-500 hover:text-gray-800'
                    ]"
                    @click="selectSalesMixTab('products-revenue')"
                >
                    Top 15 Products by Revenue
                </button>
                <button
                    type="button"
                    :class="[
                        'px-4 py-2 text-sm font-semibold transition-colors border-b-2',
                        activeSalesMixTab === 'products-quantity'
                            ? 'border-cyan-600 text-cyan-700'
                            : 'border-transparent text-gray-500 hover:text-gray-800'
                    ]"
                    @click="selectSalesMixTab('products-quantity')"
                >
                    Top 15 Products by Qty Sold
                </button>
            </div>

            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm lg:grid-cols-[minmax(16rem,1fr)_10rem_10rem_auto_auto]">
                <InputContainer>
                    <Label class="mb-1 block text-xs font-semibold uppercase text-gray-500">Stores</Label>
                    <MultiSelect
                        v-model="salesMixBranch"
                        filter
                        placeholder="All Stores"
                        :options="branchesOptions"
                        optionLabel="label"
                        optionValue="value"
                    ></MultiSelect>
                </InputContainer>
                <InputContainer>
                    <Label class="mb-1 block text-xs font-semibold uppercase text-gray-500">From</Label>
                    <Input v-model="salesMixDateFrom" type="date" />
                </InputContainer>
                <InputContainer>
                    <Label class="mb-1 block text-xs font-semibold uppercase text-gray-500">To</Label>
                    <Input v-model="salesMixDateTo" type="date" />
                </InputContainer>
                <div class="flex items-end">
                    <Button class="w-full gap-2" @click="applySalesMixFilters" :disabled="salesMixAnyLoading">
                        <Search class="h-4 w-4" />
                        Apply
                    </Button>
                </div>
                <div class="flex items-end">
                    <Button class="w-full gap-2" variant="outline" @click="resetSalesMixFilters" :disabled="salesMixAnyLoading">
                        <RotateCcw class="h-4 w-4" />
                        Reset
                    </Button>
                </div>
            </div>

            <div v-if="activeSalesMixTab === 'subcategories'" class="flex flex-col gap-5">
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Top SubCategories by Revenue</h3>
                            <p class="text-sm text-gray-500">
                                {{ salesMixMeta.date_from }} to {{ salesMixMeta.date_to }} - {{ salesMixMeta.store_count }} store(s) - Total {{ formatCurrency(salesMixMeta.total_revenue) }}
                            </p>
                        </div>
                        <label
                            v-if="salesMixRows.length > 15"
                            class="inline-flex items-center gap-2 text-sm font-medium text-gray-700"
                        >
                            <Checkbox v-model="showAllSalesMixRows" :binary="true" />
                            Show rank 16 onward
                        </label>
                    </div>

                    <div v-if="salesMixLoading" class="flex h-72 items-center justify-center text-sm text-gray-500">
                        Loading Sales Mix...
                    </div>
                    <div v-else-if="salesMixError" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                        {{ salesMixError }}
                    </div>
                    <div v-else-if="salesMixLoaded && salesMixRows.length === 0" class="flex h-72 flex-col items-center justify-center text-center text-gray-500">
                        <BarChart3 class="mb-3 h-10 w-10 text-gray-300" />
                        <p class="text-sm font-medium">No subcategories found.</p>
                    </div>
                    <div v-else-if="salesMixLoaded" class="overflow-x-auto">
                        <Chart
                            type="bar"
                            :data="salesMixChartData"
                            :options="salesMixChartOptions"
                            class="min-w-[48rem]"
                            :style="{ height: salesMixChartHeight }"
                        />
                    </div>
                    <div v-else class="flex h-72 flex-col items-center justify-center text-center text-gray-500">
                        <BarChart3 class="mb-3 h-10 w-10 text-gray-300" />
                        <p class="text-sm font-medium">Open this tab to load Sales Mix.</p>
                    </div>
                </div>
            </div>

            <div v-else-if="activeSalesMixTab === 'products-revenue'" class="flex flex-col gap-5">
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Overall Top Products by Revenue</h3>
                            <p class="text-sm text-gray-500">
                                {{ productRevenueMeta.date_from }} to {{ productRevenueMeta.date_to }} - {{ productRevenueMeta.store_count }} store(s) - Total {{ formatCurrency(productRevenueMeta.total_revenue) }}
                            </p>
                        </div>
                        <label
                            v-if="hasProductRevenueOverflow"
                            class="inline-flex items-center gap-2 text-sm font-medium text-gray-700"
                        >
                            <Checkbox v-model="showAllProductRevenueRows" :binary="true" />
                            Show rank 16 onward
                        </label>
                    </div>

                    <div v-if="productRevenueLoading" class="flex h-72 items-center justify-center text-sm text-gray-500">
                        Loading product revenue...
                    </div>
                    <div v-else-if="productRevenueError" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                        {{ productRevenueError }}
                    </div>
                    <div v-else-if="productRevenueLoaded && productRevenueOverallRows.length === 0" class="flex h-72 flex-col items-center justify-center text-center text-gray-500">
                        <BarChart3 class="mb-3 h-10 w-10 text-gray-300" />
                        <p class="text-sm font-medium">No products found.</p>
                    </div>
                    <div v-else-if="productRevenueLoaded" class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-600">Rank</th>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-600">POS Code</th>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-600">Item Name</th>
                                    <th class="px-3 py-2 text-right font-semibold text-gray-600">Revenue</th>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-600">Category</th>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-600">SubCategory</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="row in visibleProductRevenueOverallRows" :key="`overall-${row.pos_code}`" class="hover:bg-gray-50">
                                    <td class="px-3 py-2 font-medium text-gray-900">{{ row.rank }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ row.pos_code }}</td>
                                    <td class="px-3 py-2 text-gray-900">{{ row.item_name }}</td>
                                    <td class="px-3 py-2 text-right font-medium text-gray-900">{{ formatCurrency(row.revenue) }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ row.category }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ row.sub_category }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="flex h-72 flex-col items-center justify-center text-center text-gray-500">
                        <BarChart3 class="mb-3 h-10 w-10 text-gray-300" />
                        <p class="text-sm font-medium">Open this tab to load product revenue.</p>
                    </div>
                </div>

                <div v-if="productRevenueLoaded && !productRevenueLoading && !productRevenueError" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="mb-4 text-base font-semibold text-gray-900">Top Products by Main Category</h3>
                    <div class="flex flex-col gap-5">
                        <section
                            v-for="category in productRevenueCategories"
                            :key="category"
                            class="overflow-hidden rounded-lg border border-gray-200"
                        >
                            <div class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                                <h4 class="text-sm font-semibold text-gray-900">{{ category }}</h4>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-white">
                                        <tr>
                                            <th class="px-3 py-2 text-left font-semibold text-gray-600">Rank</th>
                                            <th class="px-3 py-2 text-left font-semibold text-gray-600">POS Code</th>
                                            <th class="px-3 py-2 text-left font-semibold text-gray-600">Item Name</th>
                                            <th class="px-3 py-2 text-right font-semibold text-gray-600">Revenue</th>
                                            <th class="px-3 py-2 text-left font-semibold text-gray-600">Category</th>
                                            <th class="px-3 py-2 text-left font-semibold text-gray-600">SubCategory</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        <tr
                                            v-for="row in visibleProductRevenueRowsForCategory(category)"
                                            :key="`${category}-${row.pos_code}`"
                                            class="hover:bg-gray-50"
                                        >
                                            <td class="px-3 py-2 font-medium text-gray-900">{{ row.rank }}</td>
                                            <td class="px-3 py-2 text-gray-700">{{ row.pos_code }}</td>
                                            <td class="px-3 py-2 text-gray-900">{{ row.item_name }}</td>
                                            <td class="px-3 py-2 text-right font-medium text-gray-900">{{ formatCurrency(row.revenue) }}</td>
                                            <td class="px-3 py-2 text-gray-700">{{ row.category }}</td>
                                            <td class="px-3 py-2 text-gray-700">{{ row.sub_category }}</td>
                                        </tr>
                                        <tr v-if="visibleProductRevenueRowsForCategory(category).length === 0">
                                            <td colspan="6" class="px-3 py-8 text-center text-gray-500">No products found.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                </div>
            </div>

            <div v-else-if="activeSalesMixTab === 'products-quantity'" class="flex flex-col gap-5">
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Overall Top Products by Qty Sold</h3>
                            <p class="text-sm text-gray-500">
                                {{ productQuantityMeta.date_from }} to {{ productQuantityMeta.date_to }} - {{ productQuantityMeta.store_count }} store(s) - Total Qty {{ formatQuantity(productQuantityMeta.total_quantity) }}
                            </p>
                        </div>
                        <label
                            v-if="hasProductQuantityOverflow"
                            class="inline-flex items-center gap-2 text-sm font-medium text-gray-700"
                        >
                            <Checkbox v-model="showAllProductQuantityRows" :binary="true" />
                            Show rank 16 onward
                        </label>
                    </div>

                    <div v-if="productQuantityLoading" class="flex h-72 items-center justify-center text-sm text-gray-500">
                        Loading product quantity...
                    </div>
                    <div v-else-if="productQuantityError" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                        {{ productQuantityError }}
                    </div>
                    <div v-else-if="productQuantityLoaded && productQuantityOverallRows.length === 0" class="flex h-72 flex-col items-center justify-center text-center text-gray-500">
                        <BarChart3 class="mb-3 h-10 w-10 text-gray-300" />
                        <p class="text-sm font-medium">No products found.</p>
                    </div>
                    <div v-else-if="productQuantityLoaded" class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-600">Rank</th>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-600">POS Code</th>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-600">Item Name</th>
                                    <th class="px-3 py-2 text-right font-semibold text-gray-600">Qty</th>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-600">Category</th>
                                    <th class="px-3 py-2 text-left font-semibold text-gray-600">SubCategory</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="row in visibleProductQuantityOverallRows" :key="`overall-qty-${row.pos_code}`" class="hover:bg-gray-50">
                                    <td class="px-3 py-2 font-medium text-gray-900">{{ row.rank }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ row.pos_code }}</td>
                                    <td class="px-3 py-2 text-gray-900">{{ row.item_name }}</td>
                                    <td class="px-3 py-2 text-right font-medium text-gray-900">{{ formatQuantity(row.quantity) }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ row.category }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ row.sub_category }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="flex h-72 flex-col items-center justify-center text-center text-gray-500">
                        <BarChart3 class="mb-3 h-10 w-10 text-gray-300" />
                        <p class="text-sm font-medium">Open this tab to load product quantity.</p>
                    </div>
                </div>

                <div v-if="productQuantityLoaded && !productQuantityLoading && !productQuantityError" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="mb-4 text-base font-semibold text-gray-900">Top Products by Main Category by Qty Sold</h3>
                    <div class="flex flex-col gap-5">
                        <section
                            v-for="category in productRevenueCategories"
                            :key="`qty-${category}`"
                            class="overflow-hidden rounded-lg border border-gray-200"
                        >
                            <div class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                                <h4 class="text-sm font-semibold text-gray-900">{{ category }}</h4>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-white">
                                        <tr>
                                            <th class="px-3 py-2 text-left font-semibold text-gray-600">Rank</th>
                                            <th class="px-3 py-2 text-left font-semibold text-gray-600">POS Code</th>
                                            <th class="px-3 py-2 text-left font-semibold text-gray-600">Item Name</th>
                                            <th class="px-3 py-2 text-right font-semibold text-gray-600">Qty</th>
                                            <th class="px-3 py-2 text-left font-semibold text-gray-600">Category</th>
                                            <th class="px-3 py-2 text-left font-semibold text-gray-600">SubCategory</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                        <tr
                                            v-for="row in visibleProductQuantityRowsForCategory(category)"
                                            :key="`${category}-qty-${row.pos_code}`"
                                            class="hover:bg-gray-50"
                                        >
                                            <td class="px-3 py-2 font-medium text-gray-900">{{ row.rank }}</td>
                                            <td class="px-3 py-2 text-gray-700">{{ row.pos_code }}</td>
                                            <td class="px-3 py-2 text-gray-900">{{ row.item_name }}</td>
                                            <td class="px-3 py-2 text-right font-medium text-gray-900">{{ formatQuantity(row.quantity) }}</td>
                                            <td class="px-3 py-2 text-gray-700">{{ row.category }}</td>
                                            <td class="px-3 py-2 text-gray-700">{{ row.sub_category }}</td>
                                        </tr>
                                        <tr v-if="visibleProductQuantityRowsForCategory(category).length === 0">
                                            <td colspan="6" class="px-3 py-8 text-center text-gray-500">No products found.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </section>

        <section v-else-if="activeDashboardTab === 'adoption-rate'" class="flex flex-col gap-5">
            <div class="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm lg:grid-cols-[minmax(16rem,1fr)_10rem_10rem_auto_auto]">
                <InputContainer>
                    <Label class="mb-1 block text-xs font-semibold uppercase text-gray-500">Stores</Label>
                    <MultiSelect
                        v-model="adoptionBranch"
                        filter
                        placeholder="All Stores"
                        :options="adoptionStoreOptions"
                        optionLabel="label"
                        optionValue="value"
                    ></MultiSelect>
                </InputContainer>
                <InputContainer>
                    <Label class="mb-1 block text-xs font-semibold uppercase text-gray-500">From</Label>
                    <Input v-model="adoptionDateFrom" type="date" />
                </InputContainer>
                <InputContainer>
                    <Label class="mb-1 block text-xs font-semibold uppercase text-gray-500">To</Label>
                    <Input v-model="adoptionDateTo" type="date" />
                </InputContainer>
                <div class="flex items-end">
                    <Button class="w-full gap-2" @click="applyAdoptionFilters" :disabled="adoptionLoading">
                        <Search class="h-4 w-4" />
                        Apply
                    </Button>
                </div>
                <div class="flex items-end">
                    <Button class="w-full gap-2" variant="outline" @click="resetAdoptionFilters" :disabled="adoptionLoading">
                        <RotateCcw class="h-4 w-4" />
                        Reset
                    </Button>
                </div>
            </div>

            <div v-if="adoptionLoaded" class="grid gap-4 rounded-lg border border-cyan-200 bg-cyan-50 p-5 shadow-sm md:grid-cols-[1fr_auto] md:items-center">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-cyan-700">Report-Tallied Overall Adoption Rate</p>
                    <p class="mt-1 text-3xl font-semibold text-cyan-900">{{ adoptionMeta.overall_rate === null || adoptionMeta.overall_rate === undefined ? 'N/A' : formatPercent(adoptionMeta.overall_rate) }}</p>
                    <p class="mt-1 text-sm text-cyan-800">
                        {{ adoptionMeta.date_from }} to {{ adoptionMeta.date_to }} - {{ adoptionMeta.store_count }} accessible store(s)
                    </p>
                    <p class="mt-2 text-xs text-cyan-700">Weekly chart points remain week-specific and are not averaged to produce this whole-period rate.</p>
                </div>
                <a v-if="canViewAdoptionReport" :href="adoptionMatchingReportUrl" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-cyan-700 px-4 text-sm font-medium text-white transition hover:bg-cyan-800">
                    View Matching Report
                    <ExternalLink class="h-4 w-4" />
                </a>
            </div>

            <div v-if="adoptionLoaded" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="mb-4">
                    <h3 class="text-base font-semibold text-gray-900">Report-Tallied Selected Range Indicators</h3>
                    <p class="text-sm text-gray-500">These percentages use the complete selected date range and match the corresponding Adoption Rate Tracking report tabs.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <div v-for="indicator in adoptionMeta.indicator_rates || []" :key="indicator.no" class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <p class="text-sm text-gray-600">{{ indicator.indicator }}</p>
                        <p class="mt-2 text-2xl font-semibold text-blue-700">{{ indicator.rate === null || indicator.rate === undefined ? 'N/A' : formatPercent(indicator.rate) }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ adoptionViewMode === 'combined' ? 'Report-Tallied Selected Range Reference Rates' : 'Weekly Adoption Rate by Store' }}</h3>
                        <p class="text-sm text-gray-500">
                            {{ adoptionMeta.date_from }} to {{ adoptionMeta.date_to }} - {{ adoptionMeta.store_count }} store(s)
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex overflow-hidden rounded-md border border-gray-200">
                            <button
                                type="button"
                                :class="[
                                    'px-3 py-1.5 text-sm font-medium transition-colors',
                                    adoptionViewMode === 'combined'
                                        ? 'bg-cyan-600 text-white'
                                        : 'bg-white text-gray-600 hover:bg-gray-50'
                                ]"
                                @click="adoptionViewMode = 'combined'"
                            >
                                Combined
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'px-3 py-1.5 text-sm font-medium transition-colors border-l border-gray-200',
                                    adoptionViewMode === 'per_store'
                                        ? 'bg-cyan-600 text-white'
                                        : 'bg-white text-gray-600 hover:bg-gray-50'
                                ]"
                                @click="adoptionViewMode = 'per_store'"
                            >
                                Per Store
                            </button>
                        </div>
                        <div class="inline-flex overflow-hidden rounded-md border border-gray-200">
                            <button
                                type="button"
                                :class="[
                                    'px-3 py-1.5 text-sm font-medium transition-colors',
                                    adoptionChartType === 'line'
                                        ? 'bg-cyan-600 text-white'
                                        : 'bg-white text-gray-600 hover:bg-gray-50'
                                ]"
                                @click="adoptionChartType = 'line'"
                            >
                                Line
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'px-3 py-1.5 text-sm font-medium transition-colors border-l border-gray-200',
                                    adoptionChartType === 'bar'
                                        ? 'bg-cyan-600 text-white'
                                        : 'bg-white text-gray-600 hover:bg-gray-50'
                                ]"
                                @click="adoptionChartType = 'bar'"
                            >
                                Bar
                            </button>
                        </div>
                    </div>
                </div>

                <p class="mb-4 text-xs text-gray-400">
                    {{ adoptionViewMode === 'combined'
                        ? 'Each constant line spans the selected period and exactly matches the corresponding selected-range Adoption Rate Tracking report percentage.'
                        : 'Overall weekly adoption rate for each selected store.' }}
                </p>

                <div v-if="adoptionLoading" class="flex h-96 items-center justify-center text-sm text-gray-500">
                    Loading Adoption Rate...
                </div>
                <div v-else-if="adoptionError" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    {{ adoptionError }}
                </div>
                <div v-else-if="adoptionLoaded && !adoptionHasData" class="flex h-96 flex-col items-center justify-center text-center text-gray-500">
                    <BarChart3 class="mb-3 h-10 w-10 text-gray-300" />
                    <p class="text-sm font-medium">No adoption rate data for this range.</p>
                </div>
                <div v-else-if="adoptionLoaded" class="overflow-x-auto">
                    <Chart
                        :key="'adoption-' + adoptionChartType"
                        :type="adoptionChartType"
                        :data="adoptionChartData"
                        :options="adoptionChartOptions"
                        class="h-[30rem] min-w-[48rem]"
                    />
                </div>
                <div v-else class="flex h-96 flex-col items-center justify-center text-center text-gray-500">
                    <BarChart3 class="mb-3 h-10 w-10 text-gray-300" />
                    <p class="text-sm font-medium">Open this tab to load Adoption Rate.</p>
                </div>
            </div>
        </section>

        <div v-else class="flex flex-col items-center justify-center py-24 text-center text-gray-400">
            <svg class="mb-4 h-12 w-12 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636" />
            </svg>
            <p class="text-sm font-medium">You don't have access to any Dashboard tabs.</p>
            <p class="mt-1 text-xs">Contact your administrator to request access.</p>
        </div>
    </Layout>
</template>
