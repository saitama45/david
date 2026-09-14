<script setup>
/**
 * Dashboard > Go-Live Stores tab.
 *
 * Rollout tracker for the stores listed in /branches. A store goes live in the
 * week of its first ordering transaction (its first /mass-orders order) and
 * stays live; whether it keeps ordering is for the Success Rate and Adoption
 * Rate tabs. All figures come from GoLiveStoresService.
 */
import { ref, computed, onMounted } from "vue";
import axios from "axios";
import Chart from "primevue/chart";
import { Chart as ChartJS } from "chart.js";
import { BarChart3, RotateCcw, Search } from "lucide-vue-next";

const LIVE_COLOR = "#16a34a";
const START_WEEK = 19;

const formatDate = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
};

// Monday of ISO week `week` in ISO year `year`.
const isoWeekStart = (year, week) => {
    const jan4 = new Date(year, 0, 4);
    const monday = new Date(jan4);
    monday.setDate(jan4.getDate() - ((jan4.getDay() + 6) % 7) + (week - 1) * 7);

    return monday;
};

// Mirrors GoLiveStoresService::resolveDateRange(): Week 19 of the latest year
// that has reached it, through today.
const today = new Date();
const defaultTo = formatDate(today);
const defaultFrom = (() => {
    const start = isoWeekStart(today.getFullYear(), START_WEEK);

    return formatDate(start > today ? isoWeekStart(today.getFullYear() - 1, START_WEEK) : start);
})();

const dateFrom = ref(defaultFrom);
const dateTo = ref(defaultTo);

const loaded = ref(false);
const loading = ref(false);
const error = ref("");
const rows = ref([]);
const totals = ref({});
const meta = ref({ date_from: defaultFrom, date_to: defaultTo });

const hasData = computed(() => rows.value.length > 0);

const load = async () => {
    loading.value = true;
    error.value = "";

    try {
        const { data } = await axios.get(route("dashboard.go-live-stores"), {
            params: {
                date_from: dateFrom.value,
                date_to: dateTo.value,
            },
        });

        rows.value = data.rows ?? [];
        totals.value = data.totals ?? {};
        meta.value = data.filters ?? meta.value;
        loaded.value = true;
    } catch (e) {
        error.value = e.response?.data?.message || "Failed to load Go-Live Stores data.";
    } finally {
        loading.value = false;
    }
};

const applyFilters = () => {
    loaded.value = false;
    load();
};

const resetFilters = () => {
    dateFrom.value = defaultFrom;
    dateTo.value = defaultTo;
    loaded.value = false;
    load();
};

// The parent triggers the first load lazily, only when this tab is opened.
defineExpose({ load, loaded });

const formatPercent = (value) =>
    value === null || value === undefined ? "N/A" : `${Number(value).toFixed(2)}%`;

const chartData = computed(() => ({
    labels: rows.value.map((row) => row.week_label),
    datasets: [
        {
            label: "Go-Live Stores",
            borderColor: LIVE_COLOR,
            backgroundColor: LIVE_COLOR,
            data: rows.value.map((row) => row.live_stores),
            tension: 0, // counts are whole stores; smoothing would draw values that never happened
        },
    ],
}));

const chartOptions = computed(() => {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue("--p-text-color");
    const textColorSecondary = documentStyle.getPropertyValue("--p-text-muted-color");
    const surfaceBorder = documentStyle.getPropertyValue("--p-content-border-color");
    const total = totals.value.total_stores || 0;

    return {
        maintainAspectRatio: false,
        interaction: { mode: "index", intersect: false },
        layout: { padding: { top: 24, bottom: 8 } },
        plugins: {
            legend: { labels: { color: textColor, usePointStyle: true } },
            goLivePointLabels: { enabled: true },
            tooltip: {
                callbacks: {
                    title: (items) => {
                        const row = rows.value[items[0]?.dataIndex];

                        return row ? `${row.week_label} (${row.week_range})` : "";
                    },
                    label: (context) => {
                        const row = rows.value[context.dataIndex];

                        return `Go-Live Stores: ${context.parsed.y} of ${total} (${formatPercent(row?.go_live_rate)})`;
                    },
                    afterBody: (items) => {
                        const row = rows.value[items[0]?.dataIndex];

                        return row?.new_stores.length ? [`New this week: ${row.new_stores.join(", ")}`] : [];
                    },
                },
            },
        },
        scales: {
            x: {
                ticks: { color: textColorSecondary, maxRotation: 60, minRotation: 45 },
                grid: { display: false },
            },
            y: {
                min: 0,
                // One row of headroom so the top point labels clear the legend.
                suggestedMax: total + 1,
                ticks: { color: textColorSecondary, precision: 0 },
                grid: { color: surfaceBorder },
            },
        },
    };
});

/**
 * Prints each point's count above the line. Registered globally; the per-chart
 * `enabled` flag keeps it off every other Chart.js instance on the dashboard.
 */
const registerPointLabelsPlugin = () => {
    ChartJS.register({
        id: "goLivePointLabels",
        afterDatasetsDraw(chart) {
            if (!chart.config.options.plugins?.goLivePointLabels?.enabled) return;

            const ctx = chart.ctx;
            ctx.save();
            ctx.textAlign = "center";
            ctx.textBaseline = "bottom";
            ctx.font = "bold 10px Arial";

            chart.data.datasets.forEach((dataset, i) => {
                const datasetMeta = chart.getDatasetMeta(i);
                if (datasetMeta.hidden) return;

                ctx.fillStyle = dataset.borderColor || "#374151";

                datasetMeta.data.forEach((point, index) => {
                    const value = dataset.data[index];
                    if (value === null || value === undefined) return;

                    ctx.fillText(String(value), point.x, point.y - 6);
                });
            });

            ctx.restore();
        },
    });
};

