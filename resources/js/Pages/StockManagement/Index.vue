<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { usePage, router, useForm } from "@inertiajs/vue3";

import { throttle, update } from "lodash";
import { ref, watch, computed } from "vue"; // Added 'watch' and 'computed' to imports
import { useConfirm } from "primevue/useconfirm";
import { useToast } from "@/Composables/useToast";
const confirm = useConfirm();
const { toast } = useToast();
const { products, branches, costCenters, storeSummary } = defineProps({
    products: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
    costCenters: {
        type: Object,
        required: true,
    },
    storeSummary: {
        type: Object,
        default: null,
    },
});

const { options: branchesOptions } = useSelectOptions(branches);
const { options: costCentersOptions } = useSelectOptions(costCenters);

const branchId = ref(
    usePage().props.filters.branchId || branchesOptions.value[0].value
);

let search = ref(usePage().props.filters.search);

watch(branchId, (newValue) => {
    console.log(usePage());
    router.get(
        route("stock-management.index"),
        {
            branchId: newValue,
            search: search.value,
            page: 1,
        },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        }
    );
});

watch(
    search,
    throttle(function (value) {
        router.get(
            route("stock-management.index"),
            {
                search: value,
                branchId: branchId.value,
                page: 1,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

const isLogUsageModalOpen = ref(false);
const isAddQuantityModalOpen = ref(false);

const form = useForm({
    id: null,
    store_branch_id: null,
    cost_center_id: null,
    quantity: null,
    unit_cost: null,
    transaction_date: null,
    remarks: null,
});
watch(isLogUsageModalOpen, (value) => {
    if (!value) {
        form.reset();
        form.clearErrors();
    }
});

watch(isAddQuantityModalOpen, (value) => {
    if (!value) {
        form.reset();
        form.clearErrors();
    }
});
const openLogUsageModal = (id) => {
    form.id = id;
    form.store_branch_id = branchId.value;
    isLogUsageModalOpen.value = true;
};

const openAddQuantityModal = (id) => {
    form.id = id;
    form.store_branch_id = branchId.value;
    isAddQuantityModalOpen.value = true;
};

const logUsage = () => {
    form.post(route("stock-management.log-usage"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Usage logged Successfully.",
                life: 5000,
            });
            isLogUsageModalOpen.value = false;
        },
        onError: () => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occurred while trying to log the usage.",
                life: 5000,
            });
        },
    });
};

const addQuantity = () => {
    form.post(route("stock-management.add-quantity"), {
        onSuccess: () => {
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Quantity added Successfully.", // Changed detail message for clarity
                life: 10000,
            });
            isAddQuantityModalOpen.value = false;
        },
        onError: () => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occurred while trying to add quantity.", // Changed detail message for clarity
                life: 10000,
            });
        },
    });
};

// Updated showDetails function for explicit parameter passing
const showDetails = (productId, selectedBranchId) => {
    router.get(
        route("stock-management.show", productId), // Product ID is the route parameter
        {
            branchId: selectedBranchId, // Branch ID is the query parameter
        },
        {}
    );
};

import { useAuth } from "@/Composables/useAuth";

const { hasAccess } = useAuth();

const exportRoute = computed(() =>
    route("stock-management.export", {
        search: search.value,
        branchId: branchId.value,
    })
);

const isUpdateModalVisible = ref(false);
const openUpdateModal = () => {
    isUpdateModalVisible.value = true;
};

const options = [
    {
        label: "Add Quantity",
        value: "add-quantity",
    },
    {
        label: "Log Usage",
        value: "log-usage",
    },
    {
        label: "SOH Update",
        value: "soh-update",
    },
];

const updateForm = useForm({
    action: null,
    branch: branchId.value,
    file: null,
});
const action = ref(null);
watch(
    () => updateForm.action,
    (value) => {
        console.log(value);
    }
);

const isLoading = ref(false);

