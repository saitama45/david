<script setup>
defineProps({
    product: {
        type: Object,
        required: true,
    },
    branchSales: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Layout :heading="`${product.name} (${product.inventory_code})`">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input class="pl-10" placeholder="Search..." />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Branch Id</TH>
                    <TH>Name</TH>
                    <TH>Brach Code</TH>
                    <TH>Quantity Received</TH>
                    <TH>Total Sale</TH>
                    <TH>Actions</TH>
                </TableHead>

                <TableBody>
                    <tr v-for="branch in branchSales.data">
                        <TD>{{ branch.id }}</TD>
                        <TD>{{ branch.name }}</TD>
                        <TD>{{ branch.branch_code }}</TD>
                        <TD>{{ branch.total_quantity }}</TD>
                        <TD>{{
                            parseFloat(
                                branch.total_quantity * product.cost
                            ).toFixed(2)
                        }}</TD>
                        <TD><ShowButton /></TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="branchSales" />
        </TableContainer>
    </Layout>
</template>
