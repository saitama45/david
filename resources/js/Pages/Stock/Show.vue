<script setup>
const props = defineProps({
    data: {
        type: Object,
    },
});
const product = props.data.data[0].product;
const heading = `${product.name} (${product.inventory_code}) Stock Per Store`;
</script>
<template>
    <Layout :heading="heading">
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Branch</TH>
                    <TH>Branch Code</TH>
                    <TH>Stock</TH>
                    <TH>Recently Added</TH>
                    <TH>Used</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in data.data">
                        <TD>{{ item.store_branch.name }}</TD>
                        <TD>{{ item.store_branch.branch_code }}</TD>
                        <TD>{{ item.quantity }}</TD>
                        <TD>{{ item.recently_added }}</TD>
                        <TD>{{ item.used }}</TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="data" />
        </TableContainer>
    </Layout>
</template>
