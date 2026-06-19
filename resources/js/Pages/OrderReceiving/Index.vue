<script setup>
import { ref, reactive, watch, computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { debounce } from "lodash";

const props = defineProps({
    orders: {
        type: Object,
        required: true,
    },
    counts: {
        type: Object,
        required: true,
    },
    filterOptions: {
        type: Object,
        default: () => ({
            stores: [],
            suppliers: [],
            variants: [],
            agingOptions: [],
            statusOptions: [],
        }),
    },
});

const page = usePage();
const initial = page.props.filters || {};

// --- Date helpers (avoid timezone shifts by using local date parts) ---
const fmtDate = (d) => {
    if (!d) return null;
    if (typeof d === "string") return d;
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
};
const parseDate = (s) => (s ? new Date(`${s}T00:00:00`) : null);
const toArray = (v) => (Array.isArray(v) ? v : v != null && v !== "" ? [v] : []);
const toNumberArray = (v) => toArray(v).map((x) => Number(x));

// Receiving status tab + free-text search
let filter = ref(initial.currentFilter || "all");
const search = ref(initial.search || "");

// Advanced "suggester" filters
const filters = reactive({
    order_number: initial.order_number || "",
    delivery_date_from: parseDate(initial.delivery_date_from),
    delivery_date_to: parseDate(initial.delivery_date_to),
    placed_date_from: parseDate(initial.placed_date_from),
    placed_date_to: parseDate(initial.placed_date_to),
    store_ids: toNumberArray(initial.store_ids),
    supplier_ids: toNumberArray(initial.supplier_ids),
    variants: toArray(initial.variants),
    aging: toArray(initial.aging),
});

const panelOpen = ref(false);

// Build the query params for the current state of all filters
const buildParams = () => {
    const p = { currentFilter: filter.value };
    if (search.value) p.search = search.value;
    if (filters.order_number) p.order_number = filters.order_number;

    const dFrom = fmtDate(filters.delivery_date_from);
    const dTo = fmtDate(filters.delivery_date_to);
    const pFrom = fmtDate(filters.placed_date_from);
    const pTo = fmtDate(filters.placed_date_to);
    if (dFrom) p.delivery_date_from = dFrom;
    if (dTo) p.delivery_date_to = dTo;
    if (pFrom) p.placed_date_from = pFrom;
    if (pTo) p.placed_date_to = pTo;

    if (filters.store_ids.length) p.store_ids = filters.store_ids;
    if (filters.supplier_ids.length) p.supplier_ids = filters.supplier_ids;
    if (filters.variants.length) p.variants = filters.variants;
    if (filters.aging.length) p.aging = filters.aging;
    return p;
};

const go = () => {
    router.get(route("orders-receiving.index"), buildParams(), {
        preserveState: true,
        replace: true,
        preserveScroll: true,
    });
};

// Debounced reload for the free-text search box
const debouncedGo = debounce(go, 500);
watch(search, () => debouncedGo());

const applyFilters = () => {
    panelOpen.value = false;
    go();
};

const clearAllFilters = () => {
    search.value = "";
    filters.order_number = "";
    filters.delivery_date_from = null;
    filters.delivery_date_to = null;
    filters.placed_date_from = null;
    filters.placed_date_to = null;
    filters.store_ids = [];
    filters.supplier_ids = [];
    filters.variants = [];
    filters.aging = [];
    filter.value = "all";
    panelOpen.value = false;
    go();
};

const changeFilter = (currentFilter) => {
    filter.value = currentFilter;
    go();
};

const isFilterActive = (currentFilter) => {
    return filter.value === currentFilter ? "bg-primary text-white" : "";
};

// --- Active filter chips ---
const storeMap = computed(() =>
    Object.fromEntries(props.filterOptions.stores.map((s) => [s.value, s.label]))
);
const supplierMap = computed(() =>
    Object.fromEntries(props.filterOptions.suppliers.map((s) => [s.value, s.label]))
);
const agingMap = computed(() =>
    Object.fromEntries(props.filterOptions.agingOptions.map((s) => [s.value, s.label]))
);

const activeChips = computed(() => {
    const chips = [];
    if (filters.order_number)
        chips.push({ key: "order_number", label: `Order #: ${filters.order_number}`, clear: () => (filters.order_number = "") });
    if (filters.delivery_date_from || filters.delivery_date_to)
        chips.push({
            key: "delivery_date",
            label: `Delivery: ${fmtDate(filters.delivery_date_from) || "…"} → ${fmtDate(filters.delivery_date_to) || "…"}`,
            clear: () => {
                filters.delivery_date_from = null;
                filters.delivery_date_to = null;
            },
        });
    if (filters.placed_date_from || filters.placed_date_to)
        chips.push({
            key: "placed_date",
            label: `Placed: ${fmtDate(filters.placed_date_from) || "…"} → ${fmtDate(filters.placed_date_to) || "…"}`,
            clear: () => {
                filters.placed_date_from = null;
                filters.placed_date_to = null;
            },
        });
    filters.store_ids.forEach((id) =>
        chips.push({ key: `store-${id}`, label: `Store: ${storeMap.value[id] ?? id}`, clear: () => (filters.store_ids = filters.store_ids.filter((x) => x !== id)) })
    );
    filters.supplier_ids.forEach((id) =>
        chips.push({ key: `supplier-${id}`, label: `Supplier: ${supplierMap.value[id] ?? id}`, clear: () => (filters.supplier_ids = filters.supplier_ids.filter((x) => x !== id)) })
    );
    filters.variants.forEach((v) =>
        chips.push({ key: `variant-${v}`, label: `Variant: ${v}`, clear: () => (filters.variants = filters.variants.filter((x) => x !== v)) })
    );
    filters.aging.forEach((a) =>
        chips.push({ key: `aging-${a}`, label: `Status: ${agingMap.value[a] ?? a}`, clear: () => (filters.aging = filters.aging.filter((x) => x !== a)) })
    );
    return chips;
});

const hasActiveFilters = computed(() => activeChips.value.length > 0 || (search.value && search.value.length > 0));
const activeFilterCount = computed(() => activeChips.value.length);

const removeChip = (chip) => {
    chip.clear();
    go();
};

const statusBadgeColor = (status) => {
    switch (status.toUpperCase()) {
        case "RECEIVED":
            return "bg-green-500 text-white";
        case "PENDING": // This status might not appear in this list, but keeping for completeness
            return "bg-yellow-500 text-white";
        case "INCOMPLETE":
            return "bg-orange-500 text-white";
        case "COMMITED": // Keeping this case, and the tab is now re-added
            return "bg-blue-400 text-white";
        default:
            return "bg-gray-500 text-white"; // Fallback for other statuses
    }
};

const viewDetails = (id) => {
    router.get(`/orders-receiving/show/${id}`);
};

import { useAuth } from "@/composables/useAuth";

const { hasAccess } = useAuth();

const exportRoute = computed(() => {
    // Export honours every applied filter, not just search + status.
    return route("orders-receiving.export", buildParams());
});

const getSupplierDisplayName = (supplier, variant) => {
    if (!supplier?.name) return 'N/A';
    return supplier.name === 'DROPSHIPPING' && variant === 'mass regular' ? 'FRUITS AND VEGETABLES' : supplier.name;
};
</script>

<template>
    <Layout
        heading="Inbound Orders"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <FilterTab>
            <!-- "All" tab -->
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('all')"
                @click="changeFilter('all')"
            >ALL
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('all')"
                >{{ counts.all }}</Badge>
            </Button>

            <!-- "Received" tab -->
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('received')"
                @click="changeFilter('received')"
            >RECEIVED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('received')"
                >{{ counts.received }}</Badge>
            </Button>

            <!-- "Incomplete" tab -->
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('incomplete')"
                @click="changeFilter('incomplete')"
            >PARTIAL RECEIVED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('incomplete')"
                >{{ counts.incomplete }}</Badge>
            </Button>
            
            <!-- Re-added "COMMITED" tab as per request -->
            <Button
                class="sm:px-10 px-3 bg-white/10 text-gray-800 hover:text-white gap-5 sm:text-sm text-xs"
                :class="isFilterActive('commited')"
                @click="changeFilter('commited')"
            >COMMITED
                <Badge
                    class="sm:flex hidden border border-gray bg-transparent text-gray-900 px-2"
                    :class="isFilterActive('commited')"
                >{{ counts.commited }}</Badge>
            </Button>
        </FilterTab>

        <TableContainer>
            <TableHeader>
                <div class="flex w-full items-center gap-2">
                    <SearchBar>
                        <Input
                            class="pl-10"
                            v-model="search"
                            placeholder="Search..."
                        />
                    </SearchBar>

                    <Popover v-model:open="panelOpen">
                        <PopoverTrigger as-child>
                            <Button
                                class="gap-2 shrink-0 bg-primary text-white border border-primary hover:bg-primary/90 shadow-sm"
                            >
                                <Filter class="h-4 w-4" />
                                <span class="hidden sm:inline">Filters</span>
                                <Badge
                                    v-if="activeFilterCount"
                                    class="bg-white text-primary px-2"
                                >{{ activeFilterCount }}</Badge>
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent
                            align="end"
                            :collisionPadding="12"
                            class="w-[22rem] max-h-[min(80vh,var(--radix-popper-available-height))] p-0 flex flex-col"
                            @interactOutside="(e) => {
                                const target = e.detail?.originalEvent?.target;
                                if (target && (
                                    target.closest('.p-multiselect-overlay') ||
                                    target.closest('.p-datepicker') ||
                                    target.closest('.p-select-overlay') ||
                                    target.closest('[data-pc-name]')
                                )) {
                                    e.preventDefault();
                                }
                            }"
                        >
                            <div class="px-4 pt-4 pb-2 font-semibold text-sm border-b shrink-0">
                                Filter Inbound Orders
                            </div>

                            <div class="px-4 py-3 space-y-4 overflow-y-auto flex-1">
                            <!-- Order Number -->
                            <div class="space-y-1">
                                <InputLabel label="Order Number" :isRequired="false" />
                                <Input
                                    v-model="filters.order_number"
                                    placeholder="e.g. SO-00123"
                                    @keyup.enter="applyFilters"
                                />
                            </div>

                            <!-- Delivery Date range -->
                            <div class="space-y-1">
                                <InputLabel label="Delivery Date" :isRequired="false" />
                                <div class="flex items-center gap-2">
                                    <DatePicker
                                        v-model="filters.delivery_date_from"
                                        dateFormat="yy-mm-dd"
                                        showButtonBar
                                        placeholder="From"
                                        class="w-full"
                                        inputClass="w-full"
                                    />
                                    <DatePicker
                                        v-model="filters.delivery_date_to"
                                        dateFormat="yy-mm-dd"
                                        showButtonBar
                                        placeholder="To"
                                        class="w-full"
                                        inputClass="w-full"
                                    />
                                </div>
                            </div>

                            <!-- Order Placed Date range -->
                            <div class="space-y-1">
                                <InputLabel label="Order Placed Date" :isRequired="false" />
                                <div class="flex items-center gap-2">
                                    <DatePicker
                                        v-model="filters.placed_date_from"
                                        dateFormat="yy-mm-dd"
                                        showButtonBar
                                        placeholder="From"
                                        class="w-full"
                                        inputClass="w-full"
                                    />
                                    <DatePicker
                                        v-model="filters.placed_date_to"
                                        dateFormat="yy-mm-dd"
                                        showButtonBar
                                        placeholder="To"
                                        class="w-full"
                                        inputClass="w-full"
                                    />
                                </div>
                            </div>

                            <!-- Store -->
                            <div class="space-y-1">
                                <InputLabel label="Store" :isRequired="false" />
                                <MultiSelect
                                    v-model="filters.store_ids"
                                    :options="filterOptions.stores"
                                    optionLabel="label"
                                    optionValue="value"
                                    filter
                                    placeholder="All stores"
                                    class="w-full"
                                    display="chip"
                                />
                            </div>

                            <!-- Supplier -->
                            <div class="space-y-1">
                                <InputLabel label="Supplier" :isRequired="false" />
                                <MultiSelect
                                    v-model="filters.supplier_ids"
                                    :options="filterOptions.suppliers"
                                    optionLabel="label"
                                    optionValue="value"
                                    filter
                                    placeholder="All suppliers"
                                    class="w-full"
                                    display="chip"
                                />
                            </div>

                            <!-- Variant -->
                            <div class="space-y-1">
                                <InputLabel label="Variant (Order Type)" :isRequired="false" />
                                <MultiSelect
                                    v-model="filters.variants"
                                    :options="filterOptions.variants"
                                    optionLabel="label"
                                    optionValue="value"
                                    filter
                                    placeholder="All variants"
                                    class="w-full"
                                    display="chip"
                                />
                            </div>

                            <!-- Aging / Due Status -->
                            <div class="space-y-2">
                                <InputLabel label="Aging / Due Status" :isRequired="false" />
                                <MultiSelect
                                    v-model="filters.aging"
                                    :options="filterOptions.agingOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    placeholder="Any"
                                    class="w-full"
                                    display="chip"
                                />
                                <div class="rounded-md bg-gray-50 border border-gray-200 px-3 py-2 text-xs text-gray-600 space-y-2">
                                    <p class="font-semibold text-gray-700">Based on Delivery Date vs. Today — unreceived orders only</p>
                                    <div>
                                        <p><span class="font-medium text-red-600">Overdue</span> — Delivery date has already <span class="font-medium">passed</span> but order is not yet received.</p>
                                        <p class="text-gray-400 mt-0.5">e.g. Delivery date: Jun 10, Today: Jun 19 → Overdue</p>
                                    </div>
                                    <div>
                                        <p><span class="font-medium text-yellow-600">Due Today</span> — Delivery date is <span class="font-medium">today</span> and order is not yet received.</p>
                                        <p class="text-gray-400 mt-0.5">e.g. Delivery date: Jun 19, Today: Jun 19 → Due Today</p>
                                    </div>
                                    <div>
                                        <p><span class="font-medium text-green-600">Upcoming</span> — Delivery date is still in the <span class="font-medium">future</span> and order is not yet received.</p>
                                        <p class="text-gray-400 mt-0.5">e.g. Delivery date: Jun 25, Today: Jun 19 → Upcoming</p>
                                    </div>
                                </div>
                            </div>
                            </div>

                            <div class="flex items-center justify-between gap-2 px-4 py-3 border-t shrink-0 bg-white">
                                <Button
                                    variant="ghost"
                                    class="text-gray-600"
                                    @click="clearAllFilters"
                                >Clear all</Button>
                                <Button
                                    class="bg-primary text-white hover:bg-primary/90"
                                    @click="applyFilters"
                                >Apply Filters</Button>
                            </div>
                        </PopoverContent>
                    </Popover>
                </div>

                <!-- Active filter chips -->
                <div
                    v-if="hasActiveFilters"
                    class="flex flex-wrap items-center gap-2 mt-3"
                >
                    <Badge
                        v-for="chip in activeChips"
                        :key="chip.key"
                        class="bg-gray-100 text-gray-800 border border-gray-300 gap-1 pr-1"
                    >
                        {{ chip.label }}
                        <button
                            type="button"
                            class="ml-1 rounded-full hover:bg-gray-300 px-1 leading-none"
                            @click="removeChip(chip)"
                        >×</button>
                    </Badge>
                    <Button
                        variant="ghost"
                        class="text-xs text-gray-500 h-6 px-2"
                        @click="clearAllFilters"
                    >Clear all</Button>
                </div>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Supplier</TH>
                    <TH>Store</TH>
                    <TH>SO/PO Number</TH>
                    <TH>Order #</TH>
                    <TH>Delivery Date</TH>
                    <TH>Order Placed Date</TH>
                    <TH>Variant</TH>
                    <TH>Receiving Status</TH>
                    <TH v-if="hasAccess('view approved order')">Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders.data" :key="order.id">
                        <TD>{{ order.id }}</TD>
                        <TD>{{ getSupplierDisplayName(order.supplier, order.variant) }}</TD>
                        <TD>{{ order.store_branch?.name ?? "N/A" }}</TD>
                        <TD>{{ order.delivery_receipts && order.delivery_receipts.length > 0 ? order.delivery_receipts[0].sap_so_number : "N/A" }}</TD>
                        <TD>{{ order.order_number }}</TD>
                        <TD>{{ order.order_date }}</TD>
                        <TD>{{ order.created_at }}</TD>
                        <TD>
                            <span v-if="String(order.supplier_id) === '5' && order.variant && order.variant !== 'N/A' && order.variant !== 'mass dts'">
                                {{ order.variant }}
                            </span>
                        </TD>
                        <TD>
                            <Badge
                                :class="statusBadgeColor(order.order_status)"
                                class="font-bold"
                            >{{
                                (order.order_status.toUpperCase() === 'RECEIVED' || order.order_status.toUpperCase() === 'INCOMPLETE') ? 'RECEIVED' : order.order_status.toUpperCase().replace("_", " ")
                            }}</Badge>
                        </TD>
                        <TD>
                            <Button
                                v-if="hasAccess('view approved order')"
                                variant="outline"
                                @click="viewDetails(order.order_number)"
                            >
                                <Eye />
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="order in orders.data" :key="order.id">
                    <MobileTableHeading :title="order.order_number">
                        <ShowButton
                            v-if="hasAccess('view approved order')"
                            @click="viewDetails(order.order_number)"
                        />
                    </MobileTableHeading>
                    <LabelXS>Store: {{ order.store_branch?.name ?? "N/A" }}</LabelXS>
                    <LabelXS>SO/PO Number: {{ order.delivery_receipts && order.delivery_receipts.length > 0 ? order.delivery_receipts[0].sap_so_number : "N/A" }}</LabelXS>
                    <LabelXS
                        >Receiving Status:
                        {{ (order.order_status.toUpperCase() === 'RECEIVED' || order.order_status.toUpperCase() === 'INCOMPLETE') ? 'RECEIVED' : order.order_status.toUpperCase() }}</LabelXS
                    >
                    <LabelXS>Order Date: {{ order.order_date }}</LabelXS>
                    <LabelXS v-if="String(order.supplier_id) === '5' && order.variant && order.variant !== 'N/A' && order.variant !== 'mass dts'">
                        Variant: {{ order.variant }}
                    </LabelXS>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>
