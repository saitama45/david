<script setup>
import { useBackButton } from "@/Composables/useBackButton";
const { backButton } = useBackButton(route("approved-orders.index"));

const props = defineProps({
    order: {
        type: Object,
        required: true,
    },
    items: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Layout :heading="`Order Number ${order.order_number}`">
        <TableContainer>
            <Table>
                <TableHead>
                    <TH>Item</TH>
                    <TH>Inventory Code</TH>
                    <TH>Received Date</TH>
                    <TH>Quantity Received</TH>
                    <TH>Is Approved?</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in items.data" :key="item.id">
                        <TD>{{
                            item.store_order_item.product_inventory.name
                        }}</TD>
                        <TD>{{
                            item.store_order_item.product_inventory
                                .inventory_code
                        }}</TD>
                        <TD>{{ item.received_date }}</TD>
                        <TD>{{ item.quantity_received }}</TD>
                        <TD>{{ item.is_approved == 1 ? "Yes" : "No" }}</TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="items" />
        </TableContainer>
        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>
