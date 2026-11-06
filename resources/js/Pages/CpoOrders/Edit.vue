<script setup>
import { Head, useForm, router } from "@inertiajs/vue3";
import { ref, computed, watch } from "vue";
import axios from "axios";
import AutoComplete from "primevue/autocomplete";
import Select from "primevue/select";
import Button from "primevue/button";
import { Plus, Pencil, Trash2 } from "lucide-vue-next";
import CpoVendorPicker from "@/components/CpoVendorPicker.vue";
import CpoVendorDialog from "@/components/CpoVendorDialog.vue";
import { MIN_SEARCH_CHARS, SEARCH_DELAY_MS, SEARCH_LIMIT } from "@/lib/searchRules";

// Edit a CPO order still waiting for its last approval: store, delivery date, vendor and lines,
// each line with the cost typed from the receipt. The server applies Mass Orders' edit rules.
const props = defineProps({
    order: { type: Object, required: true },
    vendorOption: { type: Object, default: null },
    lines: { type: Array, default: () => [] },
    // The user's stores set up to order CPO, and the order's own store.
    stores: { type: Array, default: () => [] },
    // Last closed date per store (final approved month end counts).
    closedThrough: { type: Object, default: () => ({}) },
    editDeadline: { type: String, default: null },
});

const form = useForm({
    order_date: props.order.order_date,
    store_branch_id: props.order.store_branch_id,
    cpo_vendor_id: props.order.vendor?.id ?? null,
    lines: props.lines.map((line) => ({ ...line })),
});

// --- Store: the user's own CPO stores, already on the page, searched from 3 characters ---
const storeOptions = props.stores.map((store) => ({ ...store, label: store.brand_code ? `${store.name} (${store.brand_code})` : store.name }));
const store = ref(storeOptions.find((option) => option.id === props.order.store_branch_id) ?? null);
const storeSuggestions = ref([]);

const searchStores = ({ query }) => {
    const term = (query ?? "").trim().toLowerCase();
    storeSuggestions.value = !term
        ? []
        : storeOptions
            .filter((option) => (term.length < MIN_SEARCH_CHARS
                ? option.name.toLowerCase() === term || (option.brand_code ?? "").toLowerCase() === term
                : option.label.toLowerCase().includes(term)))
            .slice(0, SEARCH_LIMIT);
};

watch(store, (value) => {
    form.store_branch_id = value && typeof value === "object" ? value.id : null;
});

// --- Delivery date: any date but one the store's final approved month end count closed ---
const closedThroughDate = computed(() => (form.store_branch_id ? props.closedThrough[form.store_branch_id] ?? null : null));

const formatDate = (value) => new Date(value + "T00:00:00").toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });

