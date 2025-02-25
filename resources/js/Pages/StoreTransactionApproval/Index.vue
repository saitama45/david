<script setup>
import { throttle } from "lodash";
import { router } from "@inertiajs/vue3";
const { transactions, filters } = defineProps({
    transactions: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        required: true,
    },
});
let search = ref(filters.search);

watch(
    search,
    throttle(function (value) {
        router.get(
            route("store-transactions-approval.index"),
            {
                search: value,
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
    <Layout heading="Store Transactions Approval">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search..."
                        v-model="search"
                    />
                </SearchBar>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>ID</TH>
                    <TH>Receipt Number</TH>
                    <TH>Order Date</TH>
                    <TH>Ordered Items Count</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="transaction in transactions.data">
                        <TD>{{ transaction.id }}</TD>
                        <TD>{{ transaction.receipt_number }}</TD>
                        <TD>{{ transaction.order_date }}</TD>
                        <TD>{{ transaction.ordered_item_count }}</TD>
                        <TD>
                            <ShowButton
                                :isLink="true"
                                :href="
                                    route(
                                        'store-transactions-approval.show',
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
    </Layout>
</template>
