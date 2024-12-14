<script setup>
const props = defineProps({
    orders: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Layout heading="Received Orders For Approval List">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        class="pl-10"
                        placeholder="Search..."
                    />
                </SearchBar>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Supplier</TH>
                    <TH>Store Branch</TH>
                    <TH>Order Number</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders.data">
                        <TD>{{ order.id }}</TD>
                        <TD>{{ order.supplier.name }}</TD>
                        <TD>{{ order.store_branch.name }}</TD>
                        <TD>{{ order.order_number }}</TD>
                        <TD>
                            <ShowButton
                                :isLink="true"
                                :href="`/receiving-approvals/show/${order.order_number}`"
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>
