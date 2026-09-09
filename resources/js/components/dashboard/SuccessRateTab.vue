<script setup>
/**
 * Dashboard > Success Rate tab.
 *
 * Port of the "David - Adoption Rate and Success Rate" workbook. The user keys
 * in weekly Incoming / Closed tickets per module and nothing else. Transaction
 * volume comes from live system activity, and Success Rate, Close Rate and every
 * total are derived (here for the live editor preview, and authoritatively in
 * SuccessRateService on save/read).
 */
import { ref, computed, onMounted } from "vue";
import axios from "axios";
import Chart from "primevue/chart";
import { Chart as ChartJS } from "chart.js";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { BarChart3, RotateCcw, Search, Pencil } from "lucide-vue-next";
import { useAuth } from "@/composables/useAuth";

const props = defineProps({
    storeOptions: {
        type: Array,
        default: () => [],
    },
});

const { hasAccess } = useAuth();
const canManage = computed(() => hasAccess("manage success rate tickets"));

const SUCCESS_COLOR = "#4285f4";
const ADOPTION_COLOR = "#ea4335";
const CLOSE_COLOR = "#f59e0b";

const formatDate = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
};

// Default to the trailing 12 weeks so the chart opens on a real trend rather
// than a single partial week. Mirrors SuccessRateService::resolveDateRange().
const today = new Date();
const defaultTo = formatDate(today);
const defaultFrom = (() => {
    const start = new Date(today);
    start.setDate(start.getDate() - 7 * 11);
    const dayOffset = (start.getDay() + 6) % 7; // shift Sunday-first to Monday-first
    start.setDate(start.getDate() - dayOffset);

    return formatDate(start);
})();

const defaultBranch = () => [props.storeOptions[0]?.value ?? "all"];

const branch = ref(defaultBranch());
const dateFrom = ref(defaultFrom);
const dateTo = ref(defaultTo);

const loaded = ref(false);
const loading = ref(false);
const error = ref("");
const rows = ref([]);
const modules = ref([]);
const totals = ref({});
const meta = ref({ date_from: defaultFrom, date_to: defaultTo });
const showCloseRate = ref(false);

const hasData = computed(() => rows.value.length > 0);

const load = async () => {
    loading.value = true;
    error.value = "";

    try {
        const { data } = await axios.get(route("dashboard.success-rate"), {
            params: {
                branch: branch.value,
                date_from: dateFrom.value,
                date_to: dateTo.value,
            },
        });

        rows.value = data.rows ?? [];
        modules.value = data.modules ?? [];
        totals.value = data.totals ?? {};
        meta.value = data.filters ?? meta.value;
        loaded.value = true;
    } catch (e) {
        error.value = e.response?.data?.message || "Failed to load Success Rate data.";
    } finally {
        loading.value = false;
    }
};

const applyFilters = () => {
    loaded.value = false;
    load();
};

const resetFilters = () => {
    branch.value = defaultBranch();
    dateFrom.value = defaultFrom;
    dateTo.value = defaultTo;
    loaded.value = false;
    load();
};

// The parent triggers the first load lazily, only when this tab is opened.
defineExpose({ load, loaded });

const formatPercent = (value) =>
    value === null || value === undefined ? "N/A" : `${Number(value).toFixed(2)}%`;

const formatNumber = (value) => new Intl.NumberFormat("en-PH").format(Number(value || 0));

// Spells out which modules contributed a week's transaction volume, so the
// success-rate denominator is never an unexplained number.
const transactionBreakdown = (row) =>
    modules.value
        .filter((module) => module.has_transactions)
        .map((module) => `${module.label}: ${formatNumber(row[`${module.key}_transactions`])}`)
        .join(String.fromCharCode(10));

