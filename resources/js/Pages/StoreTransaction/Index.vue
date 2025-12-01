<script setup>
import { router, usePage, useForm } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Filter } from "lucide-vue-next";

const props = defineProps({
    transactions: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
    order_date: { // This prop will now be YYYY-MM-DD from the controller
        type: String,
        required: false,
    },
});

const createNewTransaction = () => {
    router.get(route("store-transactions.create"));
};

let search = ref(usePage().props.filters.search);

// CRITICAL FIX: Initialize 'from' from filters.from, 'to' from filters.to.
// The controller now ensures these are already YYYY-MM-DD or today's date.
let from = ref(usePage().props.filters.from);
let to = ref(usePage().props.filters.to);

// branchId is now passed directly from the route/filters
const branchId = usePage().props.filters.branchId;

// Custom dialog state management
const isFilterDialogOpen = ref(false);

// Custom dialog functions
const openFilterDialog = () => {
    isFilterDialogOpen.value = true;
};

const closeFilterDialog = () => {
    isFilterDialogOpen.value = false;
};

const handleEscapeKey = (event) => {
    if (event.key === 'Escape' && isFilterDialogOpen.value) {
        closeFilterDialog();
    }
};

const handleBackdropClick = (event) => {
    if (event.target === event.currentTarget) {
        closeFilterDialog();
    }
};

// Add and remove event listeners for escape key
onMounted(() => {
    document.addEventListener('keydown', handleEscapeKey);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscapeKey);
});


