<script setup>
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { ref, watch, computed, onMounted } from 'vue';

import { useToast } from "@/Composables/useToast";
const { toast } = useToast();
import { useConfirm } from "primevue/useconfirm";
const confirm = useConfirm();
const { transactions, branches } = defineProps({
    transactions: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
});
import { useSelectOptions } from "@/Composables/useSelectOptions";
const { options: branchesOptions } = useSelectOptions(branches);

const createNewTransaction = () => {
    router.get(route("store-transactions.create"));
};

let search = ref(usePage().props.filters.search);

let from = ref(usePage().props.filters.from ?? null);
let to = ref(usePage().props.filters.to ?? null);

const branchId = ref(
    usePage().props.filters.branchId || (branchesOptions.value.length > 0 ? branchesOptions.value[0].value : null)
);

watch(from, (value) => {
    router.get(
        route("store-transactions.main-index"),
        {
            search: search.value,
            from: value,
            to: to.value,
            branchId: branchId.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

watch(to, (value) => {
    router.get(
        route("store-transactions.main-index"),
        {
            search: search.value,
            from: from.value,
            to: value,
            branchId: branchId.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

watch(branchId, (value) => {
    router.get(
        route("store-transactions.main-index"),
        {
            search: search.value,
            from: from.value,
            to: to.value,
            branchId: value,
        },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

const resetFilter = () => {
    from.value = null;
    to.value = null;
    branchId.value = branchesOptions.value.length > 0 ? branchesOptions.value[0].value : null;
    router.get(
        route("store-transactions.main-index"),
        {
            search: null,
            from: null,
            to: null,
            branchId: branchId.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    );
};


const exportRoute = computed(() =>
    route("store-transactions.export-main-index", {
        branchId: branchId.value,
        from: from.value,
        to: to.value,
    })
);

import { useForm } from "@inertiajs/vue3";
const importForm = useForm({ // Renamed from excelFileForm to importForm for consistency
    store_transactions_file: null,
});
const isLoading = ref(false);
const isImportStoreTransactionModalOpen = ref(false);
const openImportStoreTransactionModal = () => {
    isImportStoreTransactionModalOpen.value = true;
};

watch(isImportStoreTransactionModalOpen, (value) => {
    if (!value) {
        isLoading.value = false;
        importForm.reset(); // Reset form on modal close
    }
});
const isErrorDialogVisible = ref(false);
const errorMessage = ref("");
// Removed axios import as we're switching to useForm().post

// New reactive variables for skipped rows display
const skippedImportRows = ref([]);
const persistentSkippedItemsMessage = ref(''); // New ref for persistent message

const formatSkippedRowsMessage = (rows) => {
    if (!rows || rows.length === 0) {
        return '';
    }

    const groupedMessages = {};

    rows.forEach(row => {
        const productId = row.data && row.data.product_id ? row.data.product_id : 'N/A';
        const reason = row.reason || 'Unknown reason';
        const key = `${productId}-${reason}`; // Unique key for grouping

        if (!groupedMessages[key]) {
            groupedMessages[key] = {
                productId: productId,
                reason: reason,
                count: 0,
                rowNumbers: []
            };
        }
        groupedMessages[key].count++;
        groupedMessages[key].rowNumbers.push(row.row_number);
    });

    let message = 'The following import warnings occurred:\n\n';
    Object.values(groupedMessages).forEach(group => {
        const productInfo = group.productId !== 'N/A' ? ` (Product ID: ${group.productId})` : '';
        const rowNumbers = group.rowNumbers.length > 5 // Limit row numbers displayed
            ? `${group.rowNumbers.slice(0, 5).join(', ')}... (and ${group.rowNumbers.length - 5} more)`
            : group.rowNumbers.join(', ');

        message += `- ${group.reason}${productInfo}. Occurrences: ${group.count} (Rows: ${rowNumbers})\n`;
    });
    return message;
};

const importTransactions = () => {
    isLoading.value = true;
    importForm.post(route("store-transactions.import"), {
        onSuccess: () => {
            isLoading.value = false;
            isImportStoreTransactionModalOpen.value = false;

            const flash = usePage().props.flash;
            console.log('onSuccess: flash object from Inertia:', flash); // Debugging log

            if (flash.skipped_import_rows && flash.skipped_import_rows.length > 0) {
                skippedImportRows.value = flash.skipped_import_rows;
                persistentSkippedItemsMessage.value = formatSkippedRowsMessage(skippedImportRows.value);
                toast.add({
                    severity: "warn",
                    summary: "Import Completed with Warnings",
                    detail: flash.warning || `Some transactions were skipped during import. See message below for details.`,
                    life: 5000,
                });
            } else if (flash.success) {
                persistentSkippedItemsMessage.value = ''; // Clear message if successful
                toast.add({
                    severity: "success",
                    summary: "Success",
                    detail: flash.success,
                    life: 3000,
                });
            }
            // Clear flash messages after processing to prevent reappearance
            flash.skipped_import_rows = null;
            flash.warning = null;
            flash.success = null;
            flash.error = null;

            router.reload({ preserveState: true }); // Reload to update table data if any records were inserted
        },
        onError: (errors) => {
            isLoading.value = false;
            isImportStoreTransactionModalOpen.value = false;
            const flash = usePage().props.flash;

            // Check for general error message from backend
            if (flash.error) {
                errorMessage.value = flash.error;
            } else if (errors.store_transactions_file) {
                // Specific validation error for the file
                errorMessage.value = errors.store_transactions_file[0];
            } else {
                errorMessage.value = "An unknown error occurred during import.";
            }
            isErrorDialogVisible.value = true;

            // Clear error flash message after displaying
            flash.error = null;
        },
        onFinish: () => {
            isLoading.value = false;
        },
    });
};

import { TriangleAlert, X } from "lucide-vue-next";

// onMounted hook to handle initial page load flash messages
onMounted(() => {
    const flash = usePage().props.flash;
    console.log('onMounted: Full flash object from Inertia:', flash); // Debugging log

    if (flash.skipped_import_rows && flash.skipped_import_rows.length > 0) {
        skippedImportRows.value = flash.skipped_import_rows;
        persistentSkippedItemsMessage.value = formatSkippedRowsMessage(skippedImportRows.value);
        
        if (flash.warning) {
            toast.add({
                severity: "warn",
                summary: "Import Warning",
                detail: flash.warning,
                life: 5000,
            });
        }
    } else if (flash.success) {
        toast.add({
            severity: "success",
            summary: "Success",
            detail: flash.success,
            life: 3000,
        });
    } else if (flash.error) {
        // If there's a general error flash, display it as a toast
        toast.add({
            severity: "error",
            summary: "Import Error",
            detail: flash.error,
            life: 5000,
        });
    }

    // Clear flash messages after they've been processed to prevent them from reappearing
    flash.skipped_import_rows = null;
    flash.warning = null;
    flash.success = null;
    flash.error = null;
});

const closeSkippedRowsCard = () => {
    persistentSkippedItemsMessage.value = ''; // Clear the message
    skippedImportRows.value = []; // Clear the array
    // No need to clear flash on page.props.flash directly here, as onMounted already handles it.
};

</script>
<template>
    <Layout
        heading="Store Transactions"
        :hasButton="true"
        buttonName="Import Store Transactions"
        :handleClick="openImportStoreTransactionModal"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <!-- Skipped Rows Card (Persistent Message) -->
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-1"
        >
            <div v-if="persistentSkippedItemsMessage"
                class="relative mx-auto w-full max-w-4xl bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-lg shadow-lg mb-4 mt-4"
                role="alert">
                <div class="flex items-center justify-between mb-2">
                    <strong class="font-bold flex items-center">
                        <TriangleAlert class="size-5 mr-2" />
                        Import Warnings!
                    </strong>
                    <button @click="closeSkippedRowsCard" class="text-yellow-700 hover:text-yellow-900 focus:outline-none">
                        <X class="size-4" />
                    </button>
                </div>
                <div class="max-h-40 overflow-y-auto pr-2"> <!-- Added pr-2 for scrollbar spacing -->
                    <p class="text-sm whitespace-pre-line">{{ persistentSkippedItemsMessage }}</p>
                </div>
            </div>
        </Transition>

        <!-- Error Dialog (for critical errors like file validation) -->
        <Dialog v-model:open="isErrorDialogVisible">
            <DialogContent
                class="sm:max-w-[400px] flex items-center justify-center flex-col"
            >
                <DialogHeader>
                    <DialogTitle>
                        <TriangleAlert class="size-12 text-red-500" />
                    </DialogTitle>
                    <DialogDescription></DialogDescription>
                </DialogHeader>
                <div class="flex items-center justify-center flex-col">
                    <h1 class="text-2xl font-bold">Error</h1>
                    <p class="text-center">
                        {{ errorMessage }}
                    </p>
                </div>
            </DialogContent>
        </Dialog>
        <TableContainer>
            <TableHeader>
                <Select
                    filter
                    placeholder="Select a Branch"
                    v-model="branchId"
                    :options="branchesOptions"
                    optionLabel="label"
                    optionValue="value"
                >
                </Select>

                <DivFlexCenter class="gap-5">
                    <Popover>
                        <PopoverTrigger> <Filter /> </PopoverTrigger>
                        <PopoverContent>
                            <div class="flex justify-end">
                                <Button
                                    @click="resetFilter"
                                    variant="link"
                                    class="text-end text-red-500 text-xs"
                                >
                                    Reset Filter
                                </Button>
                            </div>
                            <label class="text-xs">From</label>
                            <Input type="date" v-model="from" />
                            <label class="text-xs">To</label>
                            <Input type="date" v-model="to" />
                        </PopoverContent>
                    </Popover>
                </DivFlexCenter>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Order Date</TH>
                    <TH>Transactions Count</TH>
                    <TH>Overall Net Total</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="transaction in transactions.data" :key="transaction.order_date">
                        <TD>{{ transaction.order_date }}</TD>
                        <TD>{{ transaction.transaction_count }}</TD>
                        <TD>{{ transaction.net_total }}</TD>
                        <TD class="flex items-center">
                            <ShowButton
                                :isLink="true"
                                :href="
                                    route('store-transactions.index', {
                                        order_date: transaction.order_date,
                                        branchId: branchId,
                                    })
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="transactions" />
        </TableContainer>

        <Dialog v-model:open="isImportStoreTransactionModalOpen">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Import Store Transactions List</DialogTitle>
                    <DialogDescription>
                        Import the excel file here.
                    </DialogDescription>
                </DialogHeader>

                <InputContainer>
                    <LabelXS> Store Transactions List </LabelXS>
                    <Input
                        :disabled="isLoading"
                        type="file"
                        @input="
                            importForm.store_transactions_file =
                                $event.target.files[0]
                        "
                    />
                    <FormError>{{
                        importForm.errors.store_transactions_file
                    }}</FormError>
                </InputContainer>

                <InputContainer>
                    <Label class="text-xs">Store Transaction Template</Label>
                    <ul>
                        <li class="text-xs">
                            Template:
                            <a
                                class="text-blue-500 underline"
                                href="/excel/store-transactions-template"
                                >Click to download</a
                            >
                        </li>
                    </ul>
                </InputContainer>
                <DialogFooter>
                    <Button
                        :disabled="isLoading || !importForm.store_transactions_file"
                        @click="importTransactions"
                        class="gap-2"
                    >
                        Proceed
                        <span v-if="isLoading"><Loading /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
