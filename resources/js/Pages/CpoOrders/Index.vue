<script setup>
import { router, Head, useForm, usePage } from "@inertiajs/vue3";
import Dialog from "primevue/dialog";
import Select from "primevue/select";
import Button from "primevue/button";
import { ref, watch, computed } from "vue";
import axios from "axios";
import { Download, Upload, Eye, Plus, Pencil } from "lucide-vue-next";
import { throttle } from "lodash";
import { useAuth } from "@/composables/useAuth";
import { useSelectOptions } from "@/composables/useSelectOptions";
import { useToast } from "@/composables/useToast";
import CpoVendorPicker from "@/components/CpoVendorPicker.vue";

const props = defineProps({
    orders: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    currentDate: { type: String, required: true },
    // Last date closed for every CPO store of the user (final approved month end counts).
    closedForAllStores: { type: String, default: null },
    hasCpoStores: { type: Boolean, default: false },
    // The CPO ordering template is assigned to the user; placing an order needs it.
    cpoAssigned: { type: Boolean, default: false },
});

const { hasAccess } = useAuth();
const { toast } = useToast();
const { options: branchesOptions } = useSelectOptions(props.branches);

// --- Flash notification ---
const flash = computed(() => usePage().props.flash);
const flashMessageVisible = ref(false);

watch(flash, (newFlash) => {
    if (newFlash && newFlash.message) {
        flashMessageVisible.value = true;
        setTimeout(() => (flashMessageVisible.value = false), 30000);
    }
}, { deep: true, immediate: true });

const notificationType = computed(() => {
    if (!flash.value?.message) return null;
    if (flash.value.success === false) return "error";
    if (flash.value.skipped_stores?.length > 0) return "warning";
    return "success";
});

// --- Create dialog ---
const isModalVisible = ref(false);

const form = useForm({
    order_date: null,
    cpo_vendor_id: null,
    mass_order_file: null,
});

const vendor = ref(null);
watch(vendor, (value) => {
    form.cpo_vendor_id = value?.id ?? null;
});

const excelInput = ref(null);

const resetCreate = () => {
    form.reset();
    form.clearErrors();
    vendor.value = null;
    if (excelInput.value) excelInput.value.value = "";
};

watch(isModalVisible, (open) => {
    if (!open) setTimeout(resetCreate, 200);
});