watch(from, (value) => {
    router.get(
        route("store-transactions.index"),
        {
            search: search.value,
            from: value,
            to: to.value,
            branchId: branchId,
            order_date: props.order_date,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

watch(to, (value) => {
    router.get(
        route("store-transactions.index"),
        {
            search: search.value,
            from: from.value,
            to: value,
            branchId: branchId,
            order_date: props.order_date,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

watch(
    search,
    throttle(function (value) {
        router.get(
            route("store-transactions.index"),
            {
                search: value,
                from: from.value,
                to: to.value,
                branchId: branchId,
                order_date: props.order_date,
            },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

const resetFilter = () => {
    // CRITICAL FIX: Reset to the initial order_date (formatted) or today's date
    from.value = props.order_date ?? new Date().toISOString().slice(0, 10);
    to.value = new Date().toISOString().slice(0, 10); // 'To' always resets to today
    search.value = null;
    router.get(
        route("store-transactions.index"),
        {
            search: search.value,
            from: from.value,
            to: to.value,
            branchId: branchId,
            order_date: props.order_date,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    );
};

const exportRoute = computed(() =>
    route("store-transactions.export", {
        search: search.value,
        branchId: branchId,
        from: from.value,
        to: to.value,
        order_date: props.order_date,
    })
);

const formatDisplayDate = (dateString) => {
    if (!dateString) {
        return 'N/a';
    }
    try {
        const date = new Date(dateString);
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const year = date.getFullYear();
        return `${month}/${day}/${year}`;
    } catch (e) {
        console.error("Error formatting date:", dateString, e);
        return dateString;
    }
};

// --- Import Functionality ---
const isImportModalVisible = ref(false);
const isLoading = ref(false);
const skippedItems = ref([]);
const createdCount = ref(0);
const persistentSkippedItemsMessage = ref('');

const importForm = useForm({
    store_transactions_file: null,
});

const openImportModal = () => {
    isImportModalVisible.value = true;
};

const closeImportModal = () => {
    isImportModalVisible.value = false;
    importForm.reset();
};

const importFile = () => {
    isLoading.value = true;
    
    // Ensure branchId defaults to 'all' if not present
    const currentBranchId = usePage().props.filters.branchId || 'all';
    
    importForm.post(route("store-transactions.import", {
        search: search.value,
        branchId: currentBranchId,
        from: from.value,
        to: to.value,
        order_date: props.order_date,
    }), {
        preserveState: false,
        preserveScroll: true,
        onSuccess: () => {
            isLoading.value = false;
            closeImportModal();
            
            // Explicitly check flash prop from the updated page object
            const flash = usePage().props.flash;
            
            if (flash && flash.created_count) {
                createdCount.value = flash.created_count;
            } else {
                createdCount.value = 0;
            }

            if (flash && flash.skippedItems && flash.skippedItems.length > 0) {
                skippedItems.value = flash.skippedItems;
                
                if (skippedItems.value.length <= 15) {
                    persistentSkippedItemsMessage.value = formatSkippedItemsMessage(skippedItems.value);
                } else {
                    persistentSkippedItemsMessage.value = '';
                }
                
                toast.add({
                    severity: "warn",
                    summary: "Import Completed with Warnings",
                    detail: `${skippedItems.value.length} items were skipped. ${createdCount.value} records inserted.`, 
                    life: 5000,
                });
            } else if (flash && flash.success) {
                persistentSkippedItemsMessage.value = '';
                skippedItems.value = [];
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: flash.success,
                    life: 3000,
                });
            }
        },
        onError: () => {
            isLoading.value = false;
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occurred during import.",
                life: 3000,
            });
        },
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

const formatSkippedItemsMessage = (items) => {
    if (!items || items.length === 0) return '';
    
    let message = 'The following transactions/items were skipped during import:\n\n';
    items.forEach(item => {
        const identifier = item.item_code ? `Item: ${item.item_code}` : `Row: ${item.row_number}`;
        message += `- ${identifier} - Reason: ${item.reason}\n`;
    });
    return message;
};

const downloadSkippedItems = () => {
    if (skippedItems.value.length === 0) return;
    
    // Redirect to server-side export route to get a proper .xlsx file with styling
    window.location.href = route('store-transactions.export-skipped');
};

// Watch for flash messages (needed because onMounted doesn't run on Inertia partial reloads/visits to same page)
watch(() => usePage().props.flash, (flash) => {
    if (flash && flash.created_count) {
        createdCount.value = flash.created_count;
    }
    if (flash && flash.skippedItems && flash.skippedItems.length > 0) {
        skippedItems.value = flash.skippedItems;
        // Only show detailed text message if 15 or fewer items
        if (skippedItems.value.length <= 15) {
            persistentSkippedItemsMessage.value = formatSkippedItemsMessage(skippedItems.value);
        } else {
            persistentSkippedItemsMessage.value = '';
        }
    }
}, { deep: true });

onMounted(() => {
    console.log('StoreTransaction/Index.vue onMounted:');
    // ... existing logs ...

    const flash = usePage().props.flash;
    if (flash && flash.created_count) {
        createdCount.value = flash.created_count;
    }
    if (flash && flash.skippedItems && flash.skippedItems.length > 0) {
        skippedItems.value = flash.skippedItems;
        // Only show detailed text message if 15 or fewer items
        if (skippedItems.value.length <= 15) {
            persistentSkippedItemsMessage.value = formatSkippedItemsMessage(skippedItems.value);
        } else {
            persistentSkippedItemsMessage.value = '';
        }
    }
});

</script>
<template>
    <Layout
        heading="Store Transactions"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <!-- Success Message with Created Count -->
        <div v-if="createdCount > 0" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <strong class="font-bold">Import Success:</strong>
            <span class="block sm:inline"> {{ createdCount }} records were successfully inserted.</span>
            <span class="absolute top-0 bottom-0 right-0 px-4 py-3 cursor-pointer" @click="createdCount = 0">
                <svg class="fill-current h-6 w-6 text-green-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
            </span>
        </div>

        <!-- Persistent Skipped Items Message (only shown for 15 or fewer items) -->
        <div v-if="persistentSkippedItemsMessage && skippedItems.length <= 15" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <strong class="font-bold">Import Warnings:</strong>
            <span class="block sm:inline whitespace-pre-line">{{ persistentSkippedItemsMessage }}</span>
            
            <span class="absolute top-0 bottom-0 right-0 px-4 py-3 cursor-pointer" @click="persistentSkippedItemsMessage = ''">
                <svg class="fill-current h-6 w-6 text-red-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
            </span>
        </div>

        <!-- Download button (always shown when there are skipped items) -->
        <div v-if="skippedItems.length > 0" class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded mb-4" role="alert">
            <strong class="font-bold">Import Summary:</strong>
            <span class="block sm:inline"> {{ skippedItems.length }} items were skipped during import.</span>
            
            <button 
                @click="downloadSkippedItems"
                class="mt-2 bg-blue-500 hover:bg-blue-700 text-white font-bold py-1 px-2 rounded text-sm"
            >
                Download Skipped Items Report
            </button>

            <p class="text-sm mt-2">
                Common reasons for skipped items:
                <ul class="list-disc list-inside text-sm ml-4">
                    <li>Insufficient SOH balance for ingredients (Variance > SOH)</li>
                    <li>Missing POS to SAP Masterfile mapping</li>
                    <li>Transaction for this receipt already exists</li>
                    <li>Invalid data format</li>
                </ul>
            </p>
        </div>

        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search by Receipt No."
                        v-model="search"
                    />
                </SearchBar>

                <DivFlexCenter class="gap-2">
                    <Button @click="openImportModal">Import</Button>
                    <Button variant="outline" @click="openFilterDialog">
                        <Filter />
                    </Button>
                </DivFlexCenter>

                <!-- Import Modal -->
                <div
                    v-if="isImportModalVisible"
                    class="fixed inset-0 z-50 flex items-center justify-center"
                    @click="(e) => { if(e.target === e.currentTarget) closeImportModal() }"
                >
                    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>
                    <div class="relative z-10 w-full max-w-md mx-4 bg-white rounded-lg shadow-xl border border-gray-200 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">Import Transactions</h2>
                                <p class="text-sm text-gray-600 mt-1">Import the excel file of the transactions.</p>
                            </div>
                            <Button variant="ghost" size="sm" @click="closeImportModal" class="h-8 w-8 p-0">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </Button>
                        </div>
                        <div class="space-y-5">
                            <div class="flex flex-col space-y-1">
                                <Input
                                    type="file"
                                    accept=".xlsx,.xls,.csv"
                                    @input="(e) => importForm.store_transactions_file = e.target.files[0]"
                                />
                                <div v-if="importForm.errors.store_transactions_file" class="text-sm text-red-600">
                                    {{ importForm.errors.store_transactions_file }}
                                </div>
                            </div>
                            <div class="flex flex-col space-y-1">
                                <label class="text-xs text-gray-600">Accepted File Format</label>
                                <ul>
                                    <li class="text-xs">
                                        <a
                                            class="text-blue-500 underline"
                                            :href="route('excel.store-transactions-template')"
                                        >Click to download template</a>
                                    </li>
                                </ul>
                            </div>
                            <div class="flex justify-end mt-6">
                                <Button @click="importFile" :disabled="isLoading" class="gap-2">
                                    {{ isLoading ? 'Importing...' : 'Proceed' }}
                                    <span v-if="isLoading"><svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></span>
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Custom Modal Dialog -->
                <div
                    v-if="isFilterDialogOpen"
                    class="fixed inset-0 z-50 flex items-center justify-center"
                    @click="handleBackdropClick"
                >
                    <!-- Backdrop -->
                    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

                    <!-- Modal Content -->
                    <div class="relative z-10 w-full max-w-md mx-4 bg-white rounded-lg shadow-xl border border-gray-200 p-6 transform transition-all">
                        <!-- Header -->
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">Filter Transactions</h2>
                                <p class="text-sm text-gray-600 mt-1">Set date range to filter store transactions.</p>
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="closeFilterDialog"
                                class="h-8 w-8 p-0 hover:bg-gray-100"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </Button>
                        </div>

                        <!-- Form Content -->
                        <div class="space-y-4">
                            <!-- Reset Filter Button -->
                            <div class="flex justify-end">
                                <Button
                                    @click="resetFilter"
                                    variant="link"
                                    class="text-red-500 text-xs hover:text-red-600 p-0 h-auto"
                                >
                                    Reset Filter
                                </Button>
                            </div>

                            <!-- Date Fields -->
                            <div class="space-y-4">
                                <div class="space-y-2">
                                    <label class="text-sm font-medium text-gray-900">From</label>
                                    <Input
                                        type="date"
                                        v-model="from"
                                        class="w-full"
                                    />
                                </div>

                                <div class="space-y-2">
                                    <label class="text-sm font-medium text-gray-900">To</label>
                                    <Input
                                        type="date"
                                        v-model="to"
                                        class="w-full"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Branch Code</TH>
                    <TH>Store Branch</TH>
                    <TH>Receipt No.</TH>
                    <TH>Item Count</TH>
                    <TH>Overall Net Total</TH>
                    <TH>POS Sales Date</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="transaction in props.transactions.data" :key="transaction.id">
                        <TD>{{ transaction.id }}</TD>
                        <TD>{{ transaction.branch_code }}</TD>
                        <TD>{{ transaction.store_branch }}</TD>
                        <TD>{{ transaction.receipt_number }}</TD>
                        <TD>{{ transaction.item_count }}</TD>
                        <TD>{{ transaction.net_total }}</TD>
                        <TD>{{ formatDisplayDate(transaction.order_date) }}</TD>
                        <TD class="flex items-center">
                            <ShowButton
                                :isLink="true"
                                :href="
                                    route(
                                        'store-transactions.show',
                                        transaction.id
                                    )
                                "
                            />
                            <EditButton
                                :isLink="true"
                                :href="
                                    route(
                                        'store-transactions.edit',
                                        transaction.id
                                    )
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="props.transactions" />
        </TableContainer>
        <BackButton />
    </Layout>
</template>