const chartData = computed(() => {
    const labels = rows.value.map((row) => row.week_label);

    const datasets = [
        {
            label: "Success Rate",
            borderColor: SUCCESS_COLOR,
            backgroundColor: SUCCESS_COLOR,
            data: rows.value.map((row) => row.success_rate),
            tension: 0.25,
            spanGaps: true,
        },
        {
            label: "Adoption Rate",
            borderColor: ADOPTION_COLOR,
            backgroundColor: ADOPTION_COLOR,
            data: rows.value.map((row) => row.adoption_rate),
            tension: 0.25,
            spanGaps: true,
        },
    ];

    if (showCloseRate.value) {
        datasets.push({
            label: "Close Rate",
            borderColor: CLOSE_COLOR,
            backgroundColor: CLOSE_COLOR,
            data: rows.value.map((row) => row.close_rate),
            tension: 0.25,
            spanGaps: true,
            borderDash: [6, 4],
        });
    }

    return { labels, datasets };
});

const chartOptions = computed(() => {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue("--p-text-color");
    const textColorSecondary = documentStyle.getPropertyValue("--p-text-muted-color");
    const surfaceBorder = documentStyle.getPropertyValue("--p-content-border-color");

    return {
        maintainAspectRatio: false,
        interaction: { mode: "index", intersect: false },
        layout: { padding: { top: 24, bottom: 8 } },
        plugins: {
            legend: { labels: { color: textColor, usePointStyle: true } },
            successRatePointLabels: { enabled: true },
            tooltip: {
                callbacks: {
                    label: (context) => `${context.dataset.label}: ${formatPercent(context.parsed.y)}`,
                    title: (items) => {
                        const row = rows.value[items[0]?.dataIndex];

                        return row ? `${row.week_label} (${row.week_range})` : "";
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
                max: 100,
                ticks: { color: textColorSecondary, stepSize: 25, callback: (value) => `${value}%` },
                grid: { color: surfaceBorder },
            },
        },
    };
});

// --- Week editor ---------------------------------------------------------
const dialogOpen = ref(false);
const saving = ref(false);
const saveError = ref("");
const form = ref(null);

// The week being edited, kept whole so the dialog can show that week's derived
// transaction volume alongside the ticket inputs. Never sent back to the server.
const editingRow = ref(null);

const openEditor = (row) => {
    if (!canManage.value) return;

    const values = {
        week_start: row.week_start,
        week_label: row.week_label,
        week_range: row.week_range,
        adoption_rate_override: row.adoption_rate_override ?? "",
        remarks: row.remarks ?? "",
    };

    modules.value.forEach((module) => {
        values[`${module.key}_incoming`] = row[`${module.key}_incoming`] ?? 0;
        values[`${module.key}_closed`] = row[`${module.key}_closed`] ?? 0;
    });

    saveError.value = "";
    editingRow.value = row;
    form.value = values;
    dialogOpen.value = true;
};

const num = (value) => {
    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed > 0 ? Math.floor(parsed) : 0;
};

/**
 * Live preview of the workbook formulas while the user types. The same maths
 * runs server-side on read, so this can never become the source of truth.
 */
const preview = computed(() => {
    if (!form.value) return {};

    // Every module except Admin/Technical counts as a module concern; only the
    // five with an Adoption Rate indicator contribute transactions.
    const moduleKeys = modules.value.filter((m) => m.key !== "admin").map((m) => m.key);

    const moduleConcerns = moduleKeys.reduce((sum, key) => sum + num(form.value[`${key}_incoming`]), 0);
    const technicalConcerns = num(form.value.admin_incoming);
    const totalTickets = moduleConcerns + technicalConcerns;
    const totalClosed =
        moduleKeys.reduce((sum, key) => sum + num(form.value[`${key}_closed`]), 0) +
        num(form.value.admin_closed);
    // Read from the loaded week, not the form: transaction volume is derived
    // server-side from live activity and is not editable here.
    const totalTransactions = num(editingRow.value?.total_transactions);

    return {
        moduleConcerns,
        technicalConcerns,
        totalTickets,
        totalClosed,
        totalOpen: totalTickets - totalClosed,
        totalTransactions,
        successRate: totalTransactions > 0 ? (1 - totalTickets / totalTransactions) * 100 : null,
        closeRate: totalTickets > 0 ? (totalClosed / totalTickets) * 100 : null,
    };
});

const saveWeek = async () => {
    if (!form.value) return;

    saving.value = true;
    saveError.value = "";

    const payload = {
        week_start: form.value.week_start,
        adoption_rate_override:
            form.value.adoption_rate_override === "" || form.value.adoption_rate_override === null
                ? null
                : Number(form.value.adoption_rate_override),
        remarks: form.value.remarks || null,
    };

    modules.value.forEach((module) => {
        payload[`${module.key}_incoming`] = num(form.value[`${module.key}_incoming`]);
        payload[`${module.key}_closed`] = num(form.value[`${module.key}_closed`]);
    });

    try {
        await axios.post(route("dashboard.success-rate.week"), payload);
        dialogOpen.value = false;
        await load();
    } catch (e) {
        saveError.value =
            e.response?.data?.message || "Failed to save this week. Check the values and try again.";
    } finally {
        saving.value = false;
    }
};

/**
 * Prints each point's value beside the line, matching the reference chart.
 * Registered once globally; the per-chart `enabled` flag keeps it off every
 * other Chart.js instance on the dashboard.
 */
const registerPointLabelsPlugin = () => {
    ChartJS.register({
        id: "successRatePointLabels",
        afterDatasetsDraw(chart) {
            if (!chart.config.options.plugins?.successRatePointLabels?.enabled) return;

            const ctx = chart.ctx;
            ctx.save();
            ctx.textAlign = "center";
            ctx.font = "bold 10px Arial";

            chart.data.datasets.forEach((dataset, i) => {
                const datasetMeta = chart.getDatasetMeta(i);
                if (datasetMeta.hidden) return;

                datasetMeta.data.forEach((point, index) => {
                    const value = dataset.data[index];
                    if (value === null || value === undefined) return;

                    // Success Rate rides the top of the plot, so its labels go
                    // below the line; everything else sits above its point.
                    const below = dataset.label === "Success Rate";
                    ctx.textBaseline = below ? "top" : "bottom";
                    ctx.fillStyle = dataset.borderColor || "#374151";
                    ctx.fillText(
                        `${Math.round(Number(value))}%`,
                        point.x,
                        below ? point.y + 6 : point.y - 6
                    );
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
        <div class="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm lg:grid-cols-[minmax(16rem,1fr)_10rem_10rem_auto_auto]">
            <InputContainer>
                <Label class="mb-1 block text-xs font-semibold uppercase text-gray-500">Stores</Label>
                <MultiSelect
                    v-model="branch"
                    filter
                    placeholder="All Stores"
                    :options="storeOptions"
                    optionLabel="label"
                    optionValue="value"
                ></MultiSelect>
            </InputContainer>
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
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-blue-700">Running Ave. Success Rate</p>
                <p class="mt-1 text-3xl font-semibold text-blue-900">{{ formatPercent(totals.running_success_rate) }}</p>
                <p class="mt-1 text-xs text-blue-700">1 - (total tickets / total transactions)</p>
            </div>
            <div class="rounded-lg border border-red-200 bg-red-50 p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-red-700">Running Ave. Adoption Rate</p>
                <p class="mt-1 text-3xl font-semibold text-red-900">{{ formatPercent(totals.running_adoption_rate) }}</p>
                <p class="mt-1 text-xs text-red-700">Per-week override, else the Adoption Rate report</p>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Running Ave. Close Rate</p>
                <p class="mt-1 text-3xl font-semibold text-amber-900">{{ formatPercent(totals.running_close_rate) }}</p>
                <p class="mt-1 text-xs text-amber-700">total closed / total tickets</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-600">Ticket Type Split</p>
                <p class="mt-1 text-lg font-semibold text-gray-900">
                    {{ formatPercent(totals.module_share) }} module
                </p>
                <p class="text-lg font-semibold text-gray-900">
                    {{ formatPercent(totals.technical_share) }} technical
                </p>
                <p class="mt-1 text-xs text-gray-600">
                    {{ formatNumber(totals.total_tickets) }} tickets over {{ formatNumber(totals.total_transactions) }} transactions
                </p>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">DAVID Success Rate vs Adoption Rate</h3>
                    <p class="text-sm text-gray-500">
                        {{ meta.date_from }} to {{ meta.date_to }} - {{ totals.weeks_with_data || 0 }} of
                        {{ totals.weeks || 0 }} week(s) encoded
                    </p>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                    <input v-model="showCloseRate" type="checkbox" class="rounded border-gray-300" />
                    Show Close Rate
                </label>
            </div>

            <div v-if="loading" class="flex h-96 items-center justify-center text-sm text-gray-500">
                Loading Success Rate...
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
                <p class="text-sm font-medium">Open this tab to load Success Rate.</p>
            </div>
        </div>

        <div v-if="loaded && hasData" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-4">
                <h3 class="text-base font-semibold text-gray-900">Weekly Ticket Tally</h3>
                <p class="text-sm text-gray-500">
                    Only Incoming and Closed tickets are encoded. Transactions are counted from live
                    Order, Commit, Receiving, Sales Upload and Wastage activity, and every rate is
                    calculated from the two.
                    <span v-if="!canManage">You have read-only access.</span>
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-3 py-2 text-left">Week Range</th>
                            <th class="px-3 py-2 text-left">Week No</th>
                            <th class="px-3 py-2 text-right">Module</th>
                            <th class="px-3 py-2 text-right">Technical</th>
                            <th class="px-3 py-2 text-right">Total Tickets</th>
                            <th class="px-3 py-2 text-right">Closed</th>
                            <th class="px-3 py-2 text-right">Open</th>
                            <th class="px-3 py-2 text-right">Transactions</th>
                            <th class="px-3 py-2 text-right">Success Rate</th>
                            <th class="px-3 py-2 text-right">Close Rate</th>
                            <th class="px-3 py-2 text-right">Adoption Rate</th>
                            <th class="px-3 py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="row in rows" :key="row.week_start" :class="row.has_record ? '' : 'bg-gray-50/60'">
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ row.week_range }}</td>
                            <td class="whitespace-nowrap px-3 py-2 font-medium text-gray-900">{{ row.week_label }}</td>
                            <td class="px-3 py-2 text-right text-gray-700">{{ row.module_concerns }}</td>
                            <td class="px-3 py-2 text-right text-gray-700">{{ row.technical_concerns }}</td>
                            <td class="px-3 py-2 text-right font-medium text-gray-900">{{ row.total_tickets }}</td>
                            <td class="px-3 py-2 text-right text-gray-700">{{ row.total_closed }}</td>
                            <td class="px-3 py-2 text-right text-gray-700">{{ row.total_open }}</td>
                            <td class="px-3 py-2 text-right text-gray-700" :title="transactionBreakdown(row)">
                                {{ formatNumber(row.total_transactions) }}
                            </td>
                            <td class="px-3 py-2 text-right font-semibold text-blue-700">{{ formatPercent(row.success_rate) }}</td>
                            <td class="px-3 py-2 text-right text-amber-700">{{ formatPercent(row.close_rate) }}</td>
                            <td class="px-3 py-2 text-right font-semibold text-red-700">
                                {{ formatPercent(row.adoption_rate) }}
                                <span v-if="row.adoption_rate_override !== null" class="ml-1 text-xs font-normal text-gray-400">(manual)</span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Button v-if="canManage" size="sm" variant="outline" class="gap-1" @click="openEditor(row)">
                                    <Pencil class="h-3.5 w-3.5" />
                                    {{ row.has_record ? 'Edit' : 'Encode' }}
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-gray-200 bg-gray-50 text-sm font-semibold text-gray-800">
                        <tr>
                            <td class="px-3 py-2" colspan="2">Running Average / Total</td>
                            <td class="px-3 py-2 text-right">{{ totals.module_concerns }}</td>
                            <td class="px-3 py-2 text-right">{{ totals.technical_concerns }}</td>
                            <td class="px-3 py-2 text-right">{{ totals.total_tickets }}</td>
                            <td class="px-3 py-2 text-right">{{ totals.total_closed }}</td>
                            <td class="px-3 py-2 text-right">{{ totals.total_open }}</td>
                            <td class="px-3 py-2 text-right">{{ formatNumber(totals.total_transactions) }}</td>
                            <td class="px-3 py-2 text-right text-blue-700">{{ formatPercent(totals.running_success_rate) }}</td>
                            <td class="px-3 py-2 text-right text-amber-700">{{ formatPercent(totals.running_close_rate) }}</td>
                            <td class="px-3 py-2 text-right text-red-700">{{ formatPercent(totals.running_adoption_rate) }}</td>
                            <td class="px-3 py-2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Encode Tickets - {{ form?.week_label }}</DialogTitle>
                    <DialogDescription>
                        {{ form?.week_range }} - enter Incoming and Closed tickets per module.
                        Transactions come from live system activity and rates are calculated
                        automatically.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="form" class="flex flex-col gap-4">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2 text-left">Ticket Type</th>
                                    <th class="px-3 py-2 text-left">Incoming</th>
                                    <th class="px-3 py-2 text-left">Closed</th>
                                    <th class="px-3 py-2 text-right">
                                        Transactions
                                        <span class="block text-[10px] font-normal normal-case text-gray-400">
                                            from live activity
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="module in modules" :key="module.key">
                                    <td class="px-3 py-2 font-medium text-gray-800">{{ module.label }}</td>
                                    <td class="px-3 py-2">
                                        <Input v-model="form[`${module.key}_incoming`]" type="number" min="0" class="w-24" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <Input v-model="form[`${module.key}_closed`]" type="number" min="0" class="w-24" />
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">
                                        <span v-if="module.has_transactions" class="font-medium text-gray-700">
                                            {{ formatNumber(editingRow?.[`${module.key}_transactions`]) }}
                                        </span>
                                        <span v-else class="text-xs text-gray-400" title="No Adoption Rate indicator measures this module">
                                            n/a
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="grid gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4 sm:grid-cols-5">
                        <div>
                            <p class="text-xs uppercase text-gray-500">Total Tickets</p>
                            <p class="text-lg font-semibold text-gray-900">{{ preview.totalTickets }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-gray-500">Closed / Open</p>
                            <p class="text-lg font-semibold text-gray-900">{{ preview.totalClosed }} / {{ preview.totalOpen }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-gray-500">Transactions</p>
                            <p class="text-lg font-semibold text-gray-900">{{ formatNumber(preview.totalTransactions) }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-gray-500">Success Rate</p>
                            <p class="text-lg font-semibold text-blue-700">{{ formatPercent(preview.successRate) }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase text-gray-500">Close Rate</p>
                            <p class="text-lg font-semibold text-amber-700">{{ formatPercent(preview.closeRate) }}</p>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-[12rem_1fr]">
                        <InputContainer>
                            <Label class="mb-1 block text-xs font-semibold uppercase text-gray-500">
                                Adoption Rate Override (%)
                            </Label>
                            <Input
                                v-model="form.adoption_rate_override"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                placeholder="Auto"
                            />
                            <p class="mt-1 text-xs text-gray-500">Leave blank to use the Adoption Rate report.</p>
                        </InputContainer>
                        <InputContainer>
                            <Label class="mb-1 block text-xs font-semibold uppercase text-gray-500">
                                Remarks (top drivers for inc/dec)
                            </Label>
                            <Textarea v-model="form.remarks" rows="3" />
                        </InputContainer>
                    </div>

                    <p v-if="saveError" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {{ saveError }}
                    </p>
                </div>

                <DialogFooter>
                    <Button variant="outline" :disabled="saving" @click="dialogOpen = false">Cancel</Button>
                    <Button :disabled="saving" @click="saveWeek">{{ saving ? 'Saving...' : 'Save Week' }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