const minDate = computed(() => {
    if (!closedThroughDate.value) return null;
    const next = new Date(closedThroughDate.value + "T00:00:00");
    next.setDate(next.getDate() + 1);
    return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, "0")}-${String(next.getDate()).padStart(2, "0")}`;
});

// --- Vendor: optional; the order keeps the details it was placed with until another is chosen ---
const vendor = ref(props.vendorOption ?? props.order.vendor);
const vendorDialog = ref({ open: false, editing: false });

watch(vendor, (value) => {
    form.cpo_vendor_id = value && typeof value === "object" ? value.id : null;
});

const vendorUnchanged = computed(() => (vendor.value?.id ?? null) === (props.order.vendor?.id ?? null));
const shownVendor = computed(() => (vendorUnchanged.value ? props.order.vendor : vendor.value));

const openVendorDialog = (editing) => {
    vendorDialog.value = { open: true, editing: editing && !!vendor.value };
};

// --- Adding an item: searched in the CPO list from 3 characters ---
const newLine = ref({ item: null, uom: null, quantity: null, cost: null });
const itemSuggestions = ref([]);
const typedItem = ref("");
const searchingItems = ref(false);
const addProblem = ref("");
let latestItemRequest = 0;

const searchItems = async ({ query }) => {
    typedItem.value = query ?? "";
    const term = typedItem.value.trim();
    const request = ++latestItemRequest;

    if (!term) {
        itemSuggestions.value = [];
        return;
    }

    searchingItems.value = true;
    try {
        const { data } = await axios.get(route("cpo-orders.items.search"), { params: { q: term } });
        // A slower answer to an earlier keystroke must not replace a newer one.
        if (request === latestItemRequest) itemSuggestions.value = data;
    } catch (error) {
        if (request === latestItemRequest) itemSuggestions.value = [];
    } finally {
        if (request === latestItemRequest) searchingItems.value = false;
    }
};

const itemEmptyMessage = computed(() => (typedItem.value.trim().length < MIN_SEARCH_CHARS
    ? `Type at least ${MIN_SEARCH_CHARS} characters to see matches.`
    : "No item in the CPO list matches."));

const pickedItem = computed(() => (newLine.value.item && typeof newLine.value.item === "object" ? newLine.value.item : null));

watch(pickedItem, (item) => {
    newLine.value.uom = item?.units?.[0] ?? null;
    addProblem.value = "";
});

const addLine = () => {
    const item = pickedItem.value;
    const quantity = Number(newLine.value.quantity);
    const cost = Number(newLine.value.cost);

    if (!item || !newLine.value.uom) {
        addProblem.value = "Choose the item and its unit.";
        return;
    }
    if (!(quantity > 0) || !(cost > 0)) {
        addProblem.value = "Enter a quantity and a cost greater than 0.";
        return;
    }
    const sameLine = form.lines.find((line) => line.item_code === item.item_code && String(line.uom).toUpperCase() === String(newLine.value.uom).toUpperCase());
    if (sameLine) {
        addProblem.value = "This item and unit are already on the order. Change that line instead.";
        return;
    }

    form.lines.push({
        id: null,
        item_code: item.item_code,
        item_name: item.item_name,
        uom: newLine.value.uom,
        units: item.units,
        quantity,
        cost,
    });
    newLine.value = { item: null, uom: null, quantity: null, cost: null };
    itemSuggestions.value = [];
    addProblem.value = "";
};

const removeLine = (index) => form.lines.splice(index, 1);

// --- Totals ---
const money = new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 4 });
const lineTotal = (line) => (Number(line.quantity) || 0) * (Number(line.cost) || 0);
const overallTotal = computed(() => form.lines.reduce((sum, line) => sum + lineTotal(line), 0));

const lineError = (index, field) => form.errors[`lines.${index}.${field}`];

// --- Save ---
const save = () => {
    form
        .transform((data) => ({
            ...data,
            lines: data.lines.map(({ id, item_code, uom, quantity, cost }) => ({ id, item_code, uom, quantity, cost })),
        }))
        .put(route("cpo-orders.update", props.order.order_number), { preserveScroll: true });
};

const cancel = () => router.get(route("cpo-orders.index"));
</script>

<template>
    <Head :title="`Edit CPO Order ${order.order_number}`" />

    <Layout :heading="`Edit CPO Order #${order.order_number}`">
        <div class="space-y-6">
            <p class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-800">
                This order is waiting in <strong>Mass Orders Approval</strong> and can be edited until its last approval.
                <template v-if="editDeadline"> The edit cutoff for it is <strong>{{ editDeadline }}</strong>.</template>
            </p>

            <p v-if="form.errors.error" class="rounded-md bg-red-100 p-4 text-sm font-semibold text-red-800">{{ form.errors.error }}</p>

            <!-- Order details -->
            <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Order Details</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ordering Template</label>
                        <input type="text" value="CPO" disabled class="flex h-10 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label for="store" class="block text-sm font-medium text-gray-700 mb-1">Store <span class="text-red-500">*</span></label>
                        <AutoComplete
                            v-model="store"
                            inputId="store"
                            :suggestions="storeSuggestions"
                            optionLabel="label"
                            :minLength="1"
                            forceSelection
                            showEmptyMessage
                            :emptySearchMessage="`Type at least ${MIN_SEARCH_CHARS} characters of the store name or code.`"
                            :placeholder="`Type at least ${MIN_SEARCH_CHARS} characters to search your stores`"
                            :invalid="!!form.errors.store_branch_id"
                            fluid
                            @complete="searchStores"
                        />
                        <p class="mt-1 text-xs text-gray-500">Only your stores set up to order CPO. A store change gives the order a new order number.</p>
                        <p v-if="form.errors.store_branch_id" class="mt-1 text-sm text-red-600">{{ form.errors.store_branch_id }}</p>
                    </div>

                    <div>
                        <label for="order_date" class="block text-sm font-medium text-gray-700 mb-1">Delivery Date <span class="text-red-500">*</span></label>
                        <input
                            id="order_date"
                            v-model="form.order_date"
                            type="date"
                            :min="minDate"
                            class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm"
                        />
                        <p v-if="closedThroughDate" class="mt-1 text-xs text-gray-500">
                            Dates up to {{ formatDate(closedThroughDate) }} are closed for this store: its month end count is final approved.
                        </p>
                        <p v-if="form.errors.order_date" class="mt-1 text-sm text-red-600">{{ form.errors.order_date }}</p>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Vendor Name <span class="text-xs text-gray-400">(optional)</span></label>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <div class="flex-1 min-w-0">
                                <CpoVendorPicker v-model="vendor" :invalid="!!form.errors.cpo_vendor_id" />
                            </div>
                            <div class="flex gap-2">
                                <Button type="button" severity="secondary" outlined @click="openVendorDialog(false)">
                                    <Plus class="size-4 mr-1" /> Add New
                                </Button>
                                <Button type="button" severity="secondary" outlined :disabled="!vendor" @click="openVendorDialog(true)">
                                    <Pencil class="size-4 mr-1" /> Edit
                                </Button>
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Leave it blank if the vendor is not known yet: the store enters it in Inbound Orders when it receives the order.</p>
                        <p v-if="form.errors.cpo_vendor_id" class="mt-1 text-sm text-red-600">{{ form.errors.cpo_vendor_id }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">TIN # <span class="text-xs text-gray-400">(optional)</span></label>
                        <input type="text" :value="shownVendor ? (shownVendor.tin || 'None') : ''" disabled placeholder="Filled from the vendor" class="flex h-10 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Address <span class="text-xs text-gray-400">(optional)</span></label>
                        <input type="text" :value="shownVendor?.address ?? ''" disabled placeholder="Filled from the vendor" class="flex h-10 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm" />
                    </div>
                    <p v-if="vendor && vendorUnchanged" class="md:col-span-2 -mt-4 text-xs text-gray-500">
                        The order keeps the vendor details it was placed with. Choosing another vendor takes that vendor's details.
                    </p>
                </div>
            </div>

            <!-- Items -->
            <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Items</h2>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end mb-2">
                    <div class="md:col-span-5">
                        <label for="new_item" class="block text-sm font-medium text-gray-700 mb-1">Item</label>
                        <AutoComplete
                            v-model="newLine.item"
                            inputId="new_item"
                            :suggestions="itemSuggestions"
                            :optionLabel="(item) => `${item.item_name} (${item.item_code})`"
                            :minLength="1"
                            :delay="SEARCH_DELAY_MS"
                            :loading="searchingItems"
                            forceSelection
                            showEmptyMessage
                            :emptySearchMessage="itemEmptyMessage"
                            :placeholder="`Type at least ${MIN_SEARCH_CHARS} characters of the item name or code`"
                            fluid
                            @complete="searchItems"
                            @clear="typedItem = ''"
                        >
                            <template #option="{ option }">
                                <div class="flex flex-col">
                                    <span class="font-medium">{{ option.item_name }}</span>
                                    <span class="text-xs text-gray-500">{{ option.item_code }} · {{ option.units.join(', ') }}</span>
                                </div>
                            </template>
                        </AutoComplete>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
                        <Select v-model="newLine.uom" :options="pickedItem?.units ?? []" :disabled="!pickedItem" placeholder="Unit" class="w-full" />
                    </div>
                    <div class="md:col-span-2">
                        <label for="new_quantity" class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                        <input id="new_quantity" v-model="newLine.quantity" type="number" min="0" step="any" class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label for="new_cost" class="block text-sm font-medium text-gray-700 mb-1">Cost</label>
                        <input id="new_cost" v-model="newLine.cost" type="number" min="0" step="any" class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm" />
                    </div>
                    <div class="md:col-span-1">
                        <Button type="button" class="w-full" @click="addLine"><Plus class="size-4 mr-1" /> Add</Button>
                    </div>
                </div>
                <p class="mb-4 text-xs text-gray-500">The <strong>Cost</strong> is the price of one unit as shown on the invoice or receipt.</p>
                <p v-if="addProblem" class="mb-4 text-sm text-red-600">{{ addProblem }}</p>
                <p v-if="form.errors.lines" class="mb-4 text-sm text-red-600">{{ form.errors.lines }}</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-900">Item Code</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-900">Item Name</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-900">Unit</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-900">Quantity</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-900">Cost</th>
                                <th class="px-3 py-2 text-right font-medium text-gray-900">Total Cost</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!form.lines.length">
                                <td colspan="7" class="px-3 py-4 text-center text-gray-500">No items. Add at least one item.</td>
                            </tr>
                            <tr v-for="(line, index) in form.lines" :key="line.id ?? `new-${line.item_code}-${line.uom}`" class="border-t align-top">
                                <td class="px-3 py-2">
                                    {{ line.item_code }}
                                    <p v-if="lineError(index, 'item_code')" class="mt-1 text-xs text-red-600">{{ lineError(index, 'item_code') }}</p>
                                </td>
                                <td class="px-3 py-2">{{ line.item_name }}</td>
                                <td class="px-3 py-2 min-w-28">
                                    <Select v-model="line.uom" :options="line.units" class="w-full" :invalid="!!lineError(index, 'uom')" />
                                    <p v-if="lineError(index, 'uom')" class="mt-1 text-xs text-red-600">{{ lineError(index, 'uom') }}</p>
                                </td>
                                <td class="px-3 py-2 min-w-28">
                                    <input v-model="line.quantity" type="number" min="0" step="any" class="flex h-9 w-full rounded-md border border-gray-300 bg-white px-2 py-1 text-sm" />
                                    <p v-if="lineError(index, 'quantity')" class="mt-1 text-xs text-red-600">{{ lineError(index, 'quantity') }}</p>
                                </td>
                                <td class="px-3 py-2 min-w-28">
                                    <input v-model="line.cost" type="number" min="0" step="any" class="flex h-9 w-full rounded-md border border-gray-300 bg-white px-2 py-1 text-sm" />
                                    <p v-if="lineError(index, 'cost')" class="mt-1 text-xs text-red-600">{{ lineError(index, 'cost') }}</p>
                                </td>
                                <td class="px-3 py-2 text-right">{{ money.format(lineTotal(line)) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <button type="button" class="text-red-500" title="Remove" @click="removeLine(index)">
                                        <Trash2 class="size-5" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="form.lines.length">
                            <tr class="border-t font-semibold">
                                <td colspan="5" class="px-3 py-2 text-right">Overall Total</td>
                                <td class="px-3 py-2 text-right">{{ money.format(overallTotal) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <Button type="button" severity="secondary" outlined @click="cancel">Cancel</Button>
                <Button type="button" :disabled="form.processing" @click="save">Save Changes</Button>
            </div>
        </div>

        <CpoVendorDialog
            v-model:visible="vendorDialog.open"
            :vendor="vendorDialog.editing ? vendor : null"
            @saved="vendor = $event"
        />
    </Layout>
</template>
