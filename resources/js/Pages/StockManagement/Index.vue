<script setup>
import { useSearch } from "@/Composables/useSearch";
const { products } = defineProps({
    products: {
        type: Object,
        required: true,
    },
});

const { search } = useSearch("stock-management.index");
</script>
<template>
    <Layout heading="Stock Management">
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
                    <TH>Name</TH>
                    <TH>Inventory Code</TH>
                    <TH>UOM</TH>
                    <TH>Stock On Hand</TH>
                    <TH>Sytem Estimated Used</TH>
                    <TH>Recorded Used</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="product in products.data">
                        <TD>{{ product.name }}</TD>
                        <TD>{{ product.inventory_code }}</TD>
                        <TD>{{ product.uom }}</TD>
                        <TD>{{ product.stock_on_hand }}</TD>
                        <TD>{{ product.estimated_used }}</TD>
                        <TD>{{ product.recorded_used }}</TD>
                        <TD>
                            <ShowButton
                                :isLink="true"
                                :href="
                                    route(
                                        'stock-management.show',
                                        product.inventory_code
                                    )
                                "
                            />
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="products" />
        </TableContainer>
    </Layout>
</template>
