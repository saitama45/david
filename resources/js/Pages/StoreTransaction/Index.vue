<script setup>
import { router } from "@inertiajs/vue3";

const { transactions } = defineProps({
    transactions: {
        type: Object,
        required: true,
    },
});
const createNewTransaction = () => {
    router.get(route("store-transactions.create"));
};
</script>
<template>
    <Layout
        heading="Store Transactions"
        :hasButton="true"
        buttonName="Create New Transaction"
        :handleClick="createNewTransaction"
    >
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input class="pl-10" placeholder="Search..." />
                </SearchBar>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Store Branch</TH>
                    <TH>Receipt No.</TH>
                    <TH>TM#</TH>
                    <TH>Posted</TH>
                    <TH>Date</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="transaction in transactions.data">
                        <TD>{{ transaction.id }}</TD>
                        <TD>{{ transaction.store_branch.name }}</TD>
                        <TD>{{ transaction.receipt_number }}</TD>
                        <TD>{{ transaction.tim_number }}</TD>
                        <TD>{{ transaction.posted }}</TD>
                        <TD>{{ transaction.order_date }}</TD>
                        <TD class="flex items-center">
                            <ShowButton />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="transactions" />
        </TableContainer>
    </Layout>
</template>
