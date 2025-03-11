<script setup>
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { useSelectOptions } from "@/Composables/useSelectOptions";
const { options: branchesOptions } = useSelectOptions(branches);
const { transactions, order_date, branches } = defineProps({
    transactions: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
    order_date: {
        type: String,
        required: false,
    },
});
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
        route("store-transactions.index"),
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
        route("store-transactions.index"),
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
        route("store-transactions.index"),
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

watch(
    search,
    throttle(function (value) {
        router.get(
            route("store-transactions.index"),
            {
                search: value,
                from: from.value,
                to: to.value,
                branchId: branchId.value,
            },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

const resetFilter = () => {
    (from.value = null),
        (to.value = null),
        (branchId.value = null),
        (search.value = null);
};

const exportRoute = computed(() =>
    route("store-transactions.export", {
        search: search.value,
        branchId: branchId.value,
        from: from.value,
        to: to.value,
        order_date: order_date,
    })
);
</script>
<template>
    <Layout
        heading="Store Transactions"
        :hasExcelDownload="true"
        :exportRoute="exportRoute"
    >
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search..."
                        v-model="search"
                    />
                </SearchBar>

                <DivFlexCenter class="gap-5">
                    <Select
                        filter
                        placeholder="Select a Supplier"
                        v-model="branchId"
                        :options="branchesOptions"
                        optionLabel="label"
                        optionValue="value"
                    >
                    </Select>
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
                    <TH>Id</TH>
                    <TH>Store Branch</TH>
                    <TH>Receipt No.</TH>
                    <TH>Item Count</TH>
                    <TH>Overall Net Total</TH>
                    <TH>Date</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="transaction in transactions.data">
                        <TD>{{ transaction.id }}</TD>
                        <TD>{{ transaction.store_branch }}</TD>
                        <TD>{{ transaction.receipt_number }}</TD>
                        <TD>{{ transaction.item_count }}</TD>
                        <TD>{{ transaction.net_total }}</TD>
                        <TD>{{ transaction.order_date }}</TD>
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
            <Pagination :data="transactions" />
        </TableContainer>
        <BackButton />
    </Layout>
</template>
