<script setup>
import { router } from "@inertiajs/vue3";

import { useSearch } from "@/Composables/useSearch";
const { search } = useSearch("product-orders-summary.index");

const props = defineProps({
    items: {
        type: Object,
        required: true,
    },
});

const showProductOrdersDetails = (id) => {
    router.get(`/product-orders-summary/show/${id}`);
};
</script>

<template>
    <Layout heading="Item Orders Summary">
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
                    <TH>Id</TH>
                    <TH>Item</TH>
                    <TH>Inventory Code</TH>
                    <TH>Conversion</TH>
                    <TH>UOM</TH>
                    <TH>Quantity Ordered</TH>
                    <TH>Quantity Delivered</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items.data">
                        <TD>{{ item.id }}</TD>
                        <TD>{{ item.name }}</TD>
                        <TD>{{ item.inventory_code }}</TD>
                        <TD>{{ item.conversion }}</TD>
                        <TD>{{ item.unit_of_measurement.name }}</TD>
                        <TD>{{
                            item.store_order_items_sum_quantity_ordered
                        }}</TD>
                        <TD>{{
                            item.store_order_items_sum_quantity_received
                        }}</TD>
                        <TD>
                            <button @click="showProductOrdersDetails(item.id)">
                                <Eye class="size-5" />
                            </button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="items" />
        </TableContainer>
    </Layout>
</template>
