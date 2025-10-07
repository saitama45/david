<script setup>
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { ref, watch, computed, onMounted } from 'vue';

const { transactions, order_date, branches } = defineProps({
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


watch(from, (value) => {
    router.get(
        route("store-transactions.index"),
        {
            search: search.value,
            from: value,
            to: to.value,
            branchId: branchId,
            order_date: order_date,
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
            order_date: order_date,
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
                order_date: order_date,
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
    from.value = order_date ?? new Date().toISOString().slice(0, 10);
    to.value = new Date().toISOString().slice(0, 10); // 'To' always resets to today
    search.value = null;
    router.get(
        route("store-transactions.index"),
        {
            search: search.value,
            from: from.value,
            to: to.value,
            branchId: branchId,
            order_date: order_date,
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
        order_date: order_date,
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

onMounted(() => {
    console.log('StoreTransaction/Index.vue onMounted:');
    console.log('  usePage().props.filters:', usePage().props.filters);
    console.log('  from.value (after init):', from.value);
    console.log('  to.value (after init):', to.value);
    console.log('  branchId (from props.filters):', branchId);
    console.log('  transactions prop:', transactions);
    console.log('  order_date prop:', order_date);
});

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
                        placeholder="Search by Receipt No."
                        v-model="search"
                    />
                </SearchBar>

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
                    <tr v-for="transaction in transactions.data" :key="transaction.id">
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
            <Pagination :data="transactions" />
        </TableContainer>
        <BackButton />
    </Layout>
</template>
