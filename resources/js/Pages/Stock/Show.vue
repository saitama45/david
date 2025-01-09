<script setup>
import { useSearch } from "@/Composables/useSearch";

const props = defineProps({
    data: {
        type: Object,
    },
});

const { search } = useSearch(
    "stocks.show",
    props.data.data[0].product_inventory_id
);

const product = props.data.data[0].product;
const heading = `${product.name} (${product.inventory_code}) Stock Per Store`;
</script>
<template>
    <Layout :heading="heading">
        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input
                        v-model="search"
                        class="pl-10"
                        placeholder="Search..."
                    />
                </SearchBar>
            </TableHeader>
            <Table>
                <TableHead>
                    <TH>Branch</TH>
                    <TH>Branch Code</TH>
                    <TH>Stock</TH>
                    <TH>Recently Added</TH>
                    <TH>Used</TH>
                    <TH>Stock On Hand</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in data.data">
                        <TD>{{ item.store_branch.name }}</TD>
                        <TD>{{ item.store_branch.branch_code }}</TD>
                        <TD>{{ item.quantity }}</TD>
                        <TD>{{ item.recently_added }}</TD>
                        <TD>{{ item.used }}</TD>
                        <TD>{{ item.quantity - item.used }}</TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="data" />
        </TableContainer>
        <BackButton routeName="stocks.index" />
    </Layout>
</template>