onMounted(registerPointLabelsPlugin);
</script>

<template>
    <section class="flex flex-col gap-5">
        <div class="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:grid-cols-[10rem_10rem_auto_auto] sm:justify-start">
            <InputContainer>
                <Label class="mb-1 block text-xs font-semibold uppercase text-gray-500">From</Label>
                <Input v-model="dateFrom" type="date" />
            </InputContainer>
            <InputContainer>
                <Label class="mb-1 block text-xs font-semibold uppercase text-gray-500">To</Label>
                <Input v-model="dateTo" type="date" />
            </InputContainer>
            <div class="flex items-end">
                <Button class="w-full gap-2" :disabled="loading" @click="applyFilters">
                    <Search class="h-4 w-4" />
                    Apply
                </Button>
            </div>
            <div class="flex items-end">
                <Button class="w-full gap-2" variant="outline" :disabled="loading" @click="resetFilters">
                    <RotateCcw class="h-4 w-4" />
                    Reset
                </Button>
            </div>
        </div>

        <div v-if="loaded" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-green-200 bg-green-50 p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-green-700">Go-Live Stores</p>
                <p class="mt-1 text-3xl font-semibold text-green-900">
                    {{ totals.live_stores }} <span class="text-lg font-medium text-green-700">/ {{ totals.total_stores }}</span>
                </p>
                <p class="mt-1 text-xs text-green-700">Stores with at least 1 ordering transaction</p>
            </div>
            <div class="rounded-lg border border-cyan-200 bg-cyan-50 p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-cyan-700">Go-Live Rate</p>
                <p class="mt-1 text-3xl font-semibold text-cyan-900">{{ formatPercent(totals.go_live_rate) }}</p>
                <p class="mt-1 text-xs text-cyan-700">Go-live stores / active stores in Branches</p>
            </div>
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">New Go-Lives in Range</p>
                <p class="mt-1 text-3xl font-semibold text-blue-900">{{ totals.new_in_range }}</p>
                <p class="mt-1 text-xs text-blue-700">First ordering transaction within {{ totals.weeks || 0 }} week(s)</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-600">Not Yet Live</p>
                <p class="mt-1 text-3xl font-semibold text-gray-900">{{ (totals.not_live_stores || []).length }}</p>
                <p class="mt-1 text-xs text-gray-600">Active stores with no ordering transaction yet</p>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-4">
                <h3 class="text-base font-semibold text-gray-900">DAVID Go-Live Stores per Week</h3>
                <p class="text-sm text-gray-500">
                    {{ meta.date_from }} to {{ meta.date_to }} - {{ totals.weeks || 0 }} week(s)
                </p>
            </div>

            <div v-if="loading" class="flex h-96 items-center justify-center text-sm text-gray-500">
                Loading Go-Live Stores...
            </div>
            <div v-else-if="error" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                {{ error }}
            </div>
            <div v-else-if="loaded && !hasData" class="flex h-96 flex-col items-center justify-center text-center text-gray-500">
                <BarChart3 class="mb-3 h-10 w-10 text-gray-300" />
                <p class="text-sm font-medium">No weeks in this range.</p>
            </div>
            <div v-else-if="loaded" class="overflow-x-auto">
                <Chart type="line" :data="chartData" :options="chartOptions" class="h-[26rem] min-w-[48rem]" />
            </div>
            <div v-else class="flex h-96 flex-col items-center justify-center text-center text-gray-500">
                <BarChart3 class="mb-3 h-10 w-10 text-gray-300" />
                <p class="text-sm font-medium">Open this tab to load Go-Live Stores.</p>
            </div>
        </div>

        <div v-if="loaded && hasData" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-4">
                <h3 class="text-base font-semibold text-gray-900">Weekly Go-Live Tally</h3>
                <p class="text-sm text-gray-500">
                    A store goes live in the week of its first ordering transaction in Mass Orders.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-3 py-2 text-left">Week Range</th>
                            <th class="px-3 py-2 text-left">Week No</th>
                            <th class="px-3 py-2 text-left">New Go-Live Stores</th>
                            <th class="px-3 py-2 text-right">Go-Live Stores</th>
                            <th class="px-3 py-2 text-right">Not Yet Live</th>
                            <th class="px-3 py-2 text-right">Go-Live Rate</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="row in rows" :key="row.week_start">
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ row.week_range }}</td>
                            <td class="whitespace-nowrap px-3 py-2 font-medium text-gray-900">{{ row.week_label }}</td>
                            <td class="px-3 py-2 text-gray-700">
                                <template v-if="row.new_go_live">
                                    <span class="font-medium text-green-700">+{{ row.new_go_live }}</span>
                                    <span class="ml-1 text-xs text-gray-500">{{ row.new_stores.join(", ") }}</span>
                                </template>
                                <span v-else class="text-gray-400">-</span>
                            </td>
                            <td class="px-3 py-2 text-right font-semibold text-green-700">{{ row.live_stores }} / {{ row.total_stores }}</td>
                            <td class="px-3 py-2 text-right text-gray-700">{{ row.not_live_stores }}</td>
                            <td class="px-3 py-2 text-right text-gray-700">{{ formatPercent(row.go_live_rate) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="loaded && (totals.not_live_stores || []).length" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">Not Yet Live</h3>
            <p class="mb-3 text-sm text-gray-500">Active stores in Branches with no ordering transaction yet.</p>
            <div class="flex flex-wrap gap-2">
                <span
                    v-for="name in totals.not_live_stores"
                    :key="name"
                    class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs text-gray-700"
                >
                    {{ name }}
                </span>
            </div>
        </div>
    </section>
</template>
