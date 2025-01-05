<script setup>
import { useSearch } from "@/Composables/useSearch";

defineProps({
    items: {
        type: Object,
        required: true,
    },
});

const { search } = useSearch("product-sales.index");
</script>

<template>
    <Layout heading="Product Sales Data">
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
                    <TD>Product</TD>
                    <TD>Inventory Code</TD>
                    <TD>Cost</TD>
                    <TD>Delivered Quantity</TD>
                    <TD>Total Amount</TD>
                    <TD>Action</TD>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.name }}</TD>
                        <TD>{{ item.inventory_code }}</TD>
                        <TD>{{ item.cost }}</TD>
                        <TD>{{
                            item.store_order_items_sum_quantity_received ?? 0
                        }}</TD>
                        <TD>{{
                            parseFloat(
                                item.cost *
                                    (item.store_order_items_sum_quantity_received ??
                                        0)
                            ).toFixed(2)
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
