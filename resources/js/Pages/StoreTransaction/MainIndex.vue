<script setup>
import { router } from "@inertiajs/vue3";
import { usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
const { transactions } = defineProps({
    transactions: {
        type: Object,
        required: true,
    },
    branches: {
        type: Object,
        required: true,
    },
});
const createNewTransaction = () => {
    router.get(route("store-transactions.create"));
};

let search = ref(usePage().props.filters.search);

let from = ref(usePage().props.from ?? null);

let to = ref(usePage().props.to ?? null);

let branchId = ref(usePage().props.filters.branchId);

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

// watch(branchId, (value) => {
//     router.get(
//         route("store-transactions.index"),
//         {
//             search: search.value,
//             from: from.value,
//             to: to.value,
//             branchId: value,
//         },
//         {
//             preserveState: true,
//             preserveScroll: true,
//         }
//     );
// });

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
    (from.value = null), (to.value = null);
    // (branchId.value = null),
    // (search.value = null)
};

const exportRoute = computed(() =>
    route("store-transactions.export", {
        search: search.value,
        branchId: branchId.value,
        from: from.value,
        to: to.value,
    })
);
</script>
<template>
    <Layout
        heading="Store Transactions"
        :hasButton="true"
        buttonName="Create New Transaction"
        :handleClick="createNewTransaction"
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
                            <!-- <Select v-model="branchId">
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
                            </Select> -->
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
                                    route(
                                        'store-transactions.index',
                                        transaction.order_date
                                    )
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="transactions" />
        </TableContainer>
    </Layout>
</template>
