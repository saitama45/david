<script setup>
defineProps({
    items: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Layout heading="Product Sales Data">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input class="pl-10" placeholder="Search..." />
                </SearchBar>
            </TableHeader>
            <Table>
                <TableHead>
                    <TD>Product</TD>
                    <TD>Inventory Code</TD>
                    <TD>Cost</TD>
                    <TD>Total Sold</TD>
                    <TD>Total Sale</TD>
                    <TD>Action</TD>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.name }}</TD>
                        <TD>{{ item.inventory_code }}</TD>
                        <TD>{{ item.cost }}</TD>
                        <TD>{{ item.total_sold ?? 0 }}</TD>
                        <TD>{{
                            parseFloat(item.cost * item.total_sold).toFixed(2)
                        }}</TD>
                        <TD>
                            <ShowButton
                                :isLink="true"
                                :href="`/product-sales/show/${item.id}`"
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="items" />
        </TableContainer>
    </Layout>
</template>