const updateImport = () => {
    const routeLocation =
        updateForm.action == "add-quantity"
            ? "stock-management.import-add"
            : updateForm.action == "log-usage"
            ? "stock-management.import-log-usage"
            : "stock-management.import-soh-update";

    updateForm.branch = branchId.value;

    isLoading.value = true;

    axios
        .post(route(routeLocation), updateForm, {
            headers: {
                "Content-Type": "multipart/form-data",
            },
        })
        .then((response) => {
            console.log(response);
            // Check for any import errors
            if (response.data.errors && response.data.errors.length > 0) {
                // Show detailed error messages
                response.data.errors.forEach((error) => {
                    toast.add({
                        severity: "error",
                        summary: "Import Error",
                        detail: error,
                        life: 5000,
                    });
                });

                // If some data was imported successfully
                if (
                    response.data.imported &&
                    response.data.imported.length > 0
                ) {
                    toast.add({
                        severity: "warning",
                        summary: "Partial Import",
                        detail: `Successfully imported ${response.data.imported.length} rows`,
                        life: 3000,
                    });
                }
            } else {
                // Successful import
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: "Stock Updated Successfully.",
                    life: 3000,
                });
            }
        })
        .catch((err) => {
            console.log(err);
            // Handle network or server errors
            toast.add({
                severity: "error",
                summary: "Error",
                detail:
                    err.response?.data?.message ||
                    "An unexpected error occurred during import.",
                life: 3000,
            });
        })
        .finally(() => {
            isUpdateModalVisible.value = false;
            isLoading.value = false;
        });
};

watch(isUpdateModalVisible, (value) => {
    if (!value) {
        updateForm.reset();
        updateForm.clearErrors();
    }
});
// Helper function to log product SOH and return formatted value
const getProductSOHForDisplay = (product) => {
    console.log(`Product ID: ${product.id}, SOH: ${product.stock_on_hand}, Name: ${product.name}`);
    return parseFloat(product.stock_on_hand).toFixed(2);
};

// Helper function to log product Recorded Used and return formatted value
const getProductRecordedUsedForDisplay = (product) => {
    console.log(`Product ID: ${product.id}, Recorded Used: ${product.recorded_used}, Name: ${product.name}`);
    return parseFloat(product.recorded_used).toFixed(2);
};

