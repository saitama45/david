<script setup>
const { products } = defineProps({
    products: {
        type: Object,
        required: true,
    },
});
</script>
<template>
    <Layout heading="Stock Management">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input class="pl-10" placeholder="Search..." />
                </SearchBar>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Name</TH>
                    <TH>Inventory Code</TH>
                    <TH>Stock On Hand</TH>
                    <TH>Estimated Used</TH>
                    <TH>Actual Used</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="product in products.data">
                        <TD>{{ product.name }}</TD>
                        <TD>{{ product.inventory_code }}</TD>
                        <TD>{{ product.stock_on_hand }}</TD>
                        <TD> TBD </TD>
                        <TD>{{ product.recorded_used }}</TD>
                        <TD>
                            <ShowButton />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="products" />
        </TableContainer>
    </Layout>
</template>
