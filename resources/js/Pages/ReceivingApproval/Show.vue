<script setup>
import { useBackButton } from "@/Composables/useBackButton";
const { backButton } = useBackButton(route("receiving-approvals.index"));
const props = defineProps({
    order: {
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
                    <TH>Order Date</TH>
                    <TH>Quantity Ordered</TH>
                    <TH>Quantity Received</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="item in order.store_order_items" :key="item.id">
                        <TD>{{ item.product_inventory.name }}</TD>
                        <TD>{{ item.product_inventory.inventory_code }}</TD>
                        <TD>{{ order.order_date }}</TD>
                        <TD>{{ item.quantity_ordered }}</TD>
                        <TD>{{ item.quantity_received }}</TD>
                        <TD>
                            <Button variant="link" class="text-green-500 p-0">
                                Approve
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
        </TableContainer>
        <Button variant="outline" class="text-lg px-7" @click="backButton">
            Back
        </Button>
    </Layout>
</template>