// A date inside a month end count that is final approved for every CPO store cannot be picked.
const minDate = computed(() => {
    if (!props.closedForAllStores) return null;
    const next = new Date(props.closedForAllStores + "T00:00:00");
    next.setDate(next.getDate() + 1);
    return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, "0")}-${String(next.getDate()).padStart(2, "0")}`;
});

const canUpload = computed(() => form.order_date && form.cpo_vendor_id && form.mass_order_file);

const submitUpload = () => {
    form.post(route("cpo-orders.upload"), {
        forceFormData: true,
        onSuccess: () => {
            isModalVisible.value = false;
        },
    });
};

// Problems with the rows of the file, one message each.
const rowProblems = computed(() => Object.entries(form.errors)
    .filter(([key]) => key.startsWith("rows."))
    .map(([, message]) => message));

// --- Vendor add / edit: no delete ---
const vendorDialog = ref({ open: false, editing: false });
const vendorForm = ref({ name: "", tin: "", address: "" });
const vendorErrors = ref({});
const savingVendor = ref(false);

const openVendorDialog = (editing) => {
    vendorErrors.value = {};
    vendorForm.value = editing && vendor.value
        ? { name: vendor.value.name, tin: vendor.value.tin ?? "", address: vendor.value.address }
        : { name: "", tin: "", address: "" };
    vendorDialog.value = { open: true, editing: editing && !!vendor.value };
};

const saveVendor = async () => {
    savingVendor.value = true;
    vendorErrors.value = {};
    try {
        const { data } = vendorDialog.value.editing
            ? await axios.put(route("cpo-orders.vendors.update", vendor.value.id), vendorForm.value)
            : await axios.post(route("cpo-orders.vendors.store"), vendorForm.value);
        vendor.value = data;
        toast.add({ severity: "success", summary: "Vendor saved", detail: data.name, life: 3000 });
        vendorDialog.value.open = false;
    } catch (error) {
        if (error.response?.status === 422) {
            vendorErrors.value = Object.fromEntries(Object.entries(error.response.data.errors || {}).map(([key, list]) => [key, list[0]]));
        } else {
            toast.add({ severity: "error", summary: "Error", detail: "The vendor could not be saved.", life: 5000 });
        }
    } finally {
        savingVendor.value = false;
    }
};

// --- Table and filters ---
const filterQuery = ref((props.filters.filterQuery || "all").toString());
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const branchId = ref(props.filters.branchId);
const search = ref(props.filters.search);

const performFilter = throttle(() => {
    router.get(
        route("cpo-orders.index"),
        { search: search.value, filterQuery: filterQuery.value, from: from.value, to: to.value, branchId: branchId.value },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}, 500);

watch([from, to, branchId, search, filterQuery], performFilter);

const statusBadgeColor = (status) => {
    switch (status?.toUpperCase()) {
        case "RECEIVED": return "bg-green-500 text-white";
        case "INCOMPLETE": return "bg-orange-500 text-white";
        case "PENDING": return "bg-yellow-500 text-white";
        case "COMMITTED": return "bg-blue-500 text-white";
        case "REJECTED": return "bg-red-500 text-white";
        default: return "bg-gray-500 text-white";
    }
};

const statusLabel = (status) => {
    if (!status) return "N/A";
    const upper = status.toUpperCase();
    return upper === "INCOMPLETE" ? "RECEIVED" : upper.replace("_", " ");
};

const formatDisplayDate = (dateString) => {
    if (!dateString || !dateString.includes("-")) return "N/A";
    const [year, month, day] = dateString.substring(0, 10).split("-");
    const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    return `${monthNames[parseInt(month, 10) - 1]} ${parseInt(day, 10)}, ${year}`;
};

const formatDisplayDateTime = (dateString) => {
    if (!dateString) return "N/A";
    const [datePart, timePart] = dateString.split(" ");
    const [year, month, day] = datePart.split("-");
    const [hourStr, minute] = (timePart || "00:00").split(":");
    let hour = parseInt(hourStr, 10);
    const ampm = hour >= 12 ? "P.M." : "A.M.";
    if (hour > 12) hour -= 12;
    if (hour === 0) hour = 12;
    return `${parseInt(month, 10)}/${parseInt(day, 10)}/${year} ${hour}:${minute} ${ampm}`;
};

const showOrder = (orderNumber) => router.get(route("cpo-orders.show", orderNumber));

// An order placed in Mass Orders before CPO Orders existed recorded no vendor; it shows its
// ordering template (CPO), as Mass Orders did.
const vendorName = (order) => order.cpo_vendor_name || order.supplier?.name || "N/A";

// The store uploads the invoice / receipt in Inbound Orders when it receives the order.
const invoiceLabel = (order) => order.cpo_attachments_count > 0 ? `${order.cpo_attachments_count} file(s)` : "Not uploaded yet";
</script>

<template>
    <Head title="CPO Orders" />

    <Layout
        heading="CPO Orders"
        :hasButton="hasAccess('create cpo orders') && cpoAssigned"
        buttonName="Create New CPO Order"
        :handleClick="() => (isModalVisible = true)"
    >
        <p v-if="hasAccess('create cpo orders') && !cpoAssigned" class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
            The CPO ordering template is not assigned to you, so you can see CPO orders but not place one. Ask your administrator to assign it in Users.
        </p>

        <div v-if="flash.message && flashMessageVisible" class="mb-4 p-4 rounded-md" :class="{
            'bg-green-100 text-green-800': notificationType === 'success',
            'bg-yellow-100 text-yellow-800': notificationType === 'warning',
            'bg-red-100 text-red-800': notificationType === 'error',
        }">
            <p class="font-semibold">{{ flash.message }}</p>
            <ul v-if="flash.skipped_stores && flash.skipped_stores.length" class="mt-2 list-disc list-inside text-sm">
                <li v-for="skipped in flash.skipped_stores" :key="skipped.brand_code">
                    <strong>{{ skipped.brand_code }}:</strong> {{ skipped.reason }}
                </li>
            </ul>
        </div>

        <Dialog v-model:visible="isModalVisible" modal header="Create New CPO Order" :style="{ width: '65rem', maxWidth: '95vw' }">
            <div class="w-full p-2 sm:p-4 space-y-6">
                <p v-if="!hasCpoStores" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    None of your stores is set up to order CPO. Ask the office to add CPO to your store's delivery schedule.
                </p>

                <!-- Step 1: Order details -->
                <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                    <div class="flex items-center mb-4">
                        <span class="flex items-center justify-center size-8 rounded-full bg-blue-600 text-white font-bold text-lg mr-4">1</span>
                        <h2 class="text-xl font-semibold text-gray-800">Select Order Details</h2>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Ordering Template</label>
                            <input type="text" value="CPO" disabled class="flex h-10 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm" />
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
                            <p v-if="closedForAllStores" class="mt-1 text-xs text-gray-500">
                                Dates up to {{ formatDisplayDate(closedForAllStores) }} are closed: the month end count is final approved.
                            </p>
                            <p v-if="form.errors.order_date" class="mt-1 text-sm text-red-600">{{ form.errors.order_date }}</p>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Vendor Name <span class="text-red-500">*</span></label>
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
                            <p v-if="form.errors.cpo_vendor_id" class="mt-1 text-sm text-red-600">{{ form.errors.cpo_vendor_id }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">TIN # <span class="text-xs text-gray-400">(optional)</span></label>
                            <input type="text" :value="vendor ? (vendor.tin || 'None') : ''" disabled placeholder="Filled from the vendor" class="flex h-10 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Address <span class="text-red-500">*</span></label>
                            <input type="text" :value="vendor?.address ?? ''" disabled placeholder="Filled from the vendor" class="flex h-10 w-full rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm" />
                        </div>
                    </div>
                </div>

                <template v-if="form.order_date">
                    <!-- Step 2: Download -->
                    <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                        <div class="flex items-center mb-4">
                            <span class="flex items-center justify-center size-8 rounded-full bg-blue-600 text-white font-bold text-lg mr-4">2</span>
                            <h2 class="text-xl font-semibold text-gray-800">Download Template</h2>
                        </div>
                        <p class="text-gray-600 mb-5">
                            The template lists the CPO items and one column per store. Enter the quantity bought under each store, and the
                            <strong>Cost</strong> of one unit as shown on the invoice or receipt. Every line with a quantity needs its Item Code, Unit and Cost.
                        </p>
                        <a
                            :href="route('cpo-orders.download-template', { order_date: form.order_date })"
                            class="inline-flex items-center justify-center w-full px-4 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700"
                        >
                            <Download class="mr-2 size-5" />
                            Download Order Template
                        </a>
                    </div>

                    <!-- Step 3: Upload -->
                    <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                        <div class="flex items-center mb-4">
                            <span class="flex items-center justify-center size-8 rounded-full bg-blue-600 text-white font-bold text-lg mr-4">3</span>
                            <h2 class="text-xl font-semibold text-gray-800">Upload Completed File</h2>
                        </div>
                        <form @submit.prevent="submitUpload" class="space-y-5">
                            <div>
                                <label for="mass_order_file" class="block text-sm font-medium text-gray-700">Excel File <span class="text-red-500">*</span></label>
                                <input
                                    id="mass_order_file"
                                    ref="excelInput"
                                    type="file"
                                    accept=".xlsx,.xls"
                                    @input="form.mass_order_file = $event.target.files[0]"
                                    class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-gray-100 file:text-gray-700 hover:file:bg-gray-200"
                                />
                                <p v-if="form.errors.mass_order_file" class="mt-2 text-sm text-red-600">{{ form.errors.mass_order_file }}</p>
                                <ul v-if="rowProblems.length" class="mt-2 list-disc list-inside text-sm text-red-600 space-y-0.5">
                                    <li v-for="(problem, index) in rowProblems" :key="index">{{ problem }}</li>
                                </ul>
                            </div>

                            <p class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-800">
                                The invoice / receipt is not uploaded here. The store uploads it in <strong>Inbound Orders</strong> when it
                                receives the order, and the order cannot be received without it.
                            </p>

                            <div class="flex justify-end">
                                <Button type="submit" :disabled="!canUpload || form.processing">
                                    <Upload class="mr-2 size-5" />
                                    Upload and Process
                                </Button>
                            </div>
                        </form>
                    </div>
                </template>
            </div>
        </Dialog>

        <Dialog v-model:visible="vendorDialog.open" modal :header="vendorDialog.editing ? 'Edit Vendor' : 'Add New Vendor'" :style="{ width: '32rem', maxWidth: '95vw' }">
            <form class="space-y-4" @submit.prevent="saveVendor">
                <div>
                    <label for="vendor_name" class="block text-sm font-medium text-gray-700 mb-1">Vendor Name <span class="text-red-500">*</span></label>
                    <input id="vendor_name" v-model="vendorForm.name" type="text" maxlength="255" class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm" />
                    <p v-if="vendorErrors.name" class="mt-1 text-sm text-red-600">{{ vendorErrors.name }}</p>
                </div>
                <div>
                    <label for="vendor_tin" class="block text-sm font-medium text-gray-700 mb-1">TIN # <span class="text-xs text-gray-400">(optional)</span></label>
                    <input id="vendor_tin" v-model="vendorForm.tin" type="text" maxlength="50" class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm" />
                    <p v-if="vendorErrors.tin" class="mt-1 text-sm text-red-600">{{ vendorErrors.tin }}</p>
                </div>
                <div>
                    <label for="vendor_address" class="block text-sm font-medium text-gray-700 mb-1">Address <span class="text-red-500">*</span></label>
                    <textarea id="vendor_address" v-model="vendorForm.address" rows="3" maxlength="500" class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm"></textarea>
                    <p v-if="vendorErrors.address" class="mt-1 text-sm text-red-600">{{ vendorErrors.address }}</p>
                </div>
                <p v-if="vendorDialog.editing" class="text-xs text-gray-500">
                    The change applies to CPO orders placed from now on. Orders already placed keep the details they were placed with.
                </p>
                <div class="flex justify-end gap-2">
                    <Button type="button" severity="secondary" outlined @click="vendorDialog.open = false">Cancel</Button>
                    <Button type="submit" :disabled="savingVendor">Save Vendor</Button>
                </div>
            </form>
        </Dialog>

        <FilterTab>
            <FilterTabButton label="All" filter="all" :currentFilter="filterQuery" @click="filterQuery = 'all'" />
            <FilterTabButton label="Pending" filter="pending" :currentFilter="filterQuery" @click="filterQuery = 'pending'" />
            <FilterTabButton label="Committed" filter="committed" :currentFilter="filterQuery" @click="filterQuery = 'committed'" />
            <FilterTabButton label="Received" filter="received" :currentFilter="filterQuery" @click="filterQuery = 'received'" />
            <FilterTabButton label="Rejected" filter="rejected" :currentFilter="filterQuery" @click="filterQuery = 'rejected'" />
        </FilterTab>

        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input v-model="search" id="search" type="text" placeholder="Search for order number or vendor" class="pl-10 sm:max-w-full max-w-64" />
                </SearchBar>
                <DivFlexCenter class="gap-5">
                    <Popover>
                        <PopoverTrigger> <Filter /> </PopoverTrigger>
                        <PopoverContent>
                            <div class="flex justify-end">
                                <Button @click="() => { from = null; to = null; branchId = null; search = null; }" variant="link" class="text-end text-red-500 text-xs">
                                    Reset Filter
                                </Button>
                            </div>
                            <label class="text-xs">From Delivery Date</label>
                            <Input type="date" v-model="from" />
                            <label class="text-xs">To Delivery Date</label>
                            <Input type="date" v-model="to" />
                            <label class="text-xs">Store</label>
                            <Select
                                v-model="branchId"
                                filter
                                append-to="self"
                                :options="branchesOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Select a store"
                                class="w-full"
                            />
                        </PopoverContent>
                    </Popover>
                </DivFlexCenter>
            </TableHeader>

            <div class="hidden md:block">
                <Table>
                    <TableHead>
                        <TH>Id</TH>
                        <TH>Vendor</TH>
                        <TH>Store</TH>
                        <TH>Order #</TH>
                        <TH>Delivery Date</TH>
                        <TH>Order Placed Date</TH>
                        <TH>Invoice / Receipt</TH>
                        <TH>Order Status</TH>
                        <TH>Actions</TH>
                    </TableHead>
                    <TableBody>
                        <tr v-if="!orders.data || orders.data.length === 0">
                            <td colspan="9" class="text-center py-4">No CPO orders found.</td>
                        </tr>
                        <tr v-for="order in orders.data" :key="order.id">
                            <TD>{{ order.id }}</TD>
                            <TD>{{ vendorName(order) }}</TD>
                            <TD>{{ order.store_branch?.name ?? "N/A" }}</TD>
                            <TD>{{ order.order_number }}</TD>
                            <TD>{{ formatDisplayDate(order.order_date) }}</TD>
                            <TD>{{ formatDisplayDateTime(order.created_at) }}</TD>
                            <TD>{{ invoiceLabel(order) }}</TD>
                            <TD>
                                <Badge :class="statusBadgeColor(order.order_status)" class="font-bold">{{ statusLabel(order.order_status) }}</Badge>
                            </TD>
                            <TD>
                                <button @click="showOrder(order.order_number)" title="View">
                                    <Eye class="size-5" />
                                </button>
                            </TD>
                        </tr>
                    </TableBody>
                </Table>
            </div>

            <MobileTableContainer class="md:hidden">
                <MobileTableRow v-for="order in orders.data" :key="order.id">
                    <MobileTableHeading :title="order.order_number">
                        <button @click="showOrder(order.order_number)">
                            <Eye class="size-5" />
                        </button>
                    </MobileTableHeading>
                    <LabelXS>Vendor: {{ vendorName(order) }}</LabelXS>
                    <LabelXS>Status: <span :class="statusBadgeColor(order.order_status)" class="font-semibold p-1 rounded text-white">{{ statusLabel(order.order_status) }}</span></LabelXS>
                    <LabelXS>Store: {{ order.store_branch?.name ?? "N/A" }}</LabelXS>
                    <LabelXS>Delivery Date: {{ formatDisplayDate(order.order_date) }}</LabelXS>
                    <LabelXS>Invoice / Receipt: {{ invoiceLabel(order) }}</LabelXS>
                </MobileTableRow>
            </MobileTableContainer>

            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>
