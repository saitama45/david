<script setup>
const { transactions } = defineProps({
    transactions: {
        type: Object,
        required: true,
    },
});
</script>
<template>
    <Layout heading="Store Transactions Approval">
        <TableContainer>
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
                            <ShowButton />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="transactions" />
        </TableContainer>
    </Layout>
</template>
