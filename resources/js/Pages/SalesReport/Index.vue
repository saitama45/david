<script setup>
import { useSelectOptions } from "@/Composables/useSelectOptions";
import { usePage } from "@inertiajs/vue3";
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
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
let search = ref(usePage().props.filters.search);
const branchId = ref(
    usePage().props.filters.branchId || branchesOptions.value[0].value
);

console.log(branchId.value);
watch(branchId, (value) => {
    router.get(
        route("sales-report.index"),
        {
            search: search.value,
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
            route("sales-report.index"),
            {
                search: value,
                branchId: branchId.value,
            },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);
</script>

<template>
    <Layout heading="Sales Report">
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
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="transactions" />
        </TableContainer>
        <BackButton />
    </Layout>
</template>