// Helper function to format Total BaseUOM SOH
const getTotalBaseUOMSOH = (product) => {
    return parseFloat(product.total_base_uom_soh || 0).toFixed(2);
};
</script>
<template>
    <Dialog v-model:open="isUpdateModalVisible">
        <DialogContent class="sm:max-w-[600px]">
            <DialogHeader>
                <DialogTitle>Update Stock</DialogTitle>
                <DialogDescription>
                    Please input all the required fields.
                </DialogDescription>
            </DialogHeader>
            <InputContainer>
                <Label>Action</Label>
                <SelectShad v-model="updateForm.action">
                    <SelectTrigger>
                        <SelectValue placeholder="Select from options" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectLabel>Options</SelectLabel>
                            <SelectItem
                                v-for="variant in options"
                                :value="variant.value"
                            >
                                {{ variant.label }}
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </SelectShad>
            </InputContainer>
            <InputContainer>
                <Label>Store Branch</Label>
                <Select
                    filter
                    class="min-w-72"
                    placeholder="Select a Supplier"
                    :options="branchesOptions"
                    optionLabel="label"
                    optionValue="value"
                    v-model="branchId"
                    disabled
                />
            </InputContainer>
            <InputContainer>
                <Label>Excel File</Label>
                <Input
                    type="file"
                    @input="updateForm.file = $event.target.files[0]"
                />
            </InputContainer>
            <InputContainer>
                <LabelXS>Accepted Excel File Format: </LabelXS>
                <a
                    :href="route('stock-management.export-add')"
                    class="text-xs text-blue-500 underline"
                    >Add Quantity</a
                >

                <a
                    :href="route('stock-management.export-log')"
                    class="text-xs text-blue-500 underline"
                    >Log Usage</a
                >
                <a
                    :href="route('stock-management.export-soh')"
                    class="text-xs text-blue-500 underline"
                    >SOH Update</a
                >
            </InputContainer>
            <DivFlexCenter class="justify-end">
                <Button :disabled="isLoading" @click="updateImport"
                    >Submit
                    <span class="ml-1" v-if="isLoading"><Loading /></span
                ></Button>
            </DivFlexCenter>
        </DialogContent>
    </Dialog>

    <Layout
        heading="Stock Management"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <TableContainer>
            <DivFlexCenter class="justify-between sm:flex-row flex-col gap-3">
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search..."
                        v-model="search"
                    />
                </SearchBar>
                <DivFlexCenter class="gap-3">
                    <Select
                        filter
                        class="min-w-72"
                        placeholder="Select a Supplier"
                        :options="branchesOptions"
                        optionLabel="label"
                        optionValue="value"
                        v-model="branchId"
                    >
                    </Select>
                    <Button @click="openUpdateModal">Update Stock</Button>
                </DivFlexCenter>
            </DivFlexCenter>

            <!-- Dashboard Summary Card -->
            <div v-if="storeSummary" class="mb-8">
                <!-- Main Dashboard Card -->
                <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-50 via-white to-indigo-50 border border-gray-200/50 shadow-xl hover:shadow-2xl transition-all duration-500 ease-out">
                    <!-- Decorative Background Elements -->
                    <div class="absolute inset-0 bg-gradient-to-r from-blue-400/5 to-purple-400/5 backdrop-blur-3xl"></div>
                    <div class="absolute top-0 right-0 w-64 h-64 bg-gradient-to-br from-blue-400/10 to-purple-400/10 rounded-full blur-3xl transform translate-x-1/2 -translate-y-1/2"></div>
                    <div class="absolute bottom-0 left-0 w-48 h-48 bg-gradient-to-tr from-green-400/10 to-blue-400/10 rounded-full blur-2xl transform -translate-x-1/4 translate-y-1/4"></div>

                    <div class="relative p-8 md:p-10">
                        <!-- Store Header -->
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8">
                            <div>
                                <h2 class="text-2xl md:text-3xl font-bold bg-gradient-to-r from-gray-900 via-blue-800 to-purple-800 bg-clip-text text-transparent mb-2">
                                    {{ storeSummary.store }}
                                </h2>
                                <div class="flex items-center gap-2">
                                    <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                                    <span class="text-sm font-medium text-gray-600">Primary BaseUOM Analysis</span>
                                </div>
                            </div>
                            <div class="mt-4 md:mt-0 flex items-center gap-2 bg-white/60 backdrop-blur-sm rounded-full px-4 py-2 border border-gray-200/50">
                                <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                </div>
                                <span class="text-sm font-semibold text-gray-700">{{ storeSummary.base_uom }}</span>
                            </div>
                        </div>

                        <!-- Primary Item Display -->
                        <div class="mb-8 p-4 bg-white/40 backdrop-blur-sm rounded-xl border border-gray-200/50">
                            <div class="flex items-center gap-3 mb-2">
                                <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-sm font-medium text-gray-600">Primary Item</h3>
                                    <p class="text-lg font-semibold text-gray-900 truncate">{{ storeSummary.primary_item.formatted_name }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Metrics Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <!-- SOH Metric -->
                            <div class="relative group">
                                <div class="absolute inset-0 bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl transform scale-95 group-hover:scale-100 transition-transform duration-300 opacity-10"></div>
                                <div class="relative bg-white/60 backdrop-blur-sm rounded-xl p-6 border border-blue-200/50 hover:border-blue-300/70 transition-all duration-300">
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                            </svg>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <div class="w-2 h-2 bg-green-500 rounded-full"></div>
                                            <span class="text-xs font-medium text-green-600">Active</span>
                                        </div>
                                    </div>
                                    <h4 class="text-sm font-medium text-gray-600 mb-1">Total SOH</h4>
                                    <p class="text-2xl font-bold text-gray-900">{{ parseFloat(storeSummary.total_soh).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) }}</p>
                                    <p class="text-xs text-gray-500 mt-1">{{ storeSummary.item_count }} items</p>
                                </div>
                            </div>

                            <!-- BaseUOM SOH Metric -->
                            <div class="relative group">
                                <div class="absolute inset-0 bg-gradient-to-r from-purple-500 to-purple-600 rounded-xl transform scale-95 group-hover:scale-100 transition-transform duration-300 opacity-10"></div>
                                <div class="relative bg-white/60 backdrop-blur-sm rounded-xl p-6 border border-purple-200/50 hover:border-purple-300/70 transition-all duration-300">
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>
                                            </svg>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <div class="w-2 h-2 bg-purple-500 rounded-full"></div>
                                            <span class="text-xs font-medium text-purple-600">{{ storeSummary.base_uom }}</span>
                                        </div>
                                    </div>
                                    <h4 class="text-sm font-medium text-gray-600 mb-1">Total BaseUOM SOH</h4>
                                    <p class="text-2xl font-bold text-gray-900">{{ parseFloat(storeSummary.total_base_uom_soh).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) }}</p>
                                    <p class="text-xs text-gray-500 mt-1">Base units</p>
                                </div>
                            </div>

                            <!-- Items Count Metric -->
                            <div class="relative group">
                                <div class="absolute inset-0 bg-gradient-to-r from-green-500 to-green-600 rounded-xl transform scale-95 group-hover:scale-100 transition-transform duration-300 opacity-10"></div>
                                <div class="relative bg-white/60 backdrop-blur-sm rounded-xl p-6 border border-green-200/50 hover:border-green-300/70 transition-all duration-300">
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                            </svg>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                            <span class="text-xs font-medium text-blue-600">Current</span>
                                        </div>
                                    </div>
                                    <h4 class="text-sm font-medium text-gray-600 mb-1">Items Found</h4>
                                    <p class="text-2xl font-bold text-gray-900">{{ storeSummary.dashboard_stats.total_items.toLocaleString() }}</p>
                                    <p class="text-xs text-gray-500 mt-1">In {{ storeSummary.dashboard_stats.total_unique_base_uoms }} BaseUOMs</p>
                                </div>
                            </div>

                            <!-- Efficiency Metric -->
                            <div class="relative group">
                                <div class="absolute inset-0 bg-gradient-to-r from-amber-500 to-orange-600 rounded-xl transform scale-95 group-hover:scale-100 transition-transform duration-300 opacity-10"></div>
                                <div class="relative bg-white/60 backdrop-blur-sm rounded-xl p-6 border border-amber-200/50 hover:border-amber-300/70 transition-all duration-300">
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="w-12 h-12 bg-gradient-to-br from-amber-500 to-orange-600 rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                            </svg>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <div class="w-2 h-2 bg-amber-500 rounded-full"></div>
                                            <span class="text-xs font-medium text-amber-600">Live</span>
                                        </div>
                                    </div>
                                    <h4 class="text-sm font-medium text-gray-600 mb-1">Coverage</h4>
                                    <p class="text-2xl font-bold text-gray-900">{{ Math.round((storeSummary.total_base_uom_soh / Math.max(storeSummary.dashboard_stats.overall_total_base_uom_soh, 1)) * 100) }}%</p>
                                    <p class="text-xs text-gray-500 mt-1">Of total inventory</p>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Stats Bar -->
                        <div class="mt-8 p-4 bg-gradient-to-r from-gray-50 to-blue-50 rounded-xl border border-gray-200/50">
                            <div class="flex flex-wrap items-center justify-between gap-4">
                                <div class="flex items-center gap-6">
                                    <div class="flex items-center gap-2">
                                        <div class="w-3 h-3 bg-blue-500 rounded-full"></div>
                                        <span class="text-sm text-gray-600">Total Items:</span>
                                        <span class="text-sm font-semibold text-gray-900">{{ storeSummary.dashboard_stats.total_items.toLocaleString() }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <div class="w-3 h-3 bg-purple-500 rounded-full"></div>
                                        <span class="text-sm text-gray-600">BaseUOMs:</span>
                                        <span class="text-sm font-semibold text-gray-900">{{ storeSummary.dashboard_stats.total_unique_base_uoms }}</span>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-500">
                                    Last updated: {{ new Date().toLocaleTimeString() }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <Table>
                <TableHead>
                    <TH>Name</TH>
                    <TH>Code</TH>
                    <TH>UOM</TH>
                    <TH>Alt UOM</TH> <!-- Added new column for Alt UOM -->
                    <TH>SOH</TH>
                    <TH>Total BaseUOM SOH</TH>
                    <TH>Recorded Used</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="product in products.data" :key="product.id">
                        <TD>{{ product.name }}</TD>
                        <TD>{{ product.inventory_code }}</TD>
                        <TD>{{ product.uom }}</TD>
                        <TD>{{ product.alt_uom }}</TD> <!-- Display Alt UOM -->
                        <TD>{{ getProductSOHForDisplay(product) }}</TD>
                        <TD>{{ getTotalBaseUOMSOH(product) }}</TD>
                        <TD>{{ getProductRecordedUsedForDisplay(product) }}</TD>
                        <TD>
                            <DivFlexCenter class="gap-3">
                                <ShowButton
                                    v-if="
                                        hasAccess(
                                            'view stock management history'
                                        )
                                    "
                                    @click="showDetails(product.id, branchId)"
                                />
                                <Button
                                    v-if="hasAccess('log stock usage')"
                                    @click="openLogUsageModal(product.id)"
                                    variant="link"
                                    class="text-xs text-orange-500"
                                    >Log Usage</Button
                                >
                                <Button
                                    v-if="hasAccess('add stock quantity')"
                                    @click="openAddQuantityModal(product.id)"
                                    variant="link"
                                    class="text-xs text-green-500"
                                    >Add Quantity</Button
                                >
                            </DivFlexCenter>
                        </TD>
                    </tr>
                </TableBody>
            </Table>

            <MobileTableContainer>
                <MobileTableRow v-for="product in products.data" :key="product.id">
                    <MobileTableHeading
                        :title="`${product.name} (${product.inventory_code})`"
                    >
                        <ShowButton
                            v-if="hasAccess('view stock management history')"
                            @click="showDetails(product.id, branchId)"
                        />
                    </MobileTableHeading>
                    <LabelXS>UOM: {{ product.uom }}</LabelXS>
                    <LabelXS>Alt UOM: {{ product.alt_uom }}</LabelXS> <!-- Display Alt UOM in mobile view -->
                    <LabelXS>SOH: {{ getProductSOHForDisplay(product) }}</LabelXS>
                    <LabelXS>Total BaseUOM SOH: {{ getTotalBaseUOMSOH(product) }}</LabelXS>
                    <LabelXS
                        >Estimated Used: {{ product.estimated_used }}
                        {{ product.ingredient_units }}</LabelXS
                    >
                    <LabelXS
                        >Recorded Used: {{ getProductRecordedUsedForDisplay(product) }}</LabelXS
                    >
                    <DivFlexCenter class="gap-3">
                        <Button
                            v-if="hasAccess('log stock usage')"
                            @click="openLogUsageModal(product.id)"
                            variant="link"
                            class="text-xs text-orange-500 p-0"
                            >Log Usage</Button
                        >
                        <Button
                            v-if="hasAccess('add stock quantity')"
                            @click="openAddQuantityModal(product.id)"
                            variant="link"
                            class="text-xs teMNaxt-green-500 p-0"
                            >Add Quantity</Button
                        >
                    </DivFlexCenter>
                </MobileTableRow>
            </MobileTableContainer>
            <Pagination :data="products" />
        </TableContainer>

        <Dialog v-model:open="isLogUsageModalOpen">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Log Usage</DialogTitle>
                    <DialogDescription>
                        Please input all the required fields.
                    </DialogDescription>
                </DialogHeader>
                <DivFlexCol class="gap-3">
                    <InputContainer>
                        <LabelXS>Store Branch</LabelXS>
                        <Select
                            disabled
                            filter
                            class="min-w-72"
                            placeholder="Select a Supplier"
                            :options="branchesOptions"
                            optionLabel="label"
                            optionValue="value"
                            v-model="form.store_branch_id"
                        >
                        </Select>
                        <FormError>{{ form.errors.store_branch_id }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Cost Center</LabelXS>
                        <SelectShad v-model="form.cost_center_id">
                            <SelectTrigger>
                                <SelectValue
                                    placeholder="Select from choices"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectItem
                                        v-for="costCenter in costCentersOptions"
                                        :value="costCenter.value"
                                    >
                                        {{ costCenter.label }}
                                    </SelectItem>
                                </SelectGroup>
                            </SelectContent>
                        </SelectShad>
                        <FormError>{{ form.errors.cost_center_id }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Quantity Used</LabelXS>
                        <Input type="number" v-model="form.quantity" />
                        <FormError>{{ form.errors.quantity }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Transaction Date</LabelXS>
                        <Input type="date" v-model="form.transaction_date" />
                        <FormError>{{
                            form.errors.transaction_date
                        }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Remarks</LabelXS>
                        <Textarea type="number" v-model="form.remarks" />
                        <FormError>{{ form.errors.remarks }}</FormError>
                    </InputContainer>
                </DivFlexCol>
                <DialogFooter class="justify-end">
                    <Button @click="logUsage">Submit</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="isAddQuantityModalOpen">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Add Quantity</DialogTitle>
                    <DialogDescription>
                        Please input all the required fields.
                    </DialogDescription>
                </DialogHeader>
                <DivFlexCol class="gap-3">
                    <InputContainer>
                        <LabelXS>Store Branch</LabelXS>
                        <Select
                            disabled
                            filter
                            class="min-w-72"
                            placeholder="Select a Supplier"
                            :options="branchesOptions"
                            optionLabel="label"
                            optionValue="value"
                            v-model="form.store_branch_id"
                        >
                        </Select>
                        <FormError>{{ form.errors.store_branch_id }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Quantity</LabelXS>
                        <Input type="number" v-model="form.quantity" />
                        <FormError>{{ form.errors.quantity }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Unit Cost</LabelXS>
                        <Input type="number" v-model="form.unit_cost" />
                        <FormError>{{ form.errors.unit_cost }}</FormError>
                    </InputContainer>

                    <InputContainer>
                        <LabelXS>Transaction Date</LabelXS>
                        <Input type="date" v-model="form.transaction_date" />
                        <FormError>{{
                            form.errors.transaction_date
                        }}</FormError>
                    </InputContainer>
                    <InputContainer>
                        <LabelXS>Remarks</LabelXS>
                        <Textarea type="number" v-model="form.remarks" />
                        <FormError>{{ form.errors.remarks }}</FormError>
                    </InputContainer>
                </DivFlexCol>
                <DialogFooter class="justify-end">
                    <Button @click="addQuantity">Submit</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
