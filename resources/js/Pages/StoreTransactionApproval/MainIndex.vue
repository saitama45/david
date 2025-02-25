<script setup>
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { useSelectOptions } from "@/Composables/useSelectOptions";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
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
const { options: branchesOptions } = useSelectOptions(branches);

const createNewTransaction = () => {
    router.get(route("store-transactions.create"));
};

let search = ref(usePage().props.filters.search);

let from = ref(usePage().props.from ?? null);

let to = ref(usePage().props.to ?? null);

const branchId = ref(
    usePage().props.filters.branchId || branchesOptions.value[0].value
);

watch(from, (value) => {
    router.get(
        route("store-transactions-approval.main-index"),
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
        route("store-transactions-approval.main-index"),
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
        route("store-transactions-approval.main-index"),
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

// watch(
//     search,
//     throttle(function (value) {
//         router.get(
//             route("store-transactions.index"),
//             {
//                 search: value,
//                 from: from.value,
//                 to: to.value,
//                 branchId: branchId.value,
//             },
//             {
//                 preserveState: true,
//                 replace: true,
//             }
//         );
//     }, 500)
// );

const resetFilter = () => {
    (from.value = null),
        (to.value = null),
        (branchId.value = branchesOptions.value[0].value);
    // (search.value = null)
};

const exportRoute = computed(() =>
    route("store-transactions.export-main-index", {
        branchId: branchId.value,
        from: from.value,
        to: to.value,
    })
);

import { useForm } from "@inertiajs/vue3";
const excelFileForm = useForm({
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
    }
});

const importTransactions = () => {
    isLoading.value = true;
    excelFileForm.post(route("store-transactions.import"), {
        onSuccess: () => {
            isLoading.value = false;
            isImportStoreTransactionModalOpen.value = false;
            toast.add({
                severity: "success",
                summary: "Success",
                detail: "Store transactions created successfully",
                life: 3000,
            });
        },
        onError: (e) => {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "An error occured while trying to import store transactions.",
                life: 3000,
            });
            isLoading.value = false;
        },
    });
};
</script>
<template>
    <Layout
        heading="Store Transactions Approval"
        :hasButton="true"
        buttonName="Import Store Transactions"
        :handleClick="openImportStoreTransactionModal"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <TableContainer>
            <TableHeader>
                <!-- <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search..."
                        v-model="search"
                    />
                </SearchBar> -->
                <InputContainer></InputContainer>

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
                            <label class="text-xs">Store</label>
                            <Select v-model="branchId">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a store" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectLabel>Stores</SelectLabel>
                                        <SelectItem
                                            v-for="(value, key) in branches"
                                            :key="key"
                                            :value="key"
                                        >
                                            {{ value }}
                                        </SelectItem>
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
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
                    <tr v-for="transaction in transactions.data">
                        <TD>{{ transaction.order_date }}</TD>
                        <TD>{{ transaction.transaction_count }}</TD>
                        <TD>{{ transaction.net_total }}</TD>
                        <TD class="flex items-center">
                            <ShowButton
                                :isLink="true"
                                :href="
                                    route('store-transactions-approval.index', {
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
                            excelFileForm.store_transactions_file =
                                $event.target.files[0]
                        "
                    />
                    <FormError>{{
                        excelFileForm.errors.store_transactions_file
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
                        :disabled="isLoading"
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